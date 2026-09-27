<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Services\Inbox;

use App\Domains\Advisor\Outreach\Models\Contact;
use App\Domains\Advisor\Outreach\Models\Message;
use App\Domains\Advisor\Outreach\Models\Reply;
use App\Domains\Advisor\Outreach\Models\Suppression;
use App\Domains\Advisor\Outreach\Services\AuditLogger;
use App\Domains\Advisor\Outreach\Services\ContactService;
use App\Domains\Advisor\Outreach\Services\Sending\SendControl;
use App\Domains\Advisor\Outreach\Services\StageMachine;
use App\Domains\Advisor\Outreach\Support\Actor;
use App\Domains\Advisor\Outreach\Support\Stage;
use Illuminate\Support\Facades\DB;

/**
 * Stores one incoming mail and applies its mechanical consequences
 * (PLAN-send.md §7). Idempotent by the mail's Message-ID.
 *
 *   reply        letter -> replied, rest of the series cancelled, lead -> replied
 *   unsubscribe  contact unsubscribed (one-way), email suppressed, series cancelled
 *   hard bounce  letter -> bounced, contact email invalid, email suppressed,
 *                series cancelled; high bounce rate opens the circuit breaker
 *   auto_reply / soft bounce  stored only; the series continues
 *
 * Deciding what a reply MEANS (interested / later / declined) is Claude's or a
 * person's job through MCP / UI; this class never reads the text for that.
 */
final class InboundProcessor
{
    private const BOUNCE_WINDOW = 50;

    private const BOUNCE_RATE_LIMIT = 0.05;

    private const AFTER_SEND = [Message::SENT, Message::SEND_UNKNOWN];

    public function __construct(
        private readonly ContactService $contacts,
        private readonly StageMachine $stages,
        private readonly SendControl $control,
        private readonly AuditLogger $audit,
    ) {}

    public function process(InboundMail $mail): ?Reply
    {
        if ($mail->messageId !== null && Reply::query()->where('imap_message_id', $mail->messageId)->exists()) {
            return null;   // already processed
        }

        $letter = $this->matchLetter($mail);
        $contact = $letter?->contact ?? $this->matchContact($mail);

        return DB::connection('advisor')->transaction(function () use ($mail, $letter, $contact): Reply {
            $reply = Reply::query()->create([
                'message_id' => $letter?->id,
                'company_id' => $contact?->company_id,
                'contact_id' => $contact?->id,
                'kind' => $mail->kind,
                'from_email' => (string) ($mail->fromEmail ?? ''),
                'received_at' => $mail->receivedAt,
                'subject' => $mail->subject === null ? null : mb_substr($mail->subject, 0, 500),
                'body_text' => $mail->text,
                'imap_message_id' => $mail->messageId,
                'in_reply_to' => $mail->references[0] ?? null,
                'classification' => match ($mail->kind) {
                    'auto_reply' => 'auto_reply',
                    'bounce' => 'bounce',
                    'unsubscribe' => 'unsubscribe',
                    default => null,          // left for Claude / a person
                },
            ]);

            $this->audit->log(Actor::system(), 'inbound.received', 'reply', $reply->id, [
                'kind' => $mail->kind, 'message_id' => $letter?->id, 'contact_id' => $contact?->id,
            ]);

            match (true) {
                $mail->kind === 'reply' => $this->onReply($letter, $contact),
                $mail->kind === 'unsubscribe' => $this->onUnsubscribe($letter, $contact),
                $mail->isHardBounce() => $this->onHardBounce($letter, $contact, $mail),
                default => null,
            };

            return $reply;
        });
    }

    private function onReply(?Message $letter, ?Contact $contact): void
    {
        if ($letter !== null && in_array($letter->status, self::AFTER_SEND, true)) {
            $letter->forceFill(['status' => Message::REPLIED])->save();
        }
        $this->stopSeries($letter, $contact, 'replied');

        $company = $contact?->company()->lockForUpdate()->first();
        if ($company !== null && $company->stage === Stage::SENT) {
            $this->stages->apply(Actor::system(), $company, Stage::REPLIED, 'reply_received');
        }
    }

    private function onUnsubscribe(?Message $letter, ?Contact $contact): void
    {
        if ($contact === null) {
            return;
        }
        if ($contact->unsubscribed_at === null) {
            $this->contacts->upsert(Actor::system(), ['company_id' => $contact->company_id, 'contact_id' => $contact->id, 'unsubscribed' => true]);
        }
        $this->suppress($contact->email, 'unsubscribed', $letter);
        $this->stopSeries($letter, $contact, 'unsubscribed');
    }

    private function onHardBounce(?Message $letter, ?Contact $contact, InboundMail $mail): void
    {
        if ($letter !== null && in_array($letter->status, self::AFTER_SEND, true)) {
            $letter->forceFill(['status' => Message::BOUNCED, 'last_error' => 'DSN '.$mail->bounceStatus])->save();
        }
        if ($contact !== null && $contact->email_status !== 'invalid') {
            $this->contacts->upsert(Actor::system(), ['company_id' => $contact->company_id, 'contact_id' => $contact->id, 'email_status' => 'invalid']);
        }
        $this->suppress($mail->bounceRecipient ?? $contact?->email, 'hard_bounce', $letter);
        $this->stopSeries($letter, $contact, 'bounced');
        $this->checkBounceRate();
    }

    /** Cancel every not-yet-sent letter to this contact. */
    private function stopSeries(?Message $letter, ?Contact $contact, string $why): void
    {
        $contactId = $contact?->id ?? $letter?->contact_id;
        if ($contactId === null) {
            return;
        }

        $cancelled = Message::query()->where('contact_id', $contactId)
            ->whereIn('status', [Message::DRAFT, Message::APPROVED])
            ->update(['status' => Message::CANCELLED, 'last_error' => 'series_stopped:'.$why]);

        if ($cancelled > 0) {
            $this->audit->log(Actor::system(), 'series.stopped', 'contact', $contactId, ['reason' => $why, 'cancelled' => $cancelled]);
        }
    }

    private function suppress(?string $email, string $reason, ?Message $letter): void
    {
        if ($email === null || $email === '') {
            return;
        }
        Suppression::query()->firstOrCreate(['email' => strtolower($email)], ['reason' => $reason, 'source_message_id' => $letter?->id]);
    }

    /** More than 5% hard bounces among the last 50 letters: stop and let a person look. */
    private function checkBounceRate(): void
    {
        $recent = Message::query()->whereNotNull('sent_at')->orderByDesc('sent_at')->limit(self::BOUNCE_WINDOW)->pluck('status');

        if ($recent->count() >= 20 && $recent->filter(fn ($s) => $s === Message::BOUNCED)->count() / $recent->count() > self::BOUNCE_RATE_LIMIT) {
            $this->control->stop(Actor::system(), SendControl::BREAKER, 'bounce_rate_above_5_percent');
        }
    }

    private function matchLetter(InboundMail $mail): ?Message
    {
        return $mail->references === [] ? null
            : Message::query()->with('contact')->whereIn('message_id_header', $mail->references)->orderByDesc('sequence_step')->first();
    }

    /** Fallback: a reply from a known contact address (e.g. sent from another client). */
    private function matchContact(InboundMail $mail): ?Contact
    {
        $email = $mail->kind === 'bounce' ? $mail->bounceRecipient : $mail->fromEmail;

        return $email === null ? null : Contact::query()->where('email', strtolower($email))->first();
    }
}
