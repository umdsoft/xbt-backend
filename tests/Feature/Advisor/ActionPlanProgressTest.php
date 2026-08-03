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

    public function test_viloyat_monitors_tuman_but_cannot_edit(): void
    {
        $district = $this->someDistrictId();
        $item = $this->makeItem();
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');

        // Tuman O'ZI kiritadi.
        $this->actingAs($tuman, 'sanctum')
            ->postJson("/api/advisor/action-plan/items/{$item->id}/progress", [
                'status' => 'completed', 'progress_percent' => 100,
            ])->assertOk();

        // Viloyat summary + tuman kesimini KO'RADI (qaysi tuman kiritганини).
        $summary = $this->actingAs($viloyat, 'sanctum')
            ->getJson("/api/advisor/action-plan/{$item->plan_id}")
            ->assertOk()
            ->assertJsonPath('sections.0.items.0.my_progress', null)
            ->json('sections.0.items.0.summary');
        $this->assertNotNull($summary);
        $this->assertGreaterThanOrEqual(1, $summary['completed']);

        $rows = $this->actingAs($viloyat, 'sanctum')
            ->getJson("/api/advisor/action-plan/items/{$item->id}")
            ->assertOk()->json('rows');
        $this->assertGreaterThan(1, count($rows));

        // LEKIN viloyat TUMAN bajarilишini O'ZГАРТИРА ОЛМАЙДИ (403 — faqat monitoring).
        $this->actingAs($viloyat, 'sanctum')
            ->postJson("/api/advisor/action-plan/items/{$item->id}/progress", [
                'district_id' => $district, 'status' => 'not_started', 'progress_percent' => 0,
            ])->assertStatus(403);

        // Tuman kiritган qiymat o'zгармаган.
        $this->assertDatabaseHas('action_plan_progress', [
            'item_id' => $item->id, 'district_id' => $district, 'status' => 'completed',
        ], 'advisor');
    }

    public function test_viloyat_can_edit_only_viloyat_scope_band(): void
    {
        $viloyatItem = $this->makeItem('viloyat');
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');

        // Viloyat-darajасидаги band — viloyat kiritadi (district null).
        $this->actingAs($viloyat, 'sanctum')
            ->postJson("/api/advisor/action-plan/items/{$viloyatItem->id}/progress", [
                'status' => 'completed', 'progress_percent' => 100,
            ])->assertOk();

        $this->assertDatabaseHas('action_plan_progress', [
            'item_id' => $viloyatItem->id, 'district_id' => null, 'status' => 'completed',
        ], 'advisor');

        // all_districts (tuman) band — viloyat kирита ОЛМАЙДИ (403).
        $allItem = ActionPlanItem::create([
            'plan_id' => $viloyatItem->plan_id, 'section_title' => 'I. Синов бўлими',
            'item_number' => '2', 'title' => 'Туман банди', 'scope' => 'all_districts', 'sort_order' => 20,
        ]);
        $this->actingAs($viloyat, 'sanctum')
            ->postJson("/api/advisor/action-plan/items/{$allItem->id}/progress", [
                'district_id' => $this->someDistrictId(), 'status' => 'completed',
            ])->assertStatus(403);
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
