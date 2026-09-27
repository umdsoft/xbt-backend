<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Services\Sending;

use App\Domains\Advisor\Outreach\Models\Message;
use App\Domains\Advisor\Outreach\Models\SendLogEntry;
use App\Domains\Advisor\Outreach\Services\AuditLogger;
use App\Domains\Advisor\Outreach\Support\Actor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * One scheduler tick (every minute): walks the approved queue oldest-first and
 * sends up to `outreach.send.batch` letters that pass SendGuard, fall inside
 * the recipient's business hours and fit the mailbox and recipient-domain caps.
 *
 * The claim (approved -> sending) is an atomic conditional UPDATE, so two
 * overlapping runs can never send the same letter twice.
 */
final class SendDispatcher
{
    private const SCAN_FACTOR = 20;

    public function __construct(
        private readonly SendGuard $guard,
        private readonly SendControl $control,
        private readonly SenderPool $pool,
        private readonly SendWindow $window,
        private readonly LetterSender $letters,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string, int> counts per outcome for this run */
    public function run(Carbon $now): array
    {
        $counts = [];

        if (! $this->enabled() || $this->control->stopReason() !== null) {
            return $counts;
        }

        $batch = max(1, (int) config('outreach.send.batch', 10));

        foreach ($this->due($now, $batch * self::SCAN_FACTOR) as $message) {
            if (($counts[LetterSender::SENT] ?? 0) + ($counts[LetterSender::FAILED] ?? 0) + ($counts[LetterSender::UNKNOWN] ?? 0) >= $batch) {
                break;
            }

            $outcome = $this->handle($message, $now);
            if ($outcome === 'no_capacity') {
                break;
            }
            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
        }

        return $counts;
    }

    private function handle(Message $message, Carbon $now): string
    {
        // Re-read: an earlier letter of this run may have changed the lead's
        // stage or scheduled this follow-up for later.
        $message = $message->fresh(['contact.company.country']);
        if ($message === null || ($message->scheduled_for !== null && $message->scheduled_for->greaterThan($now))) {
            return 'not_due';
        }

        $decision = $this->guard->check($message);

        if (! $decision->allowsSending()) {
            return $this->apply($message, $decision);
        }

        $contact = $message->contact;
        if (! $this->window->isOpen($contact->company->country?->timezone, $now)) {
            return 'outside_window';
        }
        if ($this->domainSentToday($contact->email, $now) >= (int) config('outreach.send.per_domain_daily', 2)) {
            return 'domain_cap';
        }

        $sender = $this->pool->pick($now);
        if ($sender === null) {
            return 'no_capacity';
        }

        if (! $this->claim($message, $sender->id, $now)) {
            return 'claimed_elsewhere';
        }
        $this->pool->markUsed($sender, $now);

        return $this->letters->deliver($message->fresh(['contact.company.country']), $sender, $now);
    }

    private function claim(Message $message, string $senderId, Carbon $now): bool
    {
        return Message::query()->whereKey($message->id)->where('status', Message::APPROVED)->update([
            'status' => Message::SENDING,
            'claim_id' => (string) Str::uuid(),
            'claimed_at' => $now->format('Y-m-d H:i:sP'),
            'sender_id' => $senderId,
        ]) === 1;
    }

    private function apply(Message $message, GuardDecision $decision): string
    {
        switch ($decision->action) {
            case GuardDecision::CANCEL:
                $message->forceFill(['status' => Message::CANCELLED, 'last_error' => $decision->reason])->save();
                $this->record($message, 'cancelled', $decision->reason);
                $this->audit->log(Actor::system(), 'message.cancelled', 'message', $message->id, ['reason' => $decision->reason]);
                break;
            case GuardDecision::REVERT:
                $message->forceFill(['status' => Message::DRAFT, 'approved_by_user_id' => null, 'approved_at' => null, 'last_error' => $decision->reason])->save();
                $this->record($message, 'reverted', $decision->reason);
                $this->audit->log(Actor::system(), 'message.reverted_to_draft', 'message', $message->id, ['reason' => $decision->reason]);
                break;
            case GuardDecision::HOLD:
                $this->recordHoldOnce($message, (string) $decision->reason);
                break;
        }

        return $decision->action;
    }

    /** A held letter is re-checked every minute; log only when the reason changes. */
    private function recordHoldOnce(Message $message, string $reason): void
    {
        $last = SendLogEntry::query()->where('message_id', $message->id)->orderByDesc('created_at')->first(['outcome', 'reason']);

        if ($last === null || $last->outcome !== 'held' || $last->reason !== $reason) {
            $this->record($message, 'held', $reason);
        }
    }

    private function record(Message $message, string $outcome, ?string $reason): void
    {
        SendLogEntry::query()->create(['message_id' => $message->id, 'outcome' => $outcome, 'reason' => $reason]);
    }

    /** @return Collection<int, Message> */
    private function due(Carbon $now, int $limit)
    {
        return Message::query()->with('contact.company.country')
            ->where('status', Message::APPROVED)
            ->where(fn (Builder $q) => $q->whereNull('scheduled_for')->orWhere('scheduled_for', '<=', $now->format('Y-m-d H:i:sP')))
            ->orderBy('approved_at')->orderBy('sequence_step')
            ->limit($limit)->get();
    }

    private function domainSentToday(?string $email, Carbon $now): int
    {
        $domain = strtolower(substr((string) strrchr((string) $email, '@'), 1));
        $day = $this->pool->day($now);

        return Message::query()
            ->whereIn('status', [Message::SENDING, Message::SENT, Message::REPLIED, Message::BOUNCED, Message::SEND_UNKNOWN])
            ->where('claimed_at', '>=', $day->format('Y-m-d H:i:sP'))
            ->whereHas('contact', fn (Builder $c) => $c->whereRaw("lower(split_part(email, '@', 2)) = ?", [$domain]))
            ->count();
    }

    /** Off by default; live mode refuses to run without the legally required sender identity. */
    private function enabled(): bool
    {
        $mode = (string) config('outreach.send.mode', 'off');

        if ($mode === 'test') {
            return (string) config('outreach.send.test_recipient') !== '';
        }

        if ($mode === 'live') {
            $id = (array) config('outreach.send.identity');
            if (empty($id['name']) || empty($id['organization']) || empty($id['address'])) {
                Log::error('outreach.send: live mode without sender identity (OUTREACH_SENDER_*); nothing sent.');

                return false;
            }

            return true;
        }

        return false;
    }
}
