<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor;

use App\Domains\Advisor\Models\ActionPlan;
use App\Domains\Advisor\Models\ActionPlanItem;

/**
 * CHORA-TADBIR STATISTIKASI (arxiv modeli): birlik = (band × tuman). «Kiritilган»
 * = kamida bitta jurnal yozuvi. Tuman o'z bandlari; viloyat umumiy + tuman kesimi.
 */
class ActionPlanStatsTest extends AdvisorTestCase
{
    private function makeItem(): ActionPlanItem
    {
        $plan = ActionPlan::firstOrCreate(['year' => 2099], ['title' => 'Синов режа', 'status' => 'active']);

        return ActionPlanItem::create([
            'plan_id' => $plan->id,
            'section_title' => 'I. Синов бўлими',
            'item_number' => (string) random_int(1, 9999),
            'title' => 'Синов банди',
            'scope' => 'all_districts',
            'sort_order' => 10,
        ]);
    }

    public function test_tuman_stats_reported_only_own(): void
    {
        $district = $this->someDistrictId();
        $item = $this->makeItem();
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);

        $this->actingAs($tuman, 'sanctum')
            ->postJson("/api/advisor/action-plan/items/{$item->id}/entries", ['report' => 'Бажарилди', 'progress_percent' => 80])
            ->assertCreated();

        $overall = $this->actingAs($tuman, 'sanctum')
            ->getJson('/api/advisor/action-plan/stats')
            ->assertOk()
            ->assertJsonPath('role', 'tuman')
            ->assertJsonStructure(['overall' => ['total', 'reported', 'not_reported', 'overdue', 'avg_progress', 'reported_pct']])
            ->json('overall');

        $this->assertGreaterThanOrEqual(1, $overall['total']);
        $this->assertGreaterThanOrEqual(1, $overall['reported']);
        $this->actingAs($tuman, 'sanctum')->getJson('/api/advisor/action-plan/stats')->assertJsonMissingPath('per_district');
    }

    public function test_viloyat_stats_overall_and_per_district(): void
    {
        $district = $this->someDistrictId();
        $item = $this->makeItem();
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');

        $this->actingAs($tuman, 'sanctum')
            ->postJson("/api/advisor/action-plan/items/{$item->id}/entries", ['report' => 'Бажарилди', 'progress_percent' => 100])
            ->assertCreated();

        $body = $this->actingAs($viloyat, 'sanctum')
            ->getJson('/api/advisor/action-plan/stats')
            ->assertOk()
            ->assertJsonStructure([
                'overall' => ['total', 'reported', 'overdue', 'avg_progress', 'reported_pct'],
                'per_district' => [['district' => ['id', 'name'], 'total', 'reported', 'reported_pct']],
            ])
            ->json();

        $this->assertGreaterThanOrEqual(13, $body['overall']['total']); // 1 band × 13 tuman
        $this->assertGreaterThanOrEqual(1, $body['overall']['reported']);
        $this->assertCount(13, $body['per_district']);

        $mine = collect($body['per_district'])->firstWhere('district.id', $district);
        $this->assertNotNull($mine);
        $this->assertGreaterThanOrEqual(1, $mine['reported']);
    }
}
