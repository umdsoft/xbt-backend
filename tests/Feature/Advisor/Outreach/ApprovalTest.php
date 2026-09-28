<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor\Outreach;

use App\Domains\Advisor\Outreach\Models\AuditEntry;
use App\Domains\Advisor\Outreach\Models\Message;
use App\Domains\Advisor\Outreach\Services\ApprovalService;
use App\Domains\Advisor\Outreach\Support\Actor;
use App\Domains\Advisor\Outreach\Support\RuleViolation;
use App\Models\User;

/**
 * Single and bulk approval, the hash check, pre-approval checks and the rule
 * that only a human in the UI approves (never MCP).
 */
class ApprovalTest extends OutreachTestCase
{
    private function service(): ApprovalService
    {
        return app(ApprovalService::class);
    }

    private function approvableDraft(?User $owner = null, array $companyAttrs = [], array $contactAttrs = []): Message
    {
        $company = $this->company($owner ?? $this->tuman(), $companyAttrs + ['tier' => 'B']);

        return $this->draft($this->contact($company, $contactAttrs));
    }

    private function reasonOf(callable $fn): string
    {
        try {
            $fn();
        } catch (RuleViolation $e) {
            return $e->context['check'] ?? $e->reason;
        }
        $this->fail('Expected RuleViolation');
    }

    public function test_viloyat_approves_with_matching_hash_and_it_is_audited(): void
    {
        $approver = $this->viloyat();
        $draft = $this->approvableDraft();

        $message = $this->service()->approve(Actor::ui($approver), $draft->id, $draft->body_hash);

        $this->assertSame(Message::APPROVED, $message->status);
        $this->assertSame($approver->id, $message->approved_by_user_id);
        $this->assertSame(Message::hashOf($draft->subject, $draft->body), $message->body_hash);
        $this->assertTrue(AuditEntry::query()->where('entity_id', $draft->id)->where('action', 'message.approved')->where('actor', 'user')->exists());
    }

    public function test_mcp_actor_can_never_approve_or_reject(): void
    {
        $viloyat = $this->viloyat();
        $draft = $this->approvableDraft();
        $mcp = Actor::mcp($viloyat, '00000000-0000-0000-0000-0000000000bb');

        $this->assertSame(RuleViolation::FORBIDDEN, $this->reasonOf(fn () => $this->service()->approve($mcp, $draft->id, $draft->body_hash)));
        $this->assertSame(RuleViolation::FORBIDDEN, $this->reasonOf(fn () => $this->service()->bulkApprove($mcp, [['id' => $draft->id, 'body_hash' => $draft->body_hash]])));
        $this->assertSame(RuleViolation::FORBIDDEN, $this->reasonOf(fn () => $this->service()->reject($mcp, $draft->id, 'no')));
        $this->assertSame(Message::DRAFT, $draft->fresh()->status);
    }

    public function test_tuman_cannot_approve(): void
    {
        $owner = $this->tuman();
        $draft = $this->approvableDraft($owner);

        $this->assertSame(RuleViolation::FORBIDDEN, $this->reasonOf(fn () => $this->service()->approve(Actor::ui($owner), $draft->id, $draft->body_hash)));
    }

    public function test_stale_hash_is_refused(): void
    {
        $draft = $this->approvableDraft();
        $seen = $draft->body_hash;
        $draft->update(['body' => 'Changed after the approver opened it']);

        $this->assertSame('text_changed', $this->reasonOf(fn () => $this->service()->approve(Actor::ui($this->viloyat()), $draft->id, $seen)));
    }

    public function test_pre_approval_checks(): void
    {
        $actor = Actor::ui($this->viloyat());
        $approve = fn (Message $m) => $this->reasonOf(fn () => $this->service()->approve($actor, $m->id, $m->body_hash));

        $this->assertSame('low_tier', $approve($this->approvableDraft(null, ['tier' => 'C'])));
        $this->assertSame('sanctions', $approve($this->approvableDraft(null, ['sanctions_status' => 'hit'])));
        $this->assertSame('contact_inactive', $approve($this->approvableDraft(null, [], ['do_not_contact' => true])));
        $this->assertSame('lead_closed', $approve($this->approvableDraft(null, ['stage' => 'closed_declined'])));
    }

    public function test_editing_an_approved_message_returns_it_to_draft(): void
    {
        $owner = $this->tuman();
        $draft = $this->approvableDraft($owner);
        $this->service()->approve(Actor::ui($this->viloyat()), $draft->id, $draft->body_hash);

        $edited = $this->service()->edit(Actor::ui($owner), $draft->id, $draft->subject, 'New body');

        $this->assertSame(Message::DRAFT, $edited->status);
        $this->assertNull($edited->approved_at);
        $this->assertNull($edited->approved_by_user_id);
        $this->assertSame(Message::hashOf($draft->subject, 'New body'), $edited->body_hash);
    }

    public function test_tuman_cannot_edit_another_advisors_message(): void
    {
        $draft = $this->approvableDraft($this->tuman());

        $this->assertSame(RuleViolation::NOT_FOUND, $this->reasonOf(fn () => $this->service()->edit(Actor::ui($this->tuman()), $draft->id, 'x', 'y')));
        $this->assertSame('Dear Jane, ...', $draft->fresh()->body);
    }

    public function test_reject_requires_reason(): void
    {
        $draft = $this->approvableDraft();
        $actor = Actor::ui($this->viloyat());

        $this->assertSame(RuleViolation::INVALID_INPUT, $this->reasonOf(fn () => $this->service()->reject($actor, $draft->id, '  ')));

        $rejected = $this->service()->reject($actor, $draft->id, 'Tone too pushy');
        $this->assertSame(Message::REJECTED, $rejected->status);
        $this->assertSame('Tone too pushy', $rejected->reject_reason);
    }

    public function test_bulk_approve_reports_per_item_failures(): void
    {
        $actor = Actor::ui($this->viloyat());
        $good = $this->approvableDraft();
        $lowTier = $this->approvableDraft(null, ['tier' => 'C']);
        $stale = $this->approvableDraft();

        $result = $this->service()->bulkApprove($actor, [
            ['id' => $good->id, 'body_hash' => $good->body_hash],
            ['id' => $lowTier->id, 'body_hash' => $lowTier->body_hash],
            ['id' => $stale->id, 'body_hash' => str_repeat('0', 64)],
        ]);

        $this->assertSame([$good->id], $result['approved']);
        $this->assertSame([$lowTier->id, $stale->id], array_column($result['failed'], 'id'));
        $this->assertSame(['low_tier', 'text_changed'], array_column($result['failed'], 'check'));
        $this->assertSame(Message::APPROVED, $good->fresh()->status);
        $this->assertSame(Message::DRAFT, $lowTier->fresh()->status);
    }

    public function test_bulk_limits(): void
    {
        $actor = Actor::ui($this->viloyat());

        $this->assertSame(RuleViolation::INVALID_INPUT, $this->reasonOf(fn () => $this->service()->bulkApprove($actor, [])));
        $tooMany = array_fill(0, ApprovalService::BULK_MAX + 1, ['id' => 'x', 'body_hash' => 'y']);
        $this->assertSame(RuleViolation::INVALID_INPUT, $this->reasonOf(fn () => $this->service()->bulkApprove($actor, $tooMany)));
    }

    public function test_bulk_reject_with_shared_reason(): void
    {
        $a = $this->approvableDraft();
        $b = $this->approvableDraft();

        $result = $this->service()->bulkReject(Actor::ui($this->viloyat()), [$a->id, $b->id], 'Wrong language');

        $this->assertSame([$a->id, $b->id], $result['rejected']);
        $this->assertSame('Wrong language', $b->fresh()->reject_reason);
    }
}
