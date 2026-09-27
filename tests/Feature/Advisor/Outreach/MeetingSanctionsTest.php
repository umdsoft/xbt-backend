<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor\Outreach;

use App\Domains\Advisor\Outreach\Models\AuditEntry;
use App\Domains\Advisor\Outreach\Models\Meeting;
use App\Domains\Advisor\Outreach\Services\MeetingService;
use App\Domains\Advisor\Outreach\Services\SanctionsService;
use App\Domains\Advisor\Outreach\Support\Actor;
use App\Domains\Advisor\Outreach\Support\RuleViolation;
use App\Domains\Advisor\Outreach\Support\Stage;
use App\Models\User;

/**
 * Meeting proposals/bookings (SPEC stage 4, minimal) and the sanctions check
 * result per company (owner decision: only `clear` companies get mail).
 */
class MeetingSanctionsTest extends OutreachTestCase
{
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->viloyat();
    }

    private function mcp(): Actor
    {
        return Actor::mcp($this->owner, '00000000-0000-0000-0000-0000000000aa');
    }

    private function meetings(): MeetingService
    {
        return app(MeetingService::class);
    }

    private function sanctions(): SanctionsService
    {
        return app(SanctionsService::class);
    }

    private function refusal(callable $fn): string
    {
        try {
            $fn();
        } catch (RuleViolation $e) {
            return $e->reason;
        }
        $this->fail('Expected RuleViolation');
    }

    public function test_propose_then_book_moves_lead_to_meeting_booked(): void
    {
        $company = $this->company($this->owner, ['stage' => Stage::REPLIED]);
        $contact = $this->contact($company);

        $proposed = $this->meetings()->save($this->mcp(), [
            'company_id' => $company->id, 'contact_id' => $contact->id, 'status' => 'proposed',
            'proposed_slots' => [now()->addDays(2)->toIso8601String(), now()->addDays(3)->toIso8601String()],
        ]);
        $this->assertTrue($proposed['created']);
        $this->assertSame('proposed', $proposed['meeting']->status);
        $this->assertSame(Stage::REPLIED, $company->fresh()->stage);

        $booked = $this->meetings()->save($this->mcp(), [
            'meeting_id' => $proposed['meeting']->id, 'status' => 'booked',
            'start_at' => now()->addDays(2)->toIso8601String(), 'meeting_link' => 'https://meet.example.test/abc',
        ]);
        $this->assertSame('booked', $booked['meeting']->status);
        $this->assertContains('stage:meeting_booked', $booked['effects']);
        $this->assertSame(Stage::MEETING_BOOKED, $company->fresh()->stage);

        $this->assertSame(2, AuditEntry::query()->where('entity', 'meeting')->where('entity_id', $proposed['meeting']->id)->where('actor', 'claude')->count());
    }

    public function test_cancel_of_booked_meeting_returns_lead_to_replied(): void
    {
        $company = $this->company($this->owner, ['stage' => Stage::REPLIED]);
        $booked = $this->meetings()->save($this->mcp(), ['company_id' => $company->id, 'status' => 'booked', 'start_at' => now()->addDay()->toIso8601String()]);

        $cancelled = $this->meetings()->save($this->mcp(), ['meeting_id' => $booked['meeting']->id, 'status' => 'cancelled', 'notes' => 'Postponed by them']);

        $this->assertSame('cancelled', $cancelled['meeting']->status);
        $this->assertSame(Stage::REPLIED, $company->fresh()->stage);
        $this->assertSame(RuleViolation::NOT_EDITABLE, $this->refusal(fn () => $this->meetings()->save($this->mcp(), ['meeting_id' => $booked['meeting']->id, 'status' => 'booked', 'start_at' => now()->addDay()->toIso8601String()])));
    }

    public function test_done_is_human_only(): void
    {
        $company = $this->company($this->owner, ['stage' => Stage::REPLIED]);
        $booked = $this->meetings()->save($this->mcp(), ['company_id' => $company->id, 'status' => 'booked', 'start_at' => now()->toIso8601String()]);

        $this->assertSame(RuleViolation::TRANSITION_NOT_ALLOWED, $this->refusal(fn () => $this->meetings()->save($this->mcp(), ['meeting_id' => $booked['meeting']->id, 'status' => 'done'])));

        $done = $this->meetings()->save(Actor::ui($this->owner), ['meeting_id' => $booked['meeting']->id, 'status' => 'done']);
        $this->assertSame('done', $done['meeting']->status);
        $this->assertSame(Stage::MEETING_DONE, $company->fresh()->stage);
    }

    public function test_meeting_refusals(): void
    {
        $early = $this->company($this->owner, ['stage' => Stage::VERIFIED]);
        $this->assertSame(RuleViolation::TRANSITION_PRECONDITION, $this->refusal(fn () => $this->meetings()->save($this->mcp(), ['company_id' => $early->id, 'status' => 'proposed', 'proposed_slots' => [now()->addDay()->toIso8601String()]])));

        $company = $this->company($this->owner, ['stage' => Stage::REPLIED]);
        $this->assertSame(RuleViolation::INVALID_INPUT, $this->refusal(fn () => $this->meetings()->save($this->mcp(), ['company_id' => $company->id, 'status' => 'booked'])));
        $this->assertSame(RuleViolation::INVALID_INPUT, $this->refusal(fn () => $this->meetings()->save($this->mcp(), ['company_id' => $company->id, 'status' => 'proposed'])));

        $other = $this->contact($this->company($this->owner));
        $this->assertSame(RuleViolation::NOT_FOUND, $this->refusal(fn () => $this->meetings()->save($this->mcp(), ['company_id' => $company->id, 'contact_id' => $other->id, 'status' => 'booked', 'start_at' => now()->toIso8601String()])));

        $this->meetings()->save($this->mcp(), ['company_id' => $company->id, 'status' => 'proposed', 'proposed_slots' => [now()->addDay()->toIso8601String()]]);
        $this->assertSame(RuleViolation::ALREADY_OPEN, $this->refusal(fn () => $this->meetings()->save($this->mcp(), ['company_id' => $company->id, 'status' => 'proposed', 'proposed_slots' => [now()->addDay()->toIso8601String()]])));

        $foreign = $this->company($this->tuman(), ['stage' => Stage::REPLIED]);
        $this->assertSame(RuleViolation::NOT_FOUND, $this->refusal(fn () => $this->meetings()->save(Actor::ui($this->tuman()), ['company_id' => $foreign->id, 'status' => 'booked', 'start_at' => now()->toIso8601String()])));
        $this->assertSame(0, Meeting::query()->where('company_id', $foreign->id)->count());
    }

    public function test_mark_clear_records_source_and_is_idempotent(): void
    {
        $company = $this->company($this->owner);

        $this->sanctions()->mark($this->mcp(), $company->id, 'clear', 'OFAC SDN + EU consolidated, 2026-09-27: no match');
        $again = $this->sanctions()->mark($this->mcp(), $company->id, 'clear', 'OFAC SDN + EU consolidated, 2026-09-28: no match');

        $this->assertSame('clear', $again->sanctions_status);
        $this->assertStringContainsString('2026-09-28', $again->sanctions_source);
        $this->assertNotNull($again->sanctions_checked_at);
        $this->assertSame(2, AuditEntry::query()->where('action', 'company.sanctions_checked')->where('entity_id', $company->id)->where('actor', 'claude')->count());
    }

    public function test_hit_blocks_and_is_irreversible(): void
    {
        $company = $this->company($this->owner, ['stage' => Stage::VERIFIED]);

        $hit = $this->sanctions()->mark($this->mcp(), $company->id, 'hit', 'OFAC SDN entry 12345');
        $this->assertSame(Stage::BLOCKED_SANCTIONS, $hit->stage);

        $this->assertSame(RuleViolation::IRREVERSIBLE, $this->refusal(fn () => $this->sanctions()->mark($this->mcp(), $company->id, 'clear', 'recheck')));
        $this->assertSame('hit', $company->fresh()->sanctions_status);
    }

    public function test_sanctions_marking_is_viloyat_only_and_scoped(): void
    {
        $tuman = $this->tuman();
        $own = $this->company($tuman);

        $this->assertSame(RuleViolation::FORBIDDEN, $this->refusal(fn () => $this->sanctions()->mark(Actor::ui($tuman), $own->id, 'clear', 'checked')));
        $this->assertSame(RuleViolation::NOT_FOUND, $this->refusal(fn () => $this->sanctions()->mark($this->mcp(), '00000000-0000-0000-0000-000000000000', 'clear', 'x')));
    }
}
