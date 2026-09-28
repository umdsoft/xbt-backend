<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor\Outreach;

use App\Domains\Advisor\Outreach\Models\AuditEntry;
use App\Domains\Advisor\Outreach\Models\Message;
use App\Domains\Advisor\Outreach\Models\Sender;
use App\Domains\Advisor\Outreach\Services\Sending\SendControl;
use App\Domains\Advisor\Outreach\Support\Actor;
use App\Domains\Advisor\Outreach\Support\Stage;

/** Sending dashboard and human controls over HTTP (viloyat only). */
class SendingAdminTest extends OutreachTestCase
{
    private const BASE = '/api/advisor/outreach/sending';

    private function approvedLetter(string $status = Message::APPROVED): Message
    {
        $c = $this->company($this->tuman(), ['tier' => 'A', 'sanctions_status' => 'clear', 'stage' => Stage::AWAITING_APPROVAL]);
        $m = $this->draft($this->contact($c));
        $m->forceFill(['status' => $status, 'approved_by_user_id' => $this->viloyat()->id, 'approved_at' => now(), 'claimed_at' => now()])->save();

        return $m->fresh();
    }

    public function test_only_viloyat_sees_and_controls_sending(): void
    {
        $this->actingAs($this->tuman(), 'sanctum')->getJson(self::BASE)->assertForbidden();
        $this->actingAs($this->tuman(), 'sanctum')->postJson(self::BASE.'/pause', ['reason' => 'x'])->assertForbidden();

        $this->actingAs($this->viloyat(), 'sanctum')->getJson(self::BASE)->assertOk()
            ->assertJsonStructure(['mode', 'pause', 'breaker', 'today' => ['sent', 'capacity'], 'queue', 'senders', 'unknown']);
    }

    public function test_pause_resume_and_breaker_reset(): void
    {
        $api = $this->actingAs($this->viloyat(), 'sanctum');

        $api->postJson(self::BASE.'/pause', ['reason' => 'Reviewing copy'])->assertOk()->assertJsonPath('pause.reason', 'Reviewing copy');
        $this->assertSame(SendControl::PAUSE, app(SendControl::class)->stopReason());

        $api->postJson(self::BASE.'/resume')->assertOk()->assertJsonPath('pause', null);

        app(SendControl::class)->stop(Actor::system(), SendControl::BREAKER, 'bounces');
        $api->postJson(self::BASE.'/breaker/reset')->assertOk()->assertJsonPath('breaker', null);
        $this->assertNull(app(SendControl::class)->stopReason());
        $this->assertTrue(AuditEntry::query()->where('action', 'send.breaker_off')->where('actor', 'user')->exists());
    }

    public function test_mailbox_pool_management(): void
    {
        $api = $this->actingAs($this->viloyat(), 'sanctum');

        $id = $api->postJson(self::BASE.'/senders', [
            'email' => 'Team1@Invest.Test', 'display_name' => 'Investment team', 'warmup_started_on' => '2026-10-01', 'daily_cap_max' => 40,
        ])->assertCreated()->json('sender.id');
        $this->assertSame('team1@invest.test', Sender::query()->find($id)->email);

        $api->postJson(self::BASE.'/senders', ['email' => 'team1@invest.test', 'display_name' => 'x'])->assertStatus(422);

        Sender::query()->whereKey($id)->update(['paused_at' => now()->toIso8601String(), 'paused_reason' => 'blocked']);
        $api->patchJson(self::BASE.'/senders/'.$id, ['resume' => true, 'daily_cap_max' => 30])->assertOk()->assertJsonPath('sender.paused_at', null);
        $this->assertSame(30, Sender::query()->find($id)->daily_cap_max);
    }

    public function test_unqueue_returns_an_approved_letter_to_draft(): void
    {
        $m = $this->approvedLetter();
        $sent = $this->approvedLetter(Message::SENT);
        $api = $this->actingAs($this->viloyat(), 'sanctum');

        $api->postJson(self::BASE.'/messages/'.$m->id.'/unqueue')->assertOk()->assertJsonPath('message.status', Message::DRAFT);
        $this->assertNull($m->fresh()->approved_at);

        $api->postJson(self::BASE.'/messages/'.$sent->id.'/unqueue')->assertStatus(422)->assertJsonPath('reason', 'not_approvable');
    }

    public function test_person_resolves_an_ambiguous_delivery(): void
    {
        $m = $this->approvedLetter(Message::SEND_UNKNOWN);
        $api = $this->actingAs($this->viloyat(), 'sanctum');

        $api->getJson(self::BASE)->assertOk()->assertJsonPath('unknown.0.id', $m->id);

        $api->postJson(self::BASE.'/messages/'.$m->id.'/resolve', ['outcome' => 'sent'])->assertOk()->assertJsonPath('message.status', Message::SENT);
        $this->assertSame(Stage::SENT, $m->contact->company->fresh()->stage);

        $api->postJson(self::BASE.'/messages/'.$m->id.'/resolve', ['outcome' => 'failed'])->assertStatus(422)->assertJsonPath('reason', 'irreversible');
    }
}
