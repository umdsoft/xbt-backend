<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Services\Sending;

use App\Domains\Advisor\Outreach\Models\Company;
use App\Domains\Advisor\Outreach\Models\Contact;
use App\Domains\Advisor\Outreach\Models\Message;
use App\Domains\Advisor\Outreach\Models\Suppression;
use App\Domains\Advisor\Outreach\Support\Stage;

/**
 * The last check before a letter leaves (PLAN-send.md §4, SPEC §4.3,
 * CLAUDE.md rules 1, 4, 5).
 *
 * Runs at send time, not approval time: between the two a contact may have
 * unsubscribed, a sanctions hit may have been recorded, a reply may have come.
 * Order matters — the first failing rule decides, and "never send" reasons
 * win over "not yet" reasons.
 *
 * Capacity (daily caps, recipient's business hours) is the scheduler's job;
 * this class answers only "may this letter be sent at all right now".
 */
final class SendGuard
{
    private const SENDABLE_TIERS = ['A', 'B'];

    /** Company stages in which the first letter of a series may go out. */
    private const FIRST_LETTER_STAGES = [Stage::AWAITING_APPROVAL];

    /** Company stages in which follow-ups may go out (no reply yet). */
    private const FOLLOW_UP_STAGES = [Stage::SENT];

    public function __construct(private readonly SendControl $control) {}

    public function check(Message $message): GuardDecision
    {
        // 1. Only a letter a person approved.
        if ($message->status !== Message::APPROVED || $message->approved_by_user_id === null || $message->approved_at === null) {
            return GuardDecision::skip('not_approved');
        }

        // 2. The text must be exactly the approved text (catches edits that bypassed the model).
        if (! hash_equals((string) $message->body_hash, $message->currentHash())) {
            return GuardDecision::revert('text_changed');
        }

        $contact = $message->contact;
        $company = $contact?->company;
        if ($contact === null || $company === null) {
            return GuardDecision::cancel('orphaned');
        }

        return $this->recipientDecision($contact)
            ?? $this->companyDecision($company)
            ?? $this->seriesDecision($message, $company)
            ?? $this->switchDecision()
            ?? GuardDecision::send();
    }

    /** 3–4. The person agreed not to be contacted / must not be. */
    private function recipientDecision(Contact $contact): ?GuardDecision
    {
        if (! $contact->isActive() || $contact->email === null) {
            return GuardDecision::cancel('contact_inactive');
        }

        $email = strtolower($contact->email);
        $domain = substr((string) strrchr($email, '@'), 1);

        $suppressed = Suppression::query()
            ->where('email', $email)
            ->when($domain !== '', fn ($q) => $q->orWhere('domain', $domain))
            ->exists();

        return $suppressed ? GuardDecision::cancel('suppressed') : null;
    }

    /** 5–6. Sanctions, lead state, fit, country. */
    private function companyDecision(Company $company): ?GuardDecision
    {
        return match (true) {
            $company->sanctions_status === 'hit' || $company->stage === Stage::BLOCKED_SANCTIONS => GuardDecision::cancel('sanctions_hit'),
            Stage::isClosed($company->stage) => GuardDecision::cancel('lead_closed'),
            $company->country === null || $company->country->excluded => GuardDecision::cancel('country_excluded'),
            ! in_array($company->tier, self::SENDABLE_TIERS, true) => GuardDecision::cancel('low_tier'),
            // Owner decision 2026-09-27: only companies checked as clear are mailed; unchecked ones wait.
            $company->sanctions_status !== 'clear' => GuardDecision::hold('sanctions_unchecked'),
            default => null,
        };
    }

    /** 9. First letter vs follow-ups of the same series. */
    private function seriesDecision(Message $message, Company $company): ?GuardDecision
    {
        if ($message->sequence_step <= 1) {
            return in_array($company->stage, [...self::FIRST_LETTER_STAGES, ...self::FOLLOW_UP_STAGES], true)
                ? null
                : $this->notReadyOrOver($company->stage);
        }

        if (! in_array($company->stage, self::FOLLOW_UP_STAGES, true)) {
            // Before the first letter went out the follow-up simply waits.
            return $company->stage === Stage::AWAITING_APPROVAL
                ? GuardDecision::hold('previous_step_pending')
                : $this->notReadyOrOver($company->stage);
        }

        $previous = $message->series_id === null ? null : Message::query()
            ->where('series_id', $message->series_id)
            ->where('sequence_step', $message->sequence_step - 1)
            ->first();

        return match (true) {
            $previous === null => GuardDecision::cancel('series_broken'),
            $previous->status === Message::SENT => null,
            in_array($previous->status, [Message::DRAFT, Message::APPROVED, Message::SENDING], true) => GuardDecision::hold('previous_step_pending'),
            default => GuardDecision::cancel('series_stopped'),   // replied, bounced, cancelled, failed, unknown
        };
    }

    /** Lead not yet at "awaiting approval" → wait; already in a conversation → automation stops. */
    private function notReadyOrOver(string $stage): GuardDecision
    {
        return in_array($stage, [Stage::FOUND, Stage::VERIFIED], true)
            ? GuardDecision::hold('stage_not_ready')
            : GuardDecision::cancel('conversation_started');
    }

    /** 8. Pause and circuit breaker. */
    private function switchDecision(): ?GuardDecision
    {
        $stop = $this->control->stopReason();

        return $stop === null ? null : GuardDecision::hold($stop);
    }
}
