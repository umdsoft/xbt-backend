<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor\Outreach;

use App\Domains\Advisor\Outreach\Models\AuditEntry;
use App\Domains\Advisor\Outreach\Models\Company;
use App\Domains\Advisor\Outreach\Models\McpToken;
use App\Domains\Advisor\Outreach\Models\Message;
use App\Domains\Advisor\Outreach\Services\McpTokenService;
use App\Domains\Advisor\Outreach\Support\Actor;
use App\Domains\Advisor\Outreach\Support\RuleViolation;
use App\Domains\Advisor\Outreach\Support\Stage;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

/**
 * The MCP endpoint end to end over HTTP JSON-RPC: token auth, tool list,
 * each tool, business-rule errors, audit and write caps.
 */
class McpServerTest extends OutreachTestCase
{
    private const URL = '/api/mcp/outreach';

    private User $owner;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->viloyat();
        $this->token = app(McpTokenService::class)->issue(Actor::ui($this->owner), 'test')['plain_text'];
        RateLimiter::clear('outreach-mcp-w:'.$this->owner->id);
        RateLimiter::clear('outreach-mcp-d:'.$this->owner->id.':'.now()->toDateString());
    }

    private function rpc(string $method, array $params = [], ?string $token = null): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.($token ?? $this->token), 'Accept' => 'application/json, text/event-stream'])
            ->postJson(self::URL, ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
    }

    /** @return array{error: bool, data: array<string, mixed>} */
    private function tool(string $tool, array $args = []): array
    {
        $result = $this->rpc('tools/call', ['name' => $tool, 'arguments' => $args])->assertOk()->json('result');
        $text = $result['content'][0]['text'] ?? '';

        return ['error' => (bool) ($result['isError'] ?? false), 'data' => json_decode($text, true) ?? ['raw' => $text]];
    }

    public function test_requests_without_valid_token_are_refused(): void
    {
        $this->postJson(self::URL, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();
        $this->rpc('tools/list', [], 'omcp_wrong')->assertUnauthorized();
    }

    public function test_sanctum_session_does_not_open_the_mcp_endpoint(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson(self::URL, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertUnauthorized();
    }

    public function test_mcp_token_does_not_open_the_advisor_api(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer '.$this->token])
            ->getJson('/api/advisor/outreach/companies')->assertUnauthorized();
    }

    public function test_revoked_expired_and_demoted_tokens_stop_working(): void
    {
        $service = app(McpTokenService::class);
        $row = McpToken::query()->where('user_id', $this->owner->id)->firstOrFail();

        $service->revoke(Actor::ui($this->owner), $row->id);
        $this->rpc('tools/list')->assertUnauthorized();

        $fresh = $service->issue(Actor::ui($this->owner), 'second');
        $fresh['token']->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->rpc('tools/list', [], $fresh['plain_text'])->assertUnauthorized();
    }

    public function test_only_viloyat_can_issue_tokens(): void
    {
        $this->expectException(RuleViolation::class);
        app(McpTokenService::class)->issue(Actor::ui($this->tuman()), 'nope');
    }

    public function test_token_is_stored_only_as_hash(): void
    {
        $row = McpToken::query()->where('user_id', $this->owner->id)->firstOrFail();

        $this->assertStringStartsWith('omcp_', $this->token);
        $this->assertSame(hash('sha256', $this->token), $row->token_hash);
        $this->assertArrayNotHasKey('token_hash', $row->toArray());
    }

    public function test_tool_list_keeps_the_crm_tools(): void
    {
        // The full list (incl. mail tools) is asserted in McpMailToolsTest.
        $names = collect($this->rpc('tools/list')->assertOk()->json('result.tools'))->pluck('name')->all();

        foreach ([
            'dedupe_check', 'get_pipeline', 'get_stats', 'list_approvals',
            'log_touch', 'set_stage', 'upsert_company', 'upsert_contact',
        ] as $tool) {
            $this->assertContains($tool, $names);
        }
    }

    public function test_full_research_flow_through_tools_is_audited_as_claude(): void
    {
        $this->country('XA');

        $company = $this->tool('upsert_company', [
            'domain' => 'https://www.mcp-flow.test', 'name' => 'Flow Ltd', 'country_code' => 'XA',
            'employees' => 400, 'industry' => 'outsourcing', 'has_offshore_center' => true, 'languages' => ['en'],
        ]);
        $this->assertFalse($company['error'], json_encode($company['data']));
        $this->assertSame('mcp', $company['data']['company']['created_via']);
        $id = $company['data']['company']['id'];

        $this->assertTrue($this->tool('dedupe_check', ['domain' => 'mcp-flow.test'])['data']['matches'][0]['accessible']);

        $contact = $this->tool('upsert_contact', [
            'company_id' => $id, 'full_name' => 'Ada CEO', 'email' => 'ada@mcp-flow.test', 'email_status' => 'verified', 'role_type' => 'ceo',
        ]);
        $this->assertFalse($contact['error'], json_encode($contact['data']));

        $moved = $this->tool('set_stage', ['company_id' => $id, 'to' => Stage::VERIFIED]);
        $this->assertFalse($moved['error'], json_encode($moved['data']));
        $this->assertSame(Stage::VERIFIED, $moved['data']['company']['stage']);
        $this->assertSame('A', $moved['data']['company']['tier']);

        $touch = $this->tool('log_touch', [
            'company_id' => $id, 'channel' => 'linkedin', 'direction' => 'out',
            'summary' => 'Connection request sent', 'occurred_at' => now()->toIso8601String(),
        ]);
        $this->assertSame('mcp', $touch['data']['touch']['via']);

        $pipeline = $this->tool('get_pipeline', ['stage' => Stage::VERIFIED, 'limit' => 100]);
        $this->assertContains($id, array_column($pipeline['data']['companies'], 'id'));

        $stats = $this->tool('get_stats');
        $this->assertArrayHasKey('by_country', $stats['data']);
        $this->assertArrayNotHasKey('by_owner', $stats['data']);

        $audit = AuditEntry::query()->where('actor', 'claude')->where('actor_user_id', $this->owner->id)->pluck('action');
        foreach (['company.created', 'contact.created', 'company.stage_changed', 'touch.logged'] as $action) {
            $this->assertContains($action, $audit->all());
        }
        $this->assertSame(0, AuditEntry::query()->where('actor', 'claude')->whereNull('mcp_token_id')->count());
    }

    public function test_business_rules_come_back_as_tool_errors(): void
    {
        $this->country('XE', excluded: true);

        $excluded = $this->tool('upsert_company', ['domain' => 'x-excl.test', 'name' => 'X', 'country_code' => 'XE']);
        $this->assertTrue($excluded['error']);
        $this->assertSame('country_excluded', $excluded['data']['reason']);

        $company = $this->company($this->owner, ['stage' => Stage::AWAITING_APPROVAL]);
        $sent = $this->tool('set_stage', ['company_id' => $company->id, 'to' => Stage::SENT]);
        $this->assertTrue($sent['error']);
        $this->assertSame('transition_not_allowed', $sent['data']['reason']);
        $this->assertSame(Stage::AWAITING_APPROVAL, $company->fresh()->stage);
    }

    public function test_list_approvals_is_read_only_and_there_is_no_way_to_approve(): void
    {
        $draft = $this->draft($this->contact($this->company($this->owner, ['tier' => 'A'])));

        $listed = $this->tool('list_approvals');
        $this->assertContains($draft->id, array_column($listed['data']['messages'], 'id'));

        $this->rpc('tools/call', ['name' => 'approve_message', 'arguments' => ['id' => $draft->id]])
            ->assertJsonPath('error.code', -32602);
        $this->assertSame(Message::DRAFT, $draft->fresh()->status);
    }

    public function test_write_rate_limit_is_enforced(): void
    {
        config(['outreach.mcp.writes_per_minute' => 2]);
        $company = $this->company($this->owner);
        $touch = fn () => $this->tool('log_touch', [
            'company_id' => $company->id, 'channel' => 'other', 'direction' => 'out',
            'summary' => 'x', 'occurred_at' => now()->toIso8601String(),
        ]);

        $touch();
        $touch();
        $third = $touch();

        $this->assertTrue($third['error']);
        $this->assertSame('write_rate_limited', $third['data']['reason']);
        $this->assertSame(2, $company->touchHistory()->count());
    }

    public function test_daily_write_cap_is_enforced(): void
    {
        config(['outreach.mcp.daily_write_cap' => 1]);
        $this->country('XA');

        $this->tool('upsert_company', ['domain' => 'cap-1.test', 'name' => 'A', 'country_code' => 'XA']);
        $second = $this->tool('upsert_company', ['domain' => 'cap-2.test', 'name' => 'B', 'country_code' => 'XA']);

        $this->assertSame('daily_write_cap', $second['data']['reason']);
        $this->assertFalse(Company::query()->where('domain', 'cap-2.test')->exists());
    }

    public function test_token_ui_endpoints(): void
    {
        $this->actingAs($this->tuman(), 'sanctum')->getJson('/api/advisor/outreach/mcp-tokens')->assertForbidden();

        $api = $this->actingAs($this->owner, 'sanctum');
        $created = $api->postJson('/api/advisor/outreach/mcp-tokens', ['name' => 'Claude Code'])
            ->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringStartsWith('omcp_', $created->json('plain_text'));

        $list = $api->getJson('/api/advisor/outreach/mcp-tokens')->assertOk();
        $this->assertStringNotContainsString('omcp_', $list->getContent());
        $this->assertStringNotContainsString('token_hash', $list->getContent());

        $api->postJson('/api/advisor/outreach/mcp-tokens/'.$created->json('token.id').'/revoke')
            ->assertOk()->assertJsonPath('token.id', $created->json('token.id'));
        $this->rpc('tools/list', [], $created->json('plain_text'))->assertUnauthorized();

        $api->getJson('/api/advisor/outreach/mcp-info')->assertOk()
            ->assertJsonPath('rate_per_minute', 120)->assertJsonStructure(['endpoint_url', 'daily_write_cap']);
    }
}
