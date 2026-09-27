<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor\Outreach;

use App\Domains\Advisor\Outreach\Models\Company;
use App\Domains\Advisor\Outreach\Models\Contact;
use App\Domains\Advisor\Outreach\Services\IcpScorer;

class IcpScorerTest extends OutreachTestCase
{
    private function score(array $company, array $contacts = []): array
    {
        return app(IcpScorer::class)->score(
            new Company($company),
            array_map(static fn (array $c): Contact => new Contact($c + ['do_not_contact' => false]), $contacts),
        );
    }

    public function test_perfect_fit_scores_100_and_tier_a(): void
    {
        $result = $this->score([
            'employees' => 300, 'has_offshore_center' => true, 'open_roles_6m' => 25,
            'client_regions' => ['us'], 'industry' => 'outsourcing', 'languages' => ['ru'],
        ], [['email' => 'ceo@a.test', 'email_status' => 'verified']]);

        $this->assertSame(100, $result['score']);
        $this->assertSame('A', $result['tier']);
    }

    public function test_empty_company_scores_zero_and_tier_c(): void
    {
        $result = $this->score([]);

        $this->assertSame(0, $result['score']);
        $this->assertSame('C', $result['tier']);
    }

    public function test_partial_bands(): void
    {
        $result = $this->score([
            'employees' => 40, 'open_roles_6m' => 6, 'industry' => 'software',
        ], [['email' => 'x@a.test', 'email_status' => 'catch_all']]);

        // 7 (employees 30-49) + 8 (roles 5-19) + 5 (general software) + 10 (catch_all)
        $this->assertSame(30, $result['score']);
    }

    public function test_tier_boundaries(): void
    {
        $scorer = app(IcpScorer::class);

        $this->assertSame('A', $scorer->tier(70));
        $this->assertSame('B', $scorer->tier(69));
        $this->assertSame('B', $scorer->tier(50));
        $this->assertSame('C', $scorer->tier(49));
    }

    public function test_inactive_contacts_do_not_count(): void
    {
        $result = $this->score([], [
            ['email' => 'a@a.test', 'email_status' => 'verified', 'do_not_contact' => true],
        ]);

        $this->assertSame(0, $result['breakdown']['decision_maker_email']);
    }

    public function test_sanctions_hit_zeroes_the_score(): void
    {
        $result = $this->score(['employees' => 300, 'industry' => 'outsourcing', 'sanctions_status' => 'hit']);

        $this->assertSame(0, $result['score']);
        $this->assertSame('C', $result['tier']);
    }
}
