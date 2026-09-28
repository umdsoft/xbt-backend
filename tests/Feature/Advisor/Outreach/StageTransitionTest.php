<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor\Outreach;

use App\Domains\Advisor\Outreach\Models\AuditEntry;
use App\Domains\Advisor\Outreach\Services\StageMachine;
use App\Domains\Advisor\Outreach\Support\Actor;
use App\Domains\Advisor\Outreach\Support\RuleViolation;
use App\Domains\Advisor\Outreach\Support\Stage;

class StageTransitionTest extends OutreachTestCase
{
    private const TOKEN = '00000000-0000-0000-0000-0000000000aa';

    private function move(Actor $actor, string $id, string $to): string
    {
        return app(StageMachine::class)->move($actor, $id, $to)->stage;
    }

    private function expectReason(string $reason, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Expected {$reason}");
        } catch (RuleViolation $e) {
            $this->assertSame($reason, $e->reason, $e->getMessage());
        }
    }

    public function test_channel_matrix(): void
    {
        $this->assertTrue(Stage::allows(Stage::FOUND, Stage::VERIFIED, Stage::VIA_MCP));
        $this->assertFalse(Stage::allows(Stage::AWAITING_APPROVAL, Stage::SENT, Stage::VIA_MCP));
        $this->assertFalse(Stage::allows(Stage::AWAITING_APPROVAL, Stage::SENT, Stage::VIA_UI));
        $this->assertTrue(Stage::allows(Stage::AWAITING_APPROVAL, Stage::SENT, Stage::VIA_SYSTEM));
        $this->assertFalse(Stage::allows(Stage::MEETING_BOOKED, Stage::MEETING_DONE, Stage::VIA_MCP));
        $this->assertTrue(Stage::allows(Stage::MEETING_BOOKED, Stage::MEETING_DONE, Stage::VIA_UI));
        $this->assertFalse(Stage::allows(Stage::FOUND, Stage::CLOSED_DECLINED, Stage::VIA_UI));
        $this->assertTrue(Stage::allows(Stage::FOUND, Stage::BLOCKED_SANCTIONS, Stage::VIA_MCP));
        $this->assertFalse(Stage::allows(Stage::BLOCKED_SANCTIONS, Stage::FOUND, Stage::VIA_UI));
        $this->assertFalse(Stage::allows(Stage::CLOSED_DECLINED, Stage::VERIFIED, Stage::VIA_MCP));
        $this->assertFalse(Stage::allows(Stage::FOUND, Stage::SENT, Stage::VIA_UI));
    }

    public function test_happy_path_through_mcp_with_preconditions_and_audit(): void
    {
        $user = $this->viloyat();
        $company = $this->company($user);
        $mcp = Actor::mcp($user, self::TOKEN);

        $this->expectReason(RuleViolation::TRANSITION_PRECONDITION, fn () => $this->move($mcp, $company->id, Stage::VERIFIED));

        $contact = $this->contact($company, ['email_status' => 'verified']);
        $this->assertSame(Stage::VERIFIED, $this->move($mcp, $company->id, Stage::VERIFIED));

        $this->expectReason(RuleViolation::TRANSITION_PRECONDITION, fn () => $this->move($mcp, $company->id, Stage::AWAITING_APPROVAL));

        $this->draft($contact);
        $this->assertSame(Stage::AWAITING_APPROVAL, $this->move($mcp, $company->id, Stage::AWAITING_APPROVAL));

        $audit = AuditEntry::query()->where('entity_id', $company->id)->where('action', 'company.stage_changed')->orderBy('created_at')->get();
        $this->assertCount(2, $audit);
        $this->assertEquals(['from' => Stage::VERIFIED, 'to' => Stage::AWAITING_APPROVAL], $audit->last()->payload_json); // jsonb reorders keys
        $this->assertSame('claude', $audit->last()->actor);
        $this->assertSame(self::TOKEN, $audit->last()->mcp_token_id);
    }

    public function test_mcp_cannot_mark_sent_or_human_stages(): void
    {
        $user = $this->viloyat();
        $mcp = Actor::mcp($user, self::TOKEN);
        $awaiting = $this->company($user, ['stage' => Stage::AWAITING_APPROVAL]);
        $booked = $this->company($user, ['stage' => Stage::MEETING_BOOKED]);

        $this->expectReason(RuleViolation::TRANSITION_NOT_ALLOWED, fn () => $this->move($mcp, $awaiting->id, Stage::SENT));
        $this->expectReason(RuleViolation::TRANSITION_NOT_ALLOWED, fn () => $this->move($mcp, $booked->id, Stage::MEETING_DONE));
        $this->expectReason(RuleViolation::TRANSITION_NOT_ALLOWED, fn () => $this->move(Actor::ui($user), $awaiting->id, Stage::SENT));

        $this->assertSame(Stage::MEETING_DONE, $this->move(Actor::ui($user), $booked->id, Stage::MEETING_DONE));
    }

    public function test_unknown_stage_is_rejected(): void
    {
        $user = $this->viloyat();
        $company = $this->company($user);

        $this->expectReason(RuleViolation::TRANSITION_NOT_ALLOWED, fn () => $this->move(Actor::ui($user), $company->id, 'hacked'));
    }

    public function test_only_viloyat_reopens_declined_lead(): void
    {
        $owner = $this->tuman();
        $company = $this->company($owner, ['stage' => Stage::CLOSED_DECLINED]);

        $this->expectReason(RuleViolation::FORBIDDEN, fn () => $this->move(Actor::ui($owner), $company->id, Stage::VERIFIED));

        $this->assertSame(Stage::VERIFIED, $this->move(Actor::ui($this->viloyat()), $company->id, Stage::VERIFIED));
    }

    public function test_tuman_cannot_move_another_advisors_lead(): void
    {
        $company = $this->company($this->tuman(), ['stage' => Stage::SENT]);

        $this->expectReason(RuleViolation::NOT_FOUND, fn () => $this->move(Actor::ui($this->tuman()), $company->id, Stage::REPLIED));
        $this->assertSame(Stage::SENT, $company->fresh()->stage);
    }
}
