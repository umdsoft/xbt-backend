<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor;

use App\Domains\Advisor\Models\ActionPlan;
use App\Domains\Advisor\Models\ActionPlanItem;

/**
 * CHORA-TADBIR bajarilishi — JURNAL (arxiv) modeli: tuman ma'lumot QO'SHADI
 * («bajarildi» deb tasdiqlamaydi); davriy uchun bir nechta yozuv; viloyat monitoring;
 * yozuvni FAQAT egаси tahrirlaydi/o'chiradi. Viloyat tuman ma'lumotини kirита OLMAYDI.
 */
class ActionPlanProgressTest extends AdvisorTestCase
{
    private function makeItem(string $scope = 'all_districts', ?string $deadline = null): ActionPlanItem
    {
        $plan = ActionPlan::firstOrCreate(['year' => 2099], ['title' => 'Синов режа', 'status' => 'active']);

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

    public function test_tuman_adds_journal_entries_periodic(): void
    {
        $district = $this->someDistrictId();
        $item = $this->makeItem();
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);

        // 1-yozuv.
        $this->actingAs($tuman, 'sanctum')
            ->postJson("/api/advisor/action-plan/items/{$item->id}/entries", [
                'report' => 'Биринчи босқич бажарилди', 'progress_percent' => 40, 'occurred_at' => '2026-08-01',
            ])->assertCreated();

        // 2-yozuv (davriy).
        $this->actingAs($tuman, 'sanctum')
            ->postJson("/api/advisor/action-plan/items/{$item->id}/entries", [
                'report' => 'Иккинчи босқич', 'progress_percent' => 70, 'occurred_at' => '2026-09-01',
            ])->assertCreated();

        // Tafsilotда my_progress: kiritilган, oxirgi 70%, 2 yozuv.
        $this->actingAs($tuman, 'sanctum')
            ->getJson("/api/advisor/action-plan/{$item->plan_id}")
            ->assertOk()
            ->assertJsonPath('sections.0.items.0.my_progress.reported', true)
            ->assertJsonPath('sections.0.items.0.my_progress.progress_percent', 70)
            ->assertJsonPath('sections.0.items.0.my_progress.count', 2)
            ->assertJsonPath('sections.0.items.0.summary', null);

        // Arxiv — 2 yozuv (yangi -> eski).
        $this->actingAs($tuman, 'sanctum')
            ->getJson("/api/advisor/action-plan/items/{$item->id}/archive")
            ->assertOk()
            ->assertJsonCount(2, 'entries')
            ->assertJsonPath('entries.0.progress_percent', 70);
    }

    public function test_viloyat_monitors_but_cannot_enter_tuman_data(): void
    {
        $district = $this->someDistrictId();
        $item = $this->makeItem();
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');

        $this->actingAs($tuman, 'sanctum')
            ->postJson("/api/advisor/action-plan/items/{$item->id}/entries", ['report' => 'Бажарилди', 'progress_percent' => 100])
            ->assertCreated();

        // Viloyat summary + tuman kesimini KO'RADI.
        $summary = $this->actingAs($viloyat, 'sanctum')
            ->getJson("/api/advisor/action-plan/{$item->plan_id}")
            ->assertOk()->json('sections.0.items.0.summary');
        $this->assertNotNull($summary);
        $this->assertGreaterThanOrEqual(1, $summary['reported']);

        // LEKIN viloyat all_districts bandга ma'lumot KIRИТА ОЛМАЙДИ (403).
        $this->actingAs($viloyat, 'sanctum')
            ->postJson("/api/advisor/action-plan/items/{$item->id}/entries", ['report' => 'x'])
            ->assertStatus(403);
    }

    public function test_viloyat_enters_only_viloyat_scope_band(): void
    {
        $viloyatItem = $this->makeItem('viloyat');
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');

        $this->actingAs($viloyat, 'sanctum')
            ->postJson("/api/advisor/action-plan/items/{$viloyatItem->id}/entries", ['report' => 'Вилоят даражаси', 'progress_percent' => 50])
            ->assertCreated();

        $this->assertDatabaseHas('action_plan_entries', [
            'item_id' => $viloyatItem->id, 'district_id' => null,
        ], 'advisor');
    }

    public function test_only_author_edits_or_deletes_entry(): void
    {
        $district = $this->someDistrictId();
        $item = $this->makeItem();
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');

        $entryId = $this->actingAs($tuman, 'sanctum')
            ->postJson("/api/advisor/action-plan/items/{$item->id}/entries", ['report' => 'Дастлабки'])
            ->assertCreated()->json('id');

        // Egаси tahrirlaydi.
        $this->actingAs($tuman, 'sanctum')
            ->patchJson("/api/advisor/action-plan/entries/{$entryId}", ['report' => 'Тузатилди'])
            ->assertOk();
        $this->assertDatabaseHas('action_plan_entries', ['id' => $entryId, 'report' => 'Тузатилди'], 'advisor');

        // Viloyat (egаси emas) tahrirлай/o'chира OLMAYDI.
        $this->actingAs($viloyat, 'sanctum')->patchJson("/api/advisor/action-plan/entries/{$entryId}", ['report' => 'x'])->assertStatus(403);
        $this->actingAs($viloyat, 'sanctum')->deleteJson("/api/advisor/action-plan/entries/{$entryId}")->assertStatus(403);

        // Egаси o'chiradi.
        $this->actingAs($tuman, 'sanctum')->deleteJson("/api/advisor/action-plan/entries/{$entryId}")->assertOk();
        $this->assertDatabaseMissing('action_plan_entries', ['id' => $entryId], 'advisor');
    }

    public function test_overdue_when_deadline_passed_and_not_reported(): void
    {
        $district = $this->someDistrictId();
        $item = $this->makeItem('all_districts', '2020-01-01');
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);

        $this->actingAs($tuman, 'sanctum')
            ->getJson("/api/advisor/action-plan/{$item->plan_id}")
            ->assertOk()
            ->assertJsonPath('sections.0.items.0.overdue', true);
    }
}
