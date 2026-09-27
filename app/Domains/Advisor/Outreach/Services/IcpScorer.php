<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Services;

use App\Domains\Advisor\Outreach\Models\Company;
use App\Domains\Advisor\Outreach\Models\Contact;

/**
 * ICP score (0-100) and tier A/B/C (SPEC §4.2, rules in PLAN-crm.md §6).
 *
 * Pure calculation: reads the company and its contacts, returns the result.
 * CompanyService persists it; the score is never taken from input.
 */
final class IcpScorer
{
    public const TARGET_INDUSTRIES = ['outsourcing', 'bpo_kpo', 'logistics_dispatch', 'agro_it', 'gamedev'];

    public const GENERAL_INDUSTRY = 'software';

    public const TARGET_LANGUAGES = ['ru', 'tr', 'en'];

    public const TARGET_CLIENT_REGIONS = ['us', 'eu'];

    public const TIER_A_MIN = 70;

    public const TIER_B_MIN = 50;

    /**
     * @param  iterable<Contact>  $contacts
     * @return array{score:int, tier:string, breakdown:array<string,int>}
     */
    public function score(Company $company, iterable $contacts): array
    {
        if ($company->sanctions_status === 'hit') {
            // Sanctions stop the lead regardless of fit.
            return ['score' => 0, 'tier' => 'C', 'breakdown' => ['sanctions_hit' => 0]];
        }

        $breakdown = [
            'employees' => $this->employees($company->employees),
            'offshore_center' => $company->has_offshore_center === true ? 15 : 0,
            'open_roles' => $this->openRoles($company->open_roles_6m),
            'client_regions' => $this->intersects($company->client_regions, self::TARGET_CLIENT_REGIONS) ? 10 : 0,
            'industry' => $this->industry($company->industry),
            'languages' => $this->intersects($company->languages, self::TARGET_LANGUAGES) ? 10 : 0,
            'decision_maker_email' => $this->decisionMakerEmail($contacts),
        ];

        $score = array_sum($breakdown);

        return ['score' => $score, 'tier' => $this->tier($score), 'breakdown' => $breakdown];
    }

    public function tier(int $score): string
    {
        return match (true) {
            $score >= self::TIER_A_MIN => 'A',
            $score >= self::TIER_B_MIN => 'B',
            default => 'C',
        };
    }

    private function employees(?int $n): int
    {
        return match (true) {
            $n === null => 0,
            $n >= 50 && $n <= 2000 => 15,
            ($n >= 30 && $n <= 49) || ($n >= 2001 && $n <= 5000) => 7,
            default => 0,
        };
    }

    private function openRoles(?int $n): int
    {
        return match (true) {
            $n === null || $n <= 0 => 0,
            $n >= 20 => 15,
            $n >= 5 => 8,
            default => 3,
        };
    }

    private function industry(?string $industry): int
    {
        return match (true) {
            in_array($industry, self::TARGET_INDUSTRIES, true) => 15,
            $industry === self::GENERAL_INDUSTRY => 5,
            default => 0,
        };
    }

    /** @param  iterable<Contact>  $contacts */
    private function decisionMakerEmail(iterable $contacts): int
    {
        $best = 0;
        foreach ($contacts as $contact) {
            if (! $contact->isActive() || $contact->email === null) {
                continue;
            }
            $best = max($best, match ($contact->email_status) {
                'verified' => 20,
                'catch_all' => 10,
                default => 0,
            });
        }

        return $best;
    }

    /**
     * @param  array<int, string>|null  $values
     * @param  array<int, string>  $targets
     */
    private function intersects(?array $values, array $targets): bool
    {
        return $values !== null && array_intersect(array_map('strtolower', $values), $targets) !== [];
    }
}
