<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor\Outreach;

use App\Domains\Advisor\Outreach\Models\AuditEntry;
use App\Domains\Advisor\Outreach\Models\Company;
use App\Domains\Advisor\Outreach\Models\Contact;
use App\Domains\Advisor\Outreach\Models\Message;
use App\Domains\Advisor\Outreach\Models\Suppression;
use App\Domains\Advisor\Outreach\Services\SeriesService;
use App\Domains\Advisor\Outreach\Support\Actor;
use App\Domains\Advisor\Outreach\Support\RuleViolation;
use App\Domains\Advisor\Outreach\Support\Stage;
use App\Models\User;

/**
 * Drafting a letter series (1–3 steps) and revising drafts. Drafts only:
 * nothing here approves or sends, and approved text is never touched.
 */
class SeriesTest extends OutreachTestCase
{
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->viloyat();
    }

    private function service(): SeriesService
    {
        return app(SeriesService::class);
    }

    private function mcp(?User $user = null): Actor
    {
        return Actor::mcp($user ?? $this->owner, '00000000-0000-0000-0000-0000000000aa');
    }

    private function readyContact(array $companyAttrs = [], array $contactAttrs = []): Contact
    {
        $company = $this->company($this->owner, $companyAttrs + ['tier' => 'A', 'stage' => Stage::VERIFIED]);

        return $this->contact($company, $contactAttrs + ['language' => 'en']);
    }

    /** @return array<string, mixed> */
    private function input(Contact $contact, int $steps = 3, string $language = 'en'): array
    {
        return [
            'contact_id' => $contact->id,
            'language' => $language,
            'steps' => array_map(fn (int $i): array => [
                'subject' => "Step {$i}: a delivery center in Khorezm",
                'body' => "Dear Jane, this is letter number {$i} of the series about Khorezm.",
            ], range(1, $steps)),
        ];
    }

    private function refusal(callable $fn): string
    {
        try {
            $fn();
        } catch (RuleViolation $e) {
            return $e->context['check'] ?? $e->reason;
        }
        $this->fail('Expected RuleViolation');
    }

    public function test_creates_drafts_sharing_a_series_and_moves_lead_to_awaiting_approval(): void
    {
        $contact = $this->readyContact();

        $result = $this->service()->create($this->mcp(), $this->input($contact));

        $this->assertCount(3, $result['messages']);
        $this->assertSame([1, 2, 3], $result['messages']->pluck('sequence_step')->all());
        $this->assertSame([$result['series_id']], $result['messages']->pluck('series_id')->unique()->values()->all());
        $this->assertSame([Message::DRAFT], $result['messages']->pluck('status')->unique()->values()->all());
        $this->assertTrue($result['stage_moved']);
        $this->assertSame(Stage::AWAITING_APPROVAL, $contact->company->fresh()->stage);
        foreach ($result['messages'] as $m) {
            $this->assertSame(Message::hashOf($m->subject, $m->body), $m->body_hash);
            $this->assertNull($m->approved_at);
        }

        $audit = AuditEntry::query()->where('action', 'series.drafted')->where('entity_id', $contact->company_id)->firstOrFail();
        $this->assertSame('claude', $audit->actor);
        $this->assertSame('00000000-0000-0000-0000-0000000000aa', $audit->mcp_token_id);
        $this->assertSame($result['series_id'], $audit->payload_json['series_id']);
    }

    public function test_series_for_a_lead_already_awaiting_or_sent_keeps_its_stage(): void
    {
        $contact = $this->readyContact(['stage' => Stage::SENT]);

        $result = $this->service()->create($this->mcp(), $this->input($contact, 1));

        $this->assertFalse($result['stage_moved']);
        $this->assertSame(Stage::SENT, $contact->company->fresh()->stage);
    }

    public function test_only_one_open_series_per_contact(): void
    {
        $contact = $this->readyContact();
        $first = $this->service()->create($this->mcp(), $this->input($contact, 2));

        try {
            $this->service()->create($this->mcp(), $this->input($contact, 1));
            $this->fail('Expected already_open');
        } catch (RuleViolation $e) {
            $this->assertSame(RuleViolation::ALREADY_OPEN, $e->reason);
            $this->assertSame($first['series_id'], $e->context['series_id']);
        }

        // A finished (all cancelled) series does not block a new one.
        Message::query()->where('series_id', $first['series_id'])->update(['status' => Message::CANCELLED]);
        $this->assertCount(1, $this->service()->create($this->mcp(), $this->input($contact, 1))['messages']);
    }

    public function test_refusal_reasons(): void
    {
        $this->assertSame('contact_inactive', $this->refusal(fn () => $this->service()->create($this->mcp(), $this->input($this->readyContact([], ['do_not_contact' => true])))));
        $this->assertSame('contact_inactive', $this->refusal(fn () => $this->service()->create($this->mcp(), $this->input($this->readyContact([], ['email_status' => 'invalid'])))));
        $this->assertSame('no_email', $this->refusal(fn () => $this->service()->create($this->mcp(), $this->input($this->readyContact([], ['email' => null])))));
        $this->assertSame('sanctions_hit', $this->refusal(fn () => $this->service()->create($this->mcp(), $this->input($this->readyContact(['sanctions_status' => 'hit', 'stage' => Stage::BLOCKED_SANCTIONS])))));
        $this->assertSame('lead_closed', $this->refusal(fn () => $this->service()->create($this->mcp(), $this->input($this->readyContact(['stage' => Stage::CLOSED_DECLINED])))));
        $this->assertSame('stage_not_ready', $this->refusal(fn () => $this->service()->create($this->mcp(), $this->input($this->readyContact(['stage' => Stage::FOUND])))));
        $this->assertSame('stage_not_ready', $this->refusal(fn () => $this->service()->create($this->mcp(), $this->input($this->readyContact(['stage' => Stage::REPLIED])))));
        $this->assertSame('low_tier', $this->refusal(fn () => $this->service()->create($this->mcp(), $this->input($this->readyContact(['tier' => 'C'])))));
        $this->assertSame('language_mismatch', $this->refusal(fn () => $this->service()->create($this->mcp(), $this->input($this->readyContact(), 1, 'zh'))));

        $suppressed = $this->readyContact();
        Suppression::query()->create(['email' => $suppressed->email, 'reason' => 'unsubscribed']);
        $this->assertSame('suppressed', $this->refusal(fn () => $this->service()->create($this->mcp(), $this->input($suppressed))));

        $this->assertSame(0, Message::query()->whereIn('contact_id', Contact::query()->whereIn('company_id', Company::query()->where('owner_user_id', $this->owner->id)->select('id'))->select('id'))->count());
    }

    public function test_language_may_be_contact_country_company_or_english(): void
    {
        $contact = $this->readyContact(['languages' => ['tr']], ['language' => 'ru']);

        foreach (['ru', 'tr', 'en'] as $i => $lang) {
            $result = $this->service()->create($this->mcp(), $this->input($contact, 1, $lang));
            Message::query()->where('series_id', $result['series_id'])->update(['status' => Message::CANCELLED]);
        }

        $this->assertSame(3, Message::query()->where('contact_id', $contact->id)->count());
    }

    public function test_foreign_lead_is_not_found_for_tuman(): void
    {
        $contact = $this->readyContact();
        $tuman = $this->tuman();

        $this->assertSame('not_found', $this->refusal(fn () => $this->service()->create(Actor::ui($tuman), $this->input($contact))));
    }

    public function test_revise_updates_a_draft_and_resubmits_a_rejected_one(): void
    {
        $contact = $this->readyContact();
        $series = $this->service()->create($this->mcp(), $this->input($contact, 2));
        [$one, $two] = $series['messages']->all();
        $two->forceFill(['status' => Message::REJECTED, 'reject_reason' => 'Too long'])->save();

        $edited = $this->service()->revise($this->mcp(), $one->id, ['body' => 'Dear Jane, a shorter and better first letter.']);
        $this->assertSame(Message::DRAFT, $edited->status);
        $this->assertSame(Message::hashOf($one->subject, 'Dear Jane, a shorter and better first letter.'), $edited->body_hash);

        $resubmitted = $this->service()->revise($this->mcp(), $two->id, ['subject' => 'Shorter follow-up']);
        $this->assertSame(Message::DRAFT, $resubmitted->status);
        $this->assertSame('Shorter follow-up', $resubmitted->subject);

        $this->assertSame(2, AuditEntry::query()->where('action', 'message.edited')->where('actor', 'claude')->whereIn('entity_id', [$one->id, $two->id])->count());
    }

    public function test_revise_refuses_approved_and_final_messages(): void
    {
        $contact = $this->readyContact();
        $approved = $this->draft($contact);
        $approved->forceFill(['status' => Message::APPROVED, 'approved_by_user_id' => $this->owner->id, 'approved_at' => now()])->save();
        $sent = $this->draft($contact, ['status' => Message::SENT]);

        foreach ([$approved, $sent] as $message) {
            try {
                $this->service()->revise($this->mcp(), $message->id, ['subject' => 'Changed']);
                $this->fail('Expected not_editable');
            } catch (RuleViolation $e) {
                $this->assertSame(RuleViolation::NOT_EDITABLE, $e->reason);
            }
        }

        $fresh = $approved->fresh();
        $this->assertSame(Message::APPROVED, $fresh->status);
        $this->assertNotNull($fresh->approved_at);
    }

    public function test_revise_of_foreign_message_is_not_found(): void
    {
        $message = $this->draft($this->readyContact());

        $this->assertSame('not_found', $this->refusal(fn () => $this->service()->revise(Actor::ui($this->tuman()), $message->id, ['subject' => 'x y z'])));
    }
}
