<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor\Outreach;

use App\Domains\Advisor\Outreach\Models\Message;
use App\Domains\Advisor\Outreach\Models\Sender;
use App\Domains\Advisor\Outreach\Models\Suppression;
use App\Domains\Advisor\Outreach\Services\Sending\GuardDecision;
use App\Domains\Advisor\Outreach\Services\Sending\SendControl;
use App\Domains\Advisor\Outreach\Services\Sending\SendGuard;
use App\Domains\Advisor\Outreach\Support\Actor;
use App\Domains\Advisor\Outreach\Support\Stage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every refusal case of the send-time guard (SPEC §7: not approved, text
 * changed, unsubscribed, sanctions, ...) plus the series and switch rules.
 */
class SendGuardTest extends OutreachTestCase
{
    /** A letter that passes every rule; attrs override company/contact/message. */
    private function sendable(array $company = [], array $contact = [], array $message = []): Message
    {
        $c = $this->company($this->tuman(), $company + [
            'tier' => 'A', 'sanctions_status' => 'clear', 'stage' => Stage::AWAITING_APPROVAL,
        ]);
        $draft = $this->draft($this->contact($c, $contact), ['series_id' => (string) Str::uuid()]);
        $draft->forceFill($message + [
            'status' => Message::APPROVED,
            'approved_by_user_id' => $this->viloyat()->id,
            'approved_at' => now(),
        ])->save();

        return $draft->fresh();
    }

    private function decide(Message $m): GuardDecision
    {
        return app(SendGuard::class)->check($m->fresh(['contact.company.country']));
    }

    private function assertDecision(string $action, ?string $reason, Message $m): void
    {
        $d = $this->decide($m);
        $this->assertSame([$action, $reason], [$d->action, $d->reason]);
    }

    public function test_clean_approved_letter_may_be_sent(): void
    {
        $this->assertDecision(GuardDecision::SEND, null, $this->sendable());
    }

    public function test_unapproved_letter_is_skipped(): void
    {
        $draft = $this->draft($this->contact($this->company($this->tuman(), ['tier' => 'A', 'sanctions_status' => 'clear'])));

        $this->assertDecision(GuardDecision::SKIP, 'not_approved', $draft);
    }

    public function test_text_changed_behind_the_models_back_reverts_to_draft(): void
    {
        $m = $this->sendable();
        DB::connection('advisor')->table('outreach_messages')->where('id', $m->id)->update(['body' => 'Tampered']);

        $this->assertDecision(GuardDecision::REVERT, 'text_changed', $m);
    }

    public function test_unsubscribed_or_do_not_contact_is_cancelled(): void
    {
        $this->assertDecision(GuardDecision::CANCEL, 'contact_inactive', $this->sendable([], ['unsubscribed_at' => now()]));
        $this->assertDecision(GuardDecision::CANCEL, 'contact_inactive', $this->sendable([], ['do_not_contact' => true]));
        $this->assertDecision(GuardDecision::CANCEL, 'contact_inactive', $this->sendable([], ['email_status' => 'invalid']));
    }

    public function test_suppressed_email_or_domain_is_cancelled(): void
    {
        $byEmail = $this->sendable();
        Suppression::query()->create(['email' => strtolower($byEmail->contact->email), 'reason' => 'unsubscribed']);
        $this->assertDecision(GuardDecision::CANCEL, 'suppressed', $byEmail);

        $byDomain = $this->sendable();
        Suppression::query()->create(['domain' => $byDomain->contact->company->domain, 'reason' => 'complaint']);
        $this->assertDecision(GuardDecision::CANCEL, 'suppressed', $byDomain);
    }

    public function test_sanctions_hit_cancels_and_unchecked_holds(): void
    {
        $this->assertDecision(GuardDecision::CANCEL, 'sanctions_hit', $this->sendable(['sanctions_status' => 'hit']));
        $this->assertDecision(GuardDecision::HOLD, 'sanctions_unchecked', $this->sendable(['sanctions_status' => 'unchecked']));
    }

    public function test_closed_lead_low_tier_and_excluded_country_are_cancelled(): void
    {
        $this->assertDecision(GuardDecision::CANCEL, 'lead_closed', $this->sendable(['stage' => Stage::CLOSED_DECLINED]));
        $this->assertDecision(GuardDecision::CANCEL, 'low_tier', $this->sendable(['tier' => 'C']));

        $m = $this->sendable(['country_code' => $this->country('XX', excluded: true)->code]);
        $this->assertDecision(GuardDecision::CANCEL, 'country_excluded', $m);
    }

    public function test_first_letter_waits_for_the_lead_and_stops_once_a_conversation_exists(): void
    {
        $this->assertDecision(GuardDecision::HOLD, 'stage_not_ready', $this->sendable(['stage' => Stage::VERIFIED]));
        $this->assertDecision(GuardDecision::CANCEL, 'conversation_started', $this->sendable(['stage' => Stage::REPLIED]));
        $this->assertDecision(GuardDecision::SEND, null, $this->sendable(['stage' => Stage::SENT]));
    }

    public function test_follow_up_needs_the_previous_step_sent_and_no_reply(): void
    {
        $first = $this->sendable(['stage' => Stage::SENT]);
        $follow = $this->draft($first->contact, ['series_id' => $first->series_id, 'sequence_step' => 2]);
        $follow->forceFill(['status' => Message::APPROVED, 'approved_by_user_id' => $first->approved_by_user_id, 'approved_at' => now()])->save();

        $this->assertDecision(GuardDecision::HOLD, 'previous_step_pending', $follow);

        $first->forceFill(['status' => Message::SENT, 'sent_at' => now()])->save();
        $this->assertDecision(GuardDecision::SEND, null, $follow);

        $first->forceFill(['status' => Message::REPLIED])->save();
        $this->assertDecision(GuardDecision::CANCEL, 'series_stopped', $follow);

        $first->contact->company->forceFill(['stage' => Stage::REPLIED])->save();
        $this->assertDecision(GuardDecision::CANCEL, 'conversation_started', $follow);
    }

    public function test_pause_and_breaker_hold_everything(): void
    {
        $m = $this->sendable();
        $control = app(SendControl::class);

        $control->stop(Actor::ui($this->viloyat()), SendControl::PAUSE, 'Checking copy');
        $this->assertDecision(GuardDecision::HOLD, 'pause', $m);

        $control->stop(Actor::system(), SendControl::BREAKER, 'bounce_rate');
        $this->assertDecision(GuardDecision::HOLD, 'breaker', $m);

        $control->clear(Actor::ui($this->viloyat()), SendControl::BREAKER);
        $control->clear(Actor::ui($this->viloyat()), SendControl::PAUSE);
        $this->assertDecision(GuardDecision::SEND, null, $m);
    }

    public function test_never_send_reasons_beat_not_yet_reasons(): void
    {
        app(SendControl::class)->stop(Actor::system(), SendControl::PAUSE, 'x');

        $this->assertDecision(GuardDecision::CANCEL, 'contact_inactive', $this->sendable([], ['unsubscribed_at' => now()]));
    }

    public function test_sender_warmup_ramp(): void
    {
        config(['outreach.send.warmup' => [10, 20, 30]]);
        $sender = new Sender(['warmup_started_on' => '2026-10-01', 'daily_cap_max' => 40]);

        $this->assertSame(0, $sender->capOn(Carbon::parse('2026-09-30')));
        $this->assertSame(10, $sender->capOn(Carbon::parse('2026-10-01')));
        $this->assertSame(10, $sender->capOn(Carbon::parse('2026-10-07')));
        $this->assertSame(20, $sender->capOn(Carbon::parse('2026-10-08')));
        $this->assertSame(30, $sender->capOn(Carbon::parse('2026-10-15')));
        $this->assertSame(40, $sender->capOn(Carbon::parse('2026-10-22')));
        $this->assertSame(40, $sender->capOn(Carbon::parse('2027-01-01')));
    }
}
