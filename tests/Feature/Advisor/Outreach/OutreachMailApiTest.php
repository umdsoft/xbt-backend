<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor\Outreach;

use App\Domains\Advisor\Outreach\Models\AuditEntry;
use App\Domains\Advisor\Outreach\Models\Company;
use App\Domains\Advisor\Outreach\Models\Message;
use App\Domains\Advisor\Outreach\Models\Reply;
use App\Domains\Advisor\Outreach\Support\Stage;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * REST endpoints for the advisor UI: inbox, thread, series, meetings,
 * opt-out and sanctions. Ownership (404), permissions and audit as `user`.
 */
class OutreachMailApiTest extends OutreachTestCase
{
    private const BASE = '/api/advisor/outreach';

    private function replyFor(User $owner, array $attrs = []): Reply
    {
        $company = $this->company($owner, ['stage' => Stage::SENT, 'tier' => 'A']);
        $contact = $this->contact($company);
        $letter = $this->draft($contact, ['status' => Message::SENT, 'sent_at' => now()->subDay()]);

        return Reply::query()->create(array_merge([
            'message_id' => $letter->id, 'company_id' => $company->id, 'contact_id' => $contact->id,
            'kind' => 'reply', 'from_email' => $contact->email, 'received_at' => now()->subHour(),
            'subject' => 'Re: Khorezm', 'body_text' => '<script>alert(1)</script> We are interested.',
            'imap_message_id' => '<'.Str::uuid().'@mail.test>',
        ], $attrs));
    }

    public function test_inbox_is_scoped_and_filters_work(): void
    {
        $tuman = $this->tuman();
        $own = $this->replyFor($tuman);
        $foreign = $this->replyFor($this->tuman());

        $ids = collect($this->actingAs($tuman, 'sanctum')->getJson(self::BASE.'/replies?per_page=100')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($own->id));
        $this->assertFalse($ids->contains($foreign->id));

        $this->actingAs($tuman, 'sanctum')->getJson(self::BASE.'/replies/'.$foreign->id)->assertNotFound()->assertJsonPath('reason', 'not_found');
        $this->actingAs($tuman, 'sanctum')->getJson(self::BASE.'/replies/'.$own->id)->assertOk()
            ->assertJsonPath('reply.untrusted_text', '<script>alert(1)</script> We are interested.')
            ->assertJsonPath('reply.needs_classification', true);

        $this->actingAs($this->bolinma(), 'sanctum')->getJson(self::BASE.'/replies')->assertForbidden();
    }

    public function test_classify_from_ui_then_attention_list_then_handled(): void
    {
        $viloyat = $this->viloyat();
        $reply = $this->replyFor($viloyat);
        $api = $this->actingAs($viloyat, 'sanctum');

        $api->postJson(self::BASE.'/replies/'.$reply->id.'/classify', ['classification' => 'interested', 'summary' => 'Wants a call'])
            ->assertOk()->assertJsonPath('changed', true)->assertJsonPath('reply.needs_attention', true);
        $this->assertSame('ui', $reply->fresh()->classified_via);
        $this->assertTrue(AuditEntry::query()->where('action', 'reply.classified')->where('entity_id', $reply->id)->where('actor', 'user')->exists());

        $attention = collect($api->getJson(self::BASE.'/replies?needs_attention=1&per_page=100')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($attention->contains($reply->id));

        $api->postJson(self::BASE.'/replies/'.$reply->id.'/handled')->assertOk()->assertJsonPath('reply.needs_attention', false);
        $this->assertFalse(collect($api->getJson(self::BASE.'/replies?needs_attention=1&per_page=100')->json('data'))->pluck('id')->contains($reply->id));

        $api->postJson(self::BASE.'/replies/'.$reply->id.'/classify', ['classification' => 'nonsense'])->assertUnprocessable();
    }

    public function test_thread_endpoint(): void
    {
        $tuman = $this->tuman();
        $reply = $this->replyFor($tuman);

        $this->actingAs($tuman, 'sanctum')->getJson(self::BASE.'/companies/'.$reply->company_id.'/thread')
            ->assertOk()->assertJsonPath('items.0.type', 'letter')->assertJsonPath('items.1.type', 'reply')
            ->assertJsonStructure(['company' => ['id'], 'contacts', 'items', 'meetings', 'truncated']);
        $this->actingAs($this->tuman(), 'sanctum')->getJson(self::BASE.'/companies/'.$reply->company_id.'/thread')->assertNotFound();
    }

    public function test_series_create_by_a_person(): void
    {
        $tuman = $this->tuman();
        $contact = $this->contact($this->company($tuman, ['tier' => 'B', 'stage' => Stage::VERIFIED]), ['language' => 'ru']);

        $res = $this->actingAs($tuman, 'sanctum')->postJson(self::BASE.'/series', [
            'contact_id' => $contact->id, 'language' => 'ru',
            'steps' => [['subject' => 'Хорезм', 'body' => 'Уважаемая Джейн, приглашаем открыть центр в Хорезме.']],
        ])->assertCreated()->assertJsonPath('stage_moved', true)->assertJsonPath('messages.0.status', 'draft');
        $this->assertNotNull($res->json('series_id'));

        $this->actingAs($tuman, 'sanctum')->postJson(self::BASE.'/series', [
            'contact_id' => $contact->id, 'language' => 'ru',
            'steps' => [['subject' => 'Again', 'body' => 'A second series must be refused here.']],
        ])->assertUnprocessable()->assertJsonPath('reason', 'already_open');

        $this->actingAs($this->tuman(), 'sanctum')->postJson(self::BASE.'/series', [
            'contact_id' => $contact->id, 'language' => 'ru', 'steps' => [['subject' => 'Foreign', 'body' => 'Not my lead, should be 404.']],
        ])->assertNotFound();
    }

    public function test_meetings_crud_lite_and_done_by_a_person(): void
    {
        $tuman = $this->tuman();
        $company = $this->company($tuman, ['stage' => Stage::REPLIED]);
        $api = $this->actingAs($tuman, 'sanctum');

        $id = $api->postJson(self::BASE.'/meetings', ['company_id' => $company->id, 'status' => 'booked', 'start_at' => now()->addDay()->toIso8601String()])
            ->assertCreated()->assertJsonPath('effects.0', 'stage:meeting_booked')->json('meeting.id');

        $api->getJson(self::BASE.'/meetings?company_id='.$company->id)->assertOk()->assertJsonPath('data.0.id', $id);
        $api->patchJson(self::BASE.'/meetings/'.$id, ['status' => 'done', 'notes' => 'Good call'])
            ->assertOk()->assertJsonPath('meeting.status', 'done');
        $this->assertSame(Stage::MEETING_DONE, $company->fresh()->stage);

        $api->patchJson(self::BASE.'/meetings/'.$id, ['status' => 'cancelled'])->assertUnprocessable()->assertJsonPath('reason', 'not_editable');
        $this->actingAs($this->tuman(), 'sanctum')->patchJson(self::BASE.'/meetings/'.$id, ['status' => 'cancelled'])->assertNotFound();
        $this->assertSame(0, collect($this->actingAs($this->tuman(), 'sanctum')->getJson(self::BASE.'/meetings')->json('data'))->where('id', $id)->count());
    }

    public function test_unsubscribe_and_sanctions_endpoints(): void
    {
        $tuman = $this->tuman();
        $company = $this->company($tuman, ['stage' => Stage::VERIFIED]);
        $contact = $this->contact($company);

        $this->actingAs($tuman, 'sanctum')->postJson(self::BASE.'/contacts/'.$contact->id.'/unsubscribe')
            ->assertOk()->assertJsonPath('already', false)->assertJsonPath('contact.active', false);
        $this->actingAs($this->tuman(), 'sanctum')->postJson(self::BASE.'/contacts/'.$contact->id.'/unsubscribe')->assertNotFound();

        $this->actingAs($tuman, 'sanctum')->postJson(self::BASE.'/companies/'.$company->id.'/sanctions', ['status' => 'clear', 'source' => 'OFAC/EU'])
            ->assertForbidden()->assertJsonPath('reason', 'forbidden');

        $other = $this->company($tuman);
        $this->actingAs($this->viloyat(), 'sanctum')->postJson(self::BASE.'/companies/'.$other->id.'/sanctions', ['status' => 'clear', 'source' => 'OFAC SDN + EU 2026-09-27'])
            ->assertOk()->assertJsonPath('company.sanctions_status', 'clear');
        $this->assertSame('clear', Company::query()->find($other->id)->sanctions_status);
        $this->actingAs($this->viloyat(), 'sanctum')->postJson(self::BASE.'/companies/'.$other->id.'/sanctions', ['status' => 'unchecked', 'source' => 'x'])->assertUnprocessable();
    }
}
