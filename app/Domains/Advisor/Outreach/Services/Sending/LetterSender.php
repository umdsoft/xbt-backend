<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Services\Sending;

use App\Domains\Advisor\Outreach\Models\Message;
use App\Domains\Advisor\Outreach\Models\Sender;
use App\Domains\Advisor\Outreach\Models\SendLogEntry;
use App\Domains\Advisor\Outreach\Services\AuditLogger;
use App\Domains\Advisor\Outreach\Services\StageMachine;
use App\Domains\Advisor\Outreach\Support\Actor;
use App\Domains\Advisor\Outreach\Support\Stage;
use Illuminate\Mail\Message as MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Puts one claimed letter on the wire through the `outreach` mailer (Mailcow)
 * and records the outcome (PLAN-send.md §3, §5, §7).
 *
 * Failure handling is deliberately conservative:
 *   4xx / could not connect  -> back to the queue, retried later (server refused, nothing left)
 *   5xx                      -> failed (and the mailbox paused if it looks like a block)
 *   anything else            -> send_unknown: may have been delivered, a person decides
 */
final class LetterSender
{
    public const SENT = 'sent';

    public const DEFERRED = 'deferred';

    public const FAILED = 'failed';

    public const UNKNOWN = 'unknown';

    private const BLOCK_MARKERS = ['block', 'spam', 'blacklist', 'listed', 'reputation', 'policy', 'rejected due to'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly StageMachine $stages,
        private readonly SendControl $control,
    ) {}

    public function deliver(Message $message, Sender $sender, Carbon $now): string
    {
        $contact = $message->contact;
        $domain = substr((string) strrchr($sender->email, '@'), 1);
        $messageId = Str::uuid()->toString().'@'.$domain;

        $message->forceFill(['message_id_header' => $messageId])->save();

        try {
            Mail::mailer('outreach')->raw($this->text($message), function (MailMessage $mail) use ($message, $sender, $contact, $messageId): void {
                $this->compose($mail, $message, $sender, (string) $contact->email, $messageId);
            });
        } catch (TransportExceptionInterface $e) {
            return $this->onFailure($message, $sender, $e, $now);
        }

        $this->onSent($message, $sender, $messageId, $now);

        return self::SENT;
    }

    private function compose(MailMessage $mail, Message $message, Sender $sender, string $email, string $messageId): void
    {
        $test = config('outreach.send.mode') === 'test';

        $mail->from($sender->email, $sender->display_name)
            ->replyTo($sender->email, $sender->display_name)
            ->to($test ? (string) config('outreach.send.test_recipient') : $email)
            ->subject((string) $message->subject);

        if ($bounce = config('outreach.send.bounce_address')) {
            $mail->returnPath((string) $bounce);
        }

        $headers = $mail->getSymfonyMessage()->getHeaders();
        $headers->addIdHeader('Message-ID', $messageId);
        // RFC 8058 one-click unsubscribe (required by Gmail/Yahoo for bulk senders).
        $headers->addTextHeader('List-Unsubscribe', '<'.$this->unsubscribeUrl($message).'>');
        $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        if ($previous = $this->threadParent($message)) {
            $headers->addIdHeader('In-Reply-To', $previous);
            $headers->addIdHeader('References', $previous);
        }

        if ($test) {
            $headers->addTextHeader('X-Outreach-Original-To', $email);
        }
    }

    /** Body + real sender identity + visible unsubscribe link (SPEC §4.3). */
    private function text(Message $message): string
    {
        $id = (array) config('outreach.send.identity');
        $signature = implode("\n", array_filter([$id['name'] ?? null, $id['title'] ?? null, $id['organization'] ?? null, $id['address'] ?? null]));

        return rtrim((string) $message->body)."\n\n--\n".$signature
            ."\n\nIf you would rather not hear from us again: ".$this->unsubscribeUrl($message)."\n";
    }

    private function unsubscribeUrl(Message $message): string
    {
        return URL::signedRoute('api.outreach.unsubscribe', ['contact' => $message->contact_id]);
    }

    /** Follow-ups continue the thread of the first letter. */
    private function threadParent(Message $message): ?string
    {
        if ($message->sequence_step <= 1 || $message->series_id === null) {
            return null;
        }

        return Message::query()->where('series_id', $message->series_id)
            ->where('sequence_step', 1)->value('message_id_header');
    }

    private function onSent(Message $message, Sender $sender, string $messageId, Carbon $now): void
    {
        DB::connection('advisor')->transaction(function () use ($message, $sender, $messageId, $now): void {
            $message->forceFill([
                'status' => Message::SENT,
                'sent_at' => $now,
                'smtp_message_id' => $messageId,
                'last_error' => null,
            ])->save();

            $this->log($message, $sender, self::SENT, null, null, null);
            $this->audit->log(Actor::system(), 'message.sent', 'message', $message->id, ['sender' => $sender->email, 'step' => $message->sequence_step]);

            $company = $message->contact->company()->lockForUpdate()->first();
            if ($company !== null && $company->stage === Stage::AWAITING_APPROVAL) {
                $this->stages->apply(Actor::system(), $company, Stage::SENT, 'first_letter_sent');
            }

            if ($message->sequence_step <= 1 && $message->series_id !== null) {
                $this->scheduleFollowUps($message, $now);
            }
        });
    }

    /** Follow-ups are due N days after the first letter actually went out. */
    private function scheduleFollowUps(Message $first, Carbon $sentAt): void
    {
        foreach ((array) config('outreach.send.follow_up_days', []) as $step => $days) {
            Message::query()->where('series_id', $first->series_id)->where('sequence_step', (int) $step)
                ->whereIn('status', [Message::DRAFT, Message::APPROVED])
                ->update(['scheduled_for' => $sentAt->copy()->addDays((int) $days)->format('Y-m-d H:i:sP')]);
        }
    }

    private function onFailure(Message $message, Sender $sender, TransportExceptionInterface $e, Carbon $now): string
    {
        $code = (int) $e->getCode();
        $detail = Str::limit($e->getMessage(), 1000);
        $attempts = $message->send_attempts + 1;

        $outcome = match (true) {
            $code >= 500 && $code < 600 => self::FAILED,
            ($code >= 400 && $code < 500) || $this->neverConnected($e) => $attempts >= (int) config('outreach.send.max_attempts', 3) ? self::FAILED : self::DEFERRED,
            default => self::UNKNOWN,
        };

        $message->forceFill([
            'send_attempts' => $attempts,
            'last_error' => $detail,
            'status' => match ($outcome) {
                self::DEFERRED => Message::APPROVED,
                self::FAILED => Message::FAILED,
                default => Message::SEND_UNKNOWN,
            },
            'scheduled_for' => $outcome === self::DEFERRED ? $now->copy()->addMinutes($attempts * (int) config('outreach.send.retry_minutes', 30)) : $message->scheduled_for,
            'claim_id' => null,
            'sender_id' => $outcome === self::DEFERRED ? null : $sender->id,
        ])->save();

        $this->log($message, $sender, $outcome, 'smtp', $code ?: null, $detail);
        $this->audit->log(Actor::system(), 'message.send_'.$outcome, 'message', $message->id, ['sender' => $sender->email, 'smtp_code' => $code ?: null]);

        if ($code >= 500 && $this->looksLikeBlock($detail)) {
            $sender->forceFill(['paused_at' => $now, 'paused_reason' => 'Blocked by recipient server: '.Str::limit($detail, 200)])->save();
            $this->audit->log(Actor::system(), 'sender.paused', 'sender', $sender->id, ['smtp_code' => $code]);
        }

        $this->checkBreaker();

        return $outcome;
    }

    private function neverConnected(TransportExceptionInterface $e): bool
    {
        $m = strtolower($e->getMessage());

        return str_contains($m, 'could not be established') || str_contains($m, 'unable to connect') || str_contains($m, 'connection refused');
    }

    private function looksLikeBlock(string $detail): bool
    {
        return Str::contains(strtolower($detail), self::BLOCK_MARKERS);
    }

    /** Too many failures in a row: stop everything until a person looks. */
    private function checkBreaker(): void
    {
        $limit = (int) config('outreach.send.breaker_consecutive_failures', 5);
        $recent = SendLogEntry::query()->whereIn('outcome', [self::SENT, self::DEFERRED, self::FAILED, self::UNKNOWN])
            ->orderByDesc('created_at')->limit($limit)->pluck('outcome');

        if ($recent->count() === $limit && ! $recent->contains(self::SENT)) {
            $this->control->stop(Actor::system(), SendControl::BREAKER, "{$limit} consecutive failed attempts");
        }
    }

    private function log(Message $message, Sender $sender, string $outcome, ?string $reason, ?int $code, ?string $detail): void
    {
        SendLogEntry::query()->create([
            'message_id' => $message->id,
            'sender_id' => $sender->id,
            'outcome' => $outcome,
            'reason' => $reason,
            'smtp_code' => $code,
            'detail' => $detail,
        ]);
    }
}
