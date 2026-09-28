<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor\Outreach;

use App\Domains\Advisor\Outreach\Models\Message;
use App\Domains\Advisor\Outreach\Support\Stage;

/**
 * HTTP layer: permissions per role, ownership (404 for foreign leads), error
 * envelope and the JSON shapes from docs/outreach/API-contract.md.
 */
class OutreachApiTest extends OutreachTestCase
{
    private const BASE = '/api/advisor/outreach';

    public function test_guests_and_outsiders_are_refused(): void
    {
        $this->getJson(self::BASE.'/companies')->assertUnauthorized();
        $this->actingAs($this->makeOutsider(), 'sanctum')->getJson(self::BASE.'/companies')->assertForbidden();
    }

    public function test_bolinma_has_no_outreach_access(): void
    {
        $this->actingAs($this->bolinma(), 'sanctum')->getJson(self::BASE.'/companies')
            ->assertForbidden()->assertJsonPath('reason', 'forbidden');
    }

    public function test_tuman_lists_only_own_leads_viloyat_lists_all(): void
    {
        $mine = $this->tuman();
        $theirs = $this->tuman();
        $own = $this->company($mine, ['name' => 'Mine Co']);
        $this->company($theirs, ['name' => 'Their Co']);

        $this->actingAs($mine, 'sanctum')->getJson(self::BASE.'/companies?per_page=100')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $own->id)
            ->assertJsonPath('data.0.owner_name', $mine->name);

        $viloyatIds = collect($this->actingAs($this->viloyat(), 'sanctum')
            ->getJson(self::BASE.'/companies?per_page=100&q=Co')->assertOk()->json('data'))->pluck('name');
        $this->assertTrue($viloyatIds->contains('Mine Co') && $viloyatIds->contains('Their Co'));
    }

    public function test_foreign_lead_detail_is_404(): void
    {
        $company = $this->company($this->tuman());

        $this->actingAs($this->tuman(), 'sanctum')->getJson(self::BASE.'/companies/'.$company->id)
            ->assertNotFound()->assertJsonPath('reason', 'not_found');
        $this->actingAs($this->tuman(), 'sanctum')->getJson(self::BASE.'/companies/not-a-uuid')->assertNotFound();
    }

    public function test_detail_shape(): void
    {
        $owner = $this->tuman();
        $company = $this->company($owner);
        $this->draft($this->contact($company));

        $this->actingAs($owner, 'sanctum')->getJson(self::BASE.'/companies/'.$company->id)
            ->assertOk()
            ->assertJsonStructure([
                'company' => ['id', 'name', 'stage', 'tier', 'icp_score', 'icp_breakdown', 'next_stages', 'owner_name'],
                'contacts' => [['id', 'full_name', 'email', 'active']],
                'messages' => [['id', 'subject', 'body_hash', 'status']],
                'touches', 'audit',
            ])
            ->assertJsonPath('company.next_stages', [Stage::VERIFIED, Stage::CLOSED_UNSUBSCRIBED, Stage::BLOCKED_SANCTIONS]);
    }

    public function test_create_company_errors_use_the_rule_envelope(): void
    {
        $this->country('XE', excluded: true);
        $user = $this->tuman();

        $this->actingAs($user, 'sanctum')->postJson(self::BASE.'/companies', [
            'name' => 'Bad', 'domain' => 'bad-api.test', 'country_code' => 'XE',
        ])->assertStatus(422)
            ->assertJsonPath('reason', 'country_excluded')
            ->assertJsonPath('context.excluded_reason', 'Test exclusion');

        $this->country('XA');
        $this->actingAs($user, 'sanctum')->postJson(self::BASE.'/companies', [
            'name' => 'Good', 'domain' => 'good-api.test', 'country_code' => 'XA',
        ])->assertCreated()->assertJsonPath('company.created_via', 'ui')->assertJsonPath('created', true);
    }

    public function test_stage_contact_and_touch_endpoints(): void
    {
        $owner = $this->tuman();
        $company = $this->company($owner);
        $api = $this->actingAs($owner, 'sanctum');

        $api->postJson(self::BASE.'/companies/'.$company->id.'/stage', ['to' => Stage::VERIFIED])
            ->assertStatus(422)->assertJsonPath('reason', 'transition_precondition');

        $contact = $api->postJson(self::BASE.'/contacts', [
            'company_id' => $company->id, 'full_name' => 'Jane', 'email' => 'jane@api.test', 'email_status' => 'verified',
        ])->assertCreated()->json('contact');

        $api->postJson(self::BASE.'/companies/'.$company->id.'/stage', ['to' => Stage::VERIFIED])
            ->assertOk()->assertJsonPath('company.stage', Stage::VERIFIED);

        $api->postJson(self::BASE.'/touches', [
            'company_id' => $company->id, 'contact_id' => $contact['id'], 'channel' => 'call',
            'direction' => 'out', 'summary' => 'Intro call', 'occurred_at' => now()->toIso8601String(),
        ])->assertCreated()->assertJsonPath('touch.via', 'ui');
    }

    public function test_approvals_are_viloyat_only_and_bulk_reports_failures(): void
    {
        $owner = $this->tuman();
        $good = $this->draft($this->contact($this->company($owner, ['tier' => 'A'])));
        $low = $this->draft($this->contact($this->company($owner, ['tier' => 'C'])));

        $this->actingAs($owner, 'sanctum')->getJson(self::BASE.'/approvals')->assertForbidden();
        $this->actingAs($owner, 'sanctum')->postJson(self::BASE.'/messages/'.$good->id.'/approve', ['body_hash' => $good->body_hash])
            ->assertForbidden();

        $viloyat = $this->actingAs($this->viloyat(), 'sanctum');
        $ids = collect($viloyat->getJson(self::BASE.'/approvals?per_page=100')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($good->id));

        $viloyat->postJson(self::BASE.'/approvals/bulk-approve', ['items' => [
            ['id' => $good->id, 'body_hash' => $good->body_hash],
            ['id' => $low->id, 'body_hash' => $low->body_hash],
        ]])->assertOk()
            ->assertJsonPath('approved', [$good->id])
            ->assertJsonPath('failed.0.id', $low->id)
            ->assertJsonPath('failed.0.check', 'low_tier');

        $this->assertSame(Message::APPROVED, $good->fresh()->status);
    }

    public function test_stats_and_reference_endpoints(): void
    {
        $owner = $this->tuman();
        $this->company($owner);

        $this->actingAs($owner, 'sanctum')->getJson(self::BASE.'/stats')
            ->assertOk()
            ->assertJsonPath('totals.found', 1)
            ->assertJsonPath('totals.sent', 0)
            ->assertJsonPath('totals.reply_rate', null)
            ->assertJsonMissingPath('by_owner');

        $this->actingAs($owner, 'sanctum')->getJson(self::BASE.'/countries')->assertOk()->assertJsonStructure(['data']);
        $this->actingAs($owner, 'sanctum')->getJson(self::BASE.'/advisors')->assertForbidden();

        $this->actingAs($this->viloyat(), 'sanctum')->getJson(self::BASE.'/stats')
            ->assertOk()->assertJsonStructure(['by_country', 'by_owner', 'totals']);
    }
}
