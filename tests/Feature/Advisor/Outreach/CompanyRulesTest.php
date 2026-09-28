<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor\Outreach;

use App\Domains\Advisor\Outreach\Models\AuditEntry;
use App\Domains\Advisor\Outreach\Models\Company;
use App\Domains\Advisor\Outreach\Services\CompanyService;
use App\Domains\Advisor\Outreach\Services\DedupeService;
use App\Domains\Advisor\Outreach\Support\Actor;
use App\Domains\Advisor\Outreach\Support\RuleViolation;
use App\Domains\Advisor\Outreach\Support\Stage;

/**
 * Excluded country, dedupe by domain, ownership and score recomputation.
 */
class CompanyRulesTest extends OutreachTestCase
{
    private function upsert(Actor $actor, array $data): array
    {
        return app(CompanyService::class)->upsert($actor, $data);
    }

    private function assertViolation(string $reason, callable $fn): RuleViolation
    {
        try {
            $fn();
        } catch (RuleViolation $e) {
            $this->assertSame($reason, $e->reason, $e->getMessage());

            return $e;
        }
        $this->fail("Expected RuleViolation({$reason})");
    }

    public function test_creates_company_with_normalized_domain_owner_and_score(): void
    {
        $user = $this->tuman();
        $this->country('XA');

        $result = $this->upsert(Actor::mcp($user, '00000000-0000-0000-0000-00000000000a'), [
            'name' => 'Acme', 'domain' => 'https://WWW.Acme-Test.com/about', 'country_code' => 'xa',
            'employees' => 300, 'industry' => 'outsourcing',
        ]);

        $company = $result['company'];
        $this->assertTrue($result['created']);
        $this->assertSame('acme-test.com', $company->domain);
        $this->assertSame('XA', $company->country_code);
        $this->assertSame($user->id, $company->owner_user_id);
        $this->assertSame(Stage::FOUND, $company->stage);
        $this->assertSame('mcp', $company->created_via);
        $this->assertSame(30, $company->icp_score);
        $this->assertTrue(AuditEntry::query()->where('entity_id', $company->id)->where('action', 'company.created')->where('actor', 'claude')->exists());
    }

    public function test_excluded_country_is_rejected_with_reason(): void
    {
        $this->country('XE', excluded: true);

        $e = $this->assertViolation(RuleViolation::COUNTRY_EXCLUDED, fn () => $this->upsert(
            Actor::ui($this->viloyat()),
            ['name' => 'Bad', 'domain' => 'bad-co.test', 'country_code' => 'XE'],
        ));

        $this->assertSame('Test exclusion', $e->context['excluded_reason']);
        $this->assertSame(422, $e->httpStatus());
        $this->assertFalse(Company::query()->where('domain', 'bad-co.test')->exists());
    }

    public function test_unknown_country_is_rejected(): void
    {
        $this->assertViolation(RuleViolation::COUNTRY_UNKNOWN, fn () => $this->upsert(
            Actor::ui($this->viloyat()),
            ['name' => 'X', 'domain' => 'nocountry.test', 'country_code' => 'XZ'],
        ));
    }

    public function test_same_owner_upsert_updates_instead_of_duplicating(): void
    {
        $user = $this->tuman();
        $this->country('XA');
        $actor = Actor::ui($user);

        $this->upsert($actor, ['name' => 'Acme', 'domain' => 'acme-dup.test', 'country_code' => 'XA']);
        $again = $this->upsert($actor, ['domain' => 'www.ACME-dup.test', 'employees' => 100]);

        $this->assertFalse($again['created']);
        $this->assertSame(100, $again['company']->employees);
        $this->assertSame(1, Company::query()->where('domain', 'acme-dup.test')->count());
    }

    public function test_other_advisors_domain_is_duplicate_with_owner_name_only(): void
    {
        $owner = $this->tuman();
        $other = $this->tuman();
        $this->country('XA');
        $this->upsert(Actor::ui($owner), ['name' => 'Acme', 'domain' => 'taken.test', 'country_code' => 'XA']);

        $e = $this->assertViolation(RuleViolation::DUPLICATE, fn () => $this->upsert(
            Actor::ui($other),
            ['name' => 'Acme', 'domain' => 'taken.test', 'country_code' => 'XA', 'employees' => 1],
        ));

        $this->assertSame(['accessible' => false, 'owner_name' => $owner->name], $e->context);
        $this->assertNull(Company::query()->where('domain', 'taken.test')->value('employees'));
    }

    public function test_dedupe_check_hides_foreign_lead_details(): void
    {
        $owner = $this->tuman();
        $other = $this->tuman();
        $company = $this->company($owner, ['domain' => 'seen.test']);
        $this->contact($company, ['email' => 'boss@seen.test']);

        $foreign = app(DedupeService::class)->check(Actor::ui($other), 'https://seen.test', 'BOSS@seen.test');
        $own = app(DedupeService::class)->check(Actor::ui($owner), 'seen.test', null);

        $this->assertCount(2, $foreign['matches']);
        $this->assertFalse($foreign['matches'][0]['accessible']);
        $this->assertArrayNotHasKey('company_id', $foreign['matches'][0]);
        $this->assertSame($company->id, $own['matches'][0]['company_id']);
    }

    public function test_viloyat_may_update_any_lead(): void
    {
        $owner = $this->tuman();
        $this->country('XA');
        $this->upsert(Actor::ui($owner), ['name' => 'Acme', 'domain' => 'shared.test', 'country_code' => 'XA']);

        $result = $this->upsert(Actor::ui($this->viloyat()), ['domain' => 'shared.test', 'employees' => 60]);

        $this->assertSame(60, $result['company']->employees);
        $this->assertSame($owner->id, $result['company']->owner_user_id);
    }

    public function test_input_cannot_set_score_stage_or_owner(): void
    {
        $user = $this->tuman();
        $this->country('XA');

        $company = $this->upsert(Actor::ui($user), [
            'name' => 'Acme', 'domain' => 'sneaky.test', 'country_code' => 'XA',
            'icp_score' => 99, 'tier' => 'A', 'stage' => Stage::SENT, 'owner_user_id' => 'someone',
        ])['company'];

        $this->assertSame(0, $company->icp_score);
        $this->assertSame('C', $company->tier);
        $this->assertSame(Stage::FOUND, $company->stage);
        $this->assertSame($user->id, $company->owner_user_id);
    }

    public function test_sanctions_hit_blocks_lead_and_is_irreversible(): void
    {
        $actor = Actor::ui($this->viloyat());
        $this->country('XA');
        $this->upsert($actor, ['name' => 'Acme', 'domain' => 'sanction.test', 'country_code' => 'XA']);

        $company = $this->upsert($actor, ['domain' => 'sanction.test', 'sanctions_status' => 'hit'])['company'];
        $this->assertSame(Stage::BLOCKED_SANCTIONS, $company->stage);

        $this->assertViolation(RuleViolation::IRREVERSIBLE, fn () => $this->upsert(
            $actor, ['domain' => 'sanction.test', 'sanctions_status' => 'clear'],
        ));
    }

    public function test_bolinma_cannot_write_leads(): void
    {
        $this->country('XA');

        $this->assertViolation(RuleViolation::FORBIDDEN, fn () => $this->upsert(
            Actor::ui($this->bolinma()),
            ['name' => 'Acme', 'domain' => 'bolinma.test', 'country_code' => 'XA'],
        ));
    }

    public function test_invalid_domain_is_rejected(): void
    {
        $this->assertViolation(RuleViolation::INVALID_DOMAIN, fn () => $this->upsert(
            Actor::ui($this->viloyat()),
            ['name' => 'Acme', 'domain' => 'not a domain', 'country_code' => 'XA'],
        ));
    }

    public function test_only_viloyat_reassigns_owner(): void
    {
        $owner = $this->tuman();
        $next = $this->tuman();
        $company = $this->company($owner);
        $service = app(CompanyService::class);

        $this->assertViolation(RuleViolation::FORBIDDEN, fn () => $service->reassignOwner(Actor::ui($owner), $company->id, $next->id));

        $service->reassignOwner(Actor::ui($this->viloyat()), $company->id, $next->id);

        $this->assertSame($next->id, $company->fresh()->owner_user_id);
        $this->assertTrue(AuditEntry::query()->where('entity_id', $company->id)->where('action', 'company.owner_changed')->exists());
    }
}
