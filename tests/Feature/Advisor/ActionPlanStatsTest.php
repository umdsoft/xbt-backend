<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor;

use App\Domains\Advisor\Models\ActionPlan;
use App\Domains\Advisor\Models\ActionPlanItem;

/**
 * CHORA-TADBIR STATISTIKASI — har band = bitta topshiriq. Tuman FAQAT o'z tumani
 * yig'masini; viloyat/bo'linма umumiy + tuman kesimi (leaderboard).
 */
class ActionPlanStatsTest extends AdvisorTestCase
{
    private function makeItem(string $scope = 'all_districts', ?string $deadline = null): ActionPlanItem
    {
        $plan = ActionPlan::firstOrCreate(['year' => 2099], ['title' => 'Синов режа', 'status' => 'active']);

        return ActionPlanItem::create([
            'plan_id' => $plan->id,
            'section_title' => 'I. Синов бўлими',
            'item_number' => (string) random_int(1, 9999),
            'title' => 'Синов банди',
            'scope' => $scope,
            'deadline' => $deadline,
            'sort_order' => 10,
        ]);
    }

    public function test_tuman_stats_count_only_own_bands(): void
    {
        $district = $this->someDistrictId();
        $item = $this->makeItem('all_districts');
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);

        $this->actingAs($tuman, 'sanctum')
            ->postJson("/api/advisor/action-plan/items/{$item->id}/progress", [
                'status' => 'completed', 'progress_percent' => 100,
            ])->assertOk();

        $overall = $this->actingAs($tuman, 'sanctum')
            ->getJson('/api/advisor/action-plan/stats')
            ->assertOk()
            ->assertJsonPath('role', 'tuman')
            ->assertJsonStructure(['overall' => ['total', 'completed', 'in_progress', 'not_started', 'overdue', 'avg_progress', 'completion']])
            ->json('overall');

        // Har band = 1 topshiriq (tuman uchun). Kamida 1 band, 1 bajarilган.
        $this->assertGreaterThanOrEqual(1, $overall['total']);
        $this->assertGreaterThanOrEqual(1, $overall['completed']);
        // Tuman kesimi (per_district) tumanга QAЙТАРИЛМАЙДИ.
        $this->actingAs($tuman, 'sanctum')->getJson('/api/advisor/action-plan/stats')
            ->assertJsonMissingPath('per_district');
    }

    public function test_viloyat_stats_overall_and_per_district(): void
    {
        $district = $this->someDistrictId();
        $item = $this->makeItem('all_districts');
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');

        $this->actingAs($tuman, 'sanctum')
            ->postJson("/api/advisor/action-plan/items/{$item->id}/progress", [
                'status' => 'completed', 'progress_percent' => 100,
            ])->assertOk();

        $body = $this->actingAs($viloyat, 'sanctum')
            ->getJson('/api/advisor/action-plan/stats')
            ->assertOk()
            ->assertJsonStructure([
                'overall' => ['total', 'completed', 'overdue', 'avg_progress', 'completion'],
                'per_district' => [['district' => ['id', 'name'], 'total', 'completed', 'completion']],
                'bands' => ['all_districts', 'viloyat'],
            ])
            ->json();

        // Har all_districts band = 13 topshiriq (13 tuman). Kamida bitta band bor,
        // shu bois total >= 13; per_district DOIM 13 tuman (dev bazada seed bo'lиши mumkin).
        $this->assertGreaterThanOrEqual(13, $body['overall']['total']);
        $this->assertGreaterThanOrEqual(1, $body['overall']['completed']);
        $this->assertCount(13, $body['per_district']);

        // Bajarган tuman per_district'да completed >= 1.
        $mine = collect($body['per_district'])->firstWhere('district.id', $district);
        $this->assertNotNull($mine);
        $this->assertGreaterThanOrEqual(1, $mine['completed']);
    }
}
