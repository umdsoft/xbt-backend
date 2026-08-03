<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor;

use App\Domains\Advisor\Models\ActionPlan;
use App\Domains\Advisor\Models\ActionPlanItem;

/**
 * CHORA-TADBIRLAR — bajarilishi: tuman o'z tumaniga kiritadi (boshqa tuman berса
 * ham majburan o'zi), viloyat istalgan tuman + summary ko'radi, overdue belgisi.
 */
class ActionPlanProgressTest extends AdvisorTestCase
{
    private function makeItem(string $scope = 'all_districts', ?string $deadline = null): ActionPlanItem
    {
        $plan = ActionPlan::firstOrCreate(
            ['year' => 2099],
            ['title' => 'Синов режа', 'status' => 'active'],
        );

        return ActionPlanItem::create([
            'plan_id' => $plan->id,
            'section_title' => 'I. Синов бўлими',
            'item_number' => '1',
            'title' => 'Синов банди',
            'scope' => $scope,
            'deadline' => $deadline,
            'sort_order' => 10,
        ]);
    }

    public function test_tuman_updates_own_progress_and_sees_it(): void
    {
        $district = $this->someDistrictId();
        $item = $this->makeItem();
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);

        $this->actingAs($tuman, 'sanctum')
            ->postJson("/api/advisor/action-plan/items/{$item->id}/progress", [
                'status' => 'in_progress', 'report' => 'Бошландик', 'progress_percent' => 40,
            ])
            ->assertOk()->assertJsonPath('ok', true);

        // Tafsilotда my_progress ko'rinadi.
        $this->actingAs($tuman, 'sanctum')
            ->getJson("/api/advisor/action-plan/{$item->plan_id}")
            ->assertOk()
            ->assertJsonPath('sections.0.items.0.my_progress.status', 'in_progress')
            ->assertJsonPath('sections.0.items.0.my_progress.progress_percent', 40)
            ->assertJsonPath('sections.0.items.0.summary', null);
    }

    public function test_tuman_cannot_write_other_district(): void
    {
        $district = $this->someDistrictId();
        $other = $this->anotherDistrictId($district);
        $item = $this->makeItem();
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);

        // Boshqa tuman district_id yuboradi — majburan O'Z tumaniga yoziladi.
        $this->actingAs($tuman, 'sanctum')
            ->postJson("/api/advisor/action-plan/items/{$item->id}/progress", [
                'district_id' => $other, 'status' => 'completed',
            ])->assertOk();

        $this->assertDatabaseHas('action_plan_progress', [
            'item_id' => $item->id, 'district_id' => $district, 'status' => 'completed',
        ], 'advisor');
        $this->assertDatabaseMissing('action_plan_progress', [
            'item_id' => $item->id, 'district_id' => $other,
        ], 'advisor');
    }

    public function test_viloyat_sees_summary_and_edits_any_district(): void
    {
        $district = $this->someDistrictId();
        $item = $this->makeItem();
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');

        $this->actingAs($viloyat, 'sanctum')
            ->postJson("/api/advisor/action-plan/items/{$item->id}/progress", [
                'district_id' => $district, 'status' => 'completed', 'progress_percent' => 100,
            ])->assertOk();

        $summary = $this->actingAs($viloyat, 'sanctum')
            ->getJson("/api/advisor/action-plan/{$item->plan_id}")
            ->assertOk()
            ->assertJsonPath('sections.0.items.0.my_progress', null)
            ->json('sections.0.items.0.summary');

        $this->assertNotNull($summary);
        $this->assertGreaterThanOrEqual(1, $summary['completed']);
        $this->assertGreaterThanOrEqual($summary['completed'], $summary['total']);

        // Band tafsiloti — tuman kesimi qatorlari (bir nechta tuman).
        $rows = $this->actingAs($viloyat, 'sanctum')
            ->getJson("/api/advisor/action-plan/items/{$item->id}")
            ->assertOk()->json('rows');
        $this->assertGreaterThan(1, count($rows));
    }

    public function test_viloyat_all_districts_item_requires_district(): void
    {
        $item = $this->makeItem('all_districts');
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');

        $this->actingAs($viloyat, 'sanctum')
            ->postJson("/api/advisor/action-plan/items/{$item->id}/progress", [
                'status' => 'completed',
            ])->assertStatus(422);
    }

    public function test_overdue_flag_for_tuman(): void
    {
        $district = $this->someDistrictId();
        $item = $this->makeItem('all_districts', '2020-01-01'); // o'tган muddat
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);

        $this->actingAs($tuman, 'sanctum')
            ->getJson("/api/advisor/action-plan/{$item->plan_id}")
            ->assertOk()
            ->assertJsonPath('sections.0.items.0.overdue', true);
    }
}
