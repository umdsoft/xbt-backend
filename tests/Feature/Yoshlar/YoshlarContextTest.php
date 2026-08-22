<?php

declare(strict_types=1);

namespace Tests\Feature\Yoshlar;

use App\Domains\Yoshlar\Models\Organization;
use App\Domains\Yoshlar\Models\Youth;

class YoshlarContextTest extends YoshlarTestCase
{
    public function test_context_returns_reference_data(): void
    {
        $response = $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->getJson('/api/yoshlar/context')
            ->assertOk()
            ->assertJsonStructure([
                'reference' => [
                    'districts', 'mahallas', 'sectors', 'organizations',
                    'education_statuses', 'employment_statuses', 'roles',
                ],
                'badges' => ['pending_youth', 'task_queue', 'tasks_overdue'],
            ]);

        $this->assertNotEmpty($response->json('reference.districts'));
        $this->assertNotEmpty($response->json('reference.mahallas'));
    }

    public function test_pending_badge_counts_only_own_scope(): void
    {
        $own = $this->someDistrictId();
        $other = $this->otherDistrictId($own);

        $this->pendingYouth($own);
        $this->pendingYouth($other);

        $user = $this->makeUser('yoshlar_bolim', $this->makeOrganization(
            Organization::TYPE_TUMAN_YOSHLAR, ['district_id' => $own],
        )->id);

        $badge = $this->actingAs($user, 'sanctum')
            ->getJson('/api/yoshlar/context')->assertOk()->json('badges.pending_youth');

        $this->assertSame(1, $badge);
    }

    public function test_stats_endpoint_returns_district_breakdown(): void
    {
        $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->getJson('/api/yoshlar/youth/stats')
            ->assertOk()
            ->assertJsonStructure(['total', 'neet', 'pending', 'by_district']);
    }

    public function test_stats_route_is_not_swallowed_by_show_route(): void
    {
        // `/youth/stats` `{youth}` dan oldin turishi kerak — aks holda 404.
        $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->getJson('/api/yoshlar/youth/stats')
            ->assertOk()
            ->assertJsonMissingPath('data');
    }

    private function pendingYouth(string $districtId): Youth
    {
        return Youth::query()->create([
            'last_name' => 'Navbat', 'first_name' => 'Yosh',
            'birth_date' => now()->subYears(17)->toDateString(), 'gender' => 'erkak',
            'district_id' => $districtId, 'mahalla_id' => $this->someMahallaId($districtId),
            'verification_status' => 'pending',
        ]);
    }
}
