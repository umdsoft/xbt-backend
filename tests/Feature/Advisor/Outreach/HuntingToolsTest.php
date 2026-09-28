<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor\Outreach;

use App\Domains\Advisor\Outreach\Models\AuditEntry;
use App\Domains\Advisor\Outreach\Models\Contact;
use App\Domains\Advisor\Outreach\Models\Country;
use App\Domains\Advisor\Outreach\Services\CountryService;
use App\Domains\Advisor\Outreach\Services\McpTokenService;
use App\Domains\Advisor\Outreach\Support\Actor;
use App\Domains\Advisor\Outreach\Support\RuleViolation;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Hunting tools (owner decision 2026-09-28): Claude records countries itself,
 * loads contacts in bulk; exclusions can be added by Claude but lifted only by
 * a viloyat advisor in the UI.
 */
class HuntingToolsTest extends OutreachTestCase
{
    private User $owner;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->viloyat();
        $this->token = app(McpTokenService::class)->issue(Actor::ui($this->owner), 'hunting test')['plain_text'];
        RateLimiter::clear('outreach-mcp-w:'.$this->owner->id);
        RateLimiter::clear('outreach-mcp-d:'.$this->owner->id.':'.now()->toDateString());
        // A real ISO code used only by this test; make sure no dev-DB row interferes.
        Country::query()->whereKey('LU')->delete();
    }

    /** @return array{error: bool, data: array<string, mixed>} */
    private function tool(string $name, array $args = []): array
    {
        $result = $this->withHeaders(['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json, text/event-stream'])
            ->postJson('/api/mcp/outreach', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $args]])
            ->assertOk()->json('result');

        return ['error' => (bool) ($result['isError'] ?? false), 'data' => json_decode($result['content'][0]['text'] ?? '', true) ?? []];
    }

    public function test_claude_records_a_researched_country_and_can_then_add_companies_there(): void
    {
        $refused = $this->tool('upsert_company', ['domain' => 'lux-it.test', 'name' => 'Lux IT', 'country_code' => 'LU']);
        $this->assertSame('country_unknown', $refused['data']['reason']);

        $country = $this->tool('upsert_country', [
            'code' => 'lu', 'wave' => '2', 'score' => 55, 'default_language' => 'en', 'timezone' => 'Europe/Luxembourg',
            'notes' => 'Small but rich market; many EU fintech vendors.', 'source' => 'Eurostat ICT 2025',
        ]);
        $this->assertFalse($country['error'], json_encode($country['data']));
        $this->assertSame('Luxembourg', $country['data']['country']['name'], 'name defaults to the ISO name');

        $this->assertFalse($this->tool('upsert_company', ['domain' => 'lux-it.test', 'name' => 'Lux IT', 'country_code' => 'LU'])['error']);

        $listed = collect($this->tool('list_countries')['data']['countries'])->firstWhere('code', 'LU');
        $this->assertSame(1, $listed['leads']);
        $this->assertSame('mcp', Country::query()->find('LU')->updated_via);
        $this->assertTrue(AuditEntry::query()->where('action', 'country.created')->where('entity_id', 'LU')->where('actor', 'claude')->exists());
    }

    public function test_invalid_iso_code_is_refused(): void
    {
        $result = $this->tool('upsert_country', ['code' => 'QZ', 'wave' => '1']);

        $this->assertTrue($result['error']);
        $this->assertSame(RuleViolation::INVALID_INPUT, $result['data']['reason']);
        $this->assertNull(Country::query()->find('QZ'));
    }

    public function test_claude_may_exclude_but_never_lift_an_exclusion(): void
    {
        $this->assertFalse($this->tool('upsert_country', ['code' => 'LU', 'excluded' => true, 'excluded_reason' => 'Test exclusion'])['error']);
        $this->assertTrue(Country::query()->find('LU')->excluded);

        $lift = $this->tool('upsert_country', ['code' => 'LU', 'excluded' => false]);
        $this->assertSame(RuleViolation::IRREVERSIBLE, $lift['data']['reason']);
        $this->assertTrue(Country::query()->find('LU')->excluded);

        // Only a viloyat advisor in the UI lifts it.
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/advisor/outreach/countries', ['code' => 'LU', 'excluded' => false])
            ->assertOk()->assertJsonPath('country.excluded', false);

        $this->actingAs($this->tuman(), 'sanctum')
            ->postJson('/api/advisor/outreach/countries', ['code' => 'LU', 'excluded' => true, 'excluded_reason' => 'x'])
            ->assertForbidden();
    }

    public function test_exclusion_needs_a_reason(): void
    {
        $this->assertTrue($this->tool('upsert_country', ['code' => 'LU', 'excluded' => true])['error']);
        $this->assertNull(Country::query()->find('LU'));
    }

    public function test_upsert_contacts_reports_per_item_and_costs_per_item(): void
    {
        $company = $this->company($this->owner);
        $missingCompanyId = '00000000-0000-4000-8000-000000000000'; // viloyat sees every lead, so use a non-existent one

        $result = $this->tool('upsert_contacts', ['items' => [
            ['company_id' => $company->id, 'full_name' => 'A', 'email' => 'a@bulk-contacts.test', 'email_status' => 'verified'],
            ['company_id' => $company->id, 'full_name' => 'B', 'email' => 'b@bulk-contacts.test'],
            ['company_id' => $company->id, 'full_name' => 'C', 'email' => 'c@bulk-contacts.test'],
            ['company_id' => $missingCompanyId, 'full_name' => 'D', 'email' => 'd@bulk-contacts.test'],
            ['company_id' => $company->id, 'full_name' => ''],
        ]]);

        $this->assertSame(2, $result['data']['ok']);
        $this->assertSame(3, $result['data']['failed']);
        $reasons = array_column(array_filter($result['data']['results'], fn ($r) => ! $r['ok']), 'reason');
        $this->assertSame(['contact_limit', 'not_found', 'invalid_input'], array_values($reasons));
        $this->assertSame(2, Contact::query()->where('company_id', $company->id)->count());

        config(['outreach.mcp.writes_per_minute' => 7]);   // 5 already used
        $tooMany = $this->tool('upsert_contacts', ['items' => array_fill(0, 3, ['company_id' => $company->id, 'full_name' => 'X'])]);
        $this->assertSame('write_rate_limited', $tooMany['data']['reason']);
    }

    public function test_iso_name_helper(): void
    {
        $this->assertSame('Germany', CountryService::isoName('DE'));
        $this->assertNull(CountryService::isoName('QZ'));
        $this->assertNull(CountryService::isoName('d1'));
    }
}
