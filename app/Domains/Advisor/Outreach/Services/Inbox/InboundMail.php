<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Services\Inbox;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\Message;

/**
 * One incoming mail, parsed and sorted WITHOUT AI (PLAN-send.md §7):
 *
 *   bounce       delivery status notification (DSN); hard when status 5.x.x
 *   auto_reply   out-of-office and other machine replies (RFC 3834 headers, subjects)
 *   unsubscribe  a person asking to be removed — honoured immediately (CLAUDE.md rule 5)
 *   reply        everything else; Claude classifies it later via MCP
 *
 * The text is untrusted data. Nothing here interprets it beyond keyword checks.
 */
final class InboundMail
{
    public const BODY_LIMIT = 20000;

    private const UNSUBSCRIBE_PHRASES = [
        'unsubscribe', 'remove me', 'take me off', 'stop emailing', 'stop sending', 'do not contact',
        "don't contact", 'opt out', 'opt-out', 'not interested, please remove',
        'отписаться', 'отпишите', 'не пишите', 'удалите меня',          // ru
        'abonelikten çık', 'listeden çıkar', 'bana yazmayın',            // tr
        'abmelden', 'austragen', 'nicht mehr kontaktieren',              // de
    ];

    private const AUTO_SUBJECT_PREFIXES = [
        'automatic reply', 'auto reply', 'autoreply', 'auto:', 'out of office', 'out of the office',
        'abwesenheitsnotiz', 'автоответ', 'otomatik yanıt', 'réponse automatique',
    ];

    /**
     * @param  array<int, string>  $references  In-Reply-To + References ids, without angle brackets
     */
    public function __construct(
        public readonly ?string $messageId,
        public readonly array $references,
        public readonly ?string $fromEmail,
        public readonly ?string $subject,
        public readonly string $text,
        public readonly Carbon $receivedAt,
        public readonly string $kind,
        public readonly ?string $bounceRecipient = null,
        public readonly ?string $bounceStatus = null,
    ) {}

    public static function parse(string $raw): self
    {
        $m = Message::from($raw, true);

        $messageId = self::bareId($m->getHeaderValue('Message-ID'));
        // Raw values: the parsed ID headers drop the angle brackets we match on.
        $refs = self::ids((string) $m->getHeader('In-Reply-To')?->getRawValue().' '.(string) $m->getHeader('References')?->getRawValue());
        $from = $m->getHeader('From');
        $fromEmail = $from instanceof AddressHeader ? strtolower((string) $from->getEmail()) : null;
        $subject = $m->getHeaderValue('Subject');
        $text = Str::limit((string) ($m->getTextContent() ?? ''), self::BODY_LIMIT, '');
        $received = self::date($m->getHeaderValue('Date'));

        [$bounceRecipient, $bounceStatus, $originalId] = self::dsn($m);
        if ($bounceStatus !== null) {
            return new self($messageId, array_values(array_filter([$originalId, ...$refs])), $fromEmail, $subject, $text, $received, 'bounce', $bounceRecipient, $bounceStatus);
        }

        $kind = match (true) {
            self::isAutoReply($m, (string) $subject) => 'auto_reply',
            self::asksToUnsubscribe((string) $subject, $text) => 'unsubscribe',
            default => 'reply',
        };

        return new self($messageId, $refs, $fromEmail, $subject, $text, $received, $kind);
    }

    public function isHardBounce(): bool
    {
        return $this->kind === 'bounce' && str_starts_with((string) $this->bounceStatus, '5');
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string} failed recipient, status, original Message-ID */
    private static function dsn(Message $m): array
    {
        $isReport = str_contains(strtolower((string) $m->getHeaderValue('Content-Type')), 'report-type=delivery-status');
        $status = null;
        $recipient = null;
        $original = null;

        foreach ($m->getAllParts() as $part) {
            $type = strtolower($part->getContentType());

            if ($type === 'message/delivery-status') {
                $content = (string) $part->getContent();
                if (preg_match('/^Status:\s*([245]\.\d{1,3}\.\d{1,3})/mi', $content, $s)) {
                    $status = $s[1];
                }
                if (preg_match('/^(?:Final|Original)-Recipient:\s*rfc822;\s*<?([^\s>]+@[^\s>]+)>?/mi', $content, $r)) {
                    $recipient = strtolower($r[1]);
                }
            }

            if (in_array($type, ['text/rfc822-headers', 'message/rfc822'], true)
                && preg_match('/^Message-ID:\s*<([^>]+)>/mi', (string) $part->getContent(), $o)) {
                $original = $o[1];
            }
        }

        return $isReport || $status !== null ? [$recipient, $status ?? '5.0.0', $original] : [null, null, null];
    }

    private static function isAutoReply(Message $m, string $subject): bool
    {
        $auto = strtolower((string) $m->getHeaderValue('Auto-Submitted'));
        $precedence = strtolower((string) $m->getHeaderValue('Precedence'));

        return ($auto !== '' && $auto !== 'no')
            || $m->getHeaderValue('X-Autoreply') !== null
            || $m->getHeaderValue('X-Autorespond') !== null
            || in_array($precedence, ['auto_reply', 'bulk', 'junk'], true)
            || Str::startsWith(strtolower(trim($subject)), self::AUTO_SUBJECT_PREFIXES);
    }

    /** Only the part the person wrote: quoted history below would contain our own footer's "unsubscribe" link. */
    private static function asksToUnsubscribe(string $subject, string $text): bool
    {
        $own = strtolower($subject."\n".self::withoutQuote($text));

        return Str::contains($own, self::UNSUBSCRIBE_PHRASES);
    }

    private static function withoutQuote(string $text): string
    {
        $lines = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (preg_match('/^\s*>/', $line) || preg_match('/^(On .+ wrote:|-----Original Message-----|From: .+@.+)$/i', trim($line))) {
                break;
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /** @return array<int, string> */
    private static function ids(string $value): array
    {
        preg_match_all('/<([^>\s]+)>/', $value, $m);

        return array_values(array_unique($m[1]));
    }

    private static function bareId(?string $value): ?string
    {
        $v = trim((string) $value, " <>\t\r\n");

        return $v === '' ? null : $v;
    }

    private static function date(?string $value): Carbon
    {
        try {
            return $value ? Carbon::parse($value) : Carbon::now();
        } catch (Throwable) {
            return Carbon::now();
        }
    }
}
