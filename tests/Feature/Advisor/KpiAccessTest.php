<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor;

/**
 * KPI rol-scoping va IDOR chegaralari (spec §2, §7).
 *
 * tuman: o'z tumani entrisini kiritadi/ko'radi; boshqa tuman/viloyat-KPI YO'Q;
 *        tasdiq/derivatsiya YO'Q. viloyat: hammasi + tasdiq + derivatsiya.
 */
class KpiAccessTest extends AdvisorTestCase
{
    public function test_unauthenticated_401_and_non_advisor_403(): void
    {
        $this->getJson('/api/advisor/kpis')->assertUnauthorized();

        $this->actingAs($this->makeOutsider(), 'sanctum')
            ->getJson('/api/advisor/kpis')->assertForbidden();
    }

    public function test_tuman_cannot_enter_for_other_district(): void
    {
        $mine = $this->someDistrictId();
        $other = $this->anotherDistrictId($mine);
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $mine);
        $kpi = $this->kpiIdByCode('t_x9');

        $this->actingAs($tuman, 'sanctum')->postJson('/api/advisor/kpi/entries', [
            'kpi_id' => $kpi, 'district_id' => $mine, 'period' => '2099-Q2', 'value' => 50,
        ])->assertCreated();

        $this->actingAs($tuman, 'sanctum')->postJson('/api/advisor/kpi/entries', [
            'kpi_id' => $kpi, 'district_id' => $other, 'period' => '2099-Q2', 'value' => 50,
        ])->assertForbidden();
    }

    public function test_tuman_cannot_enter_viloyat_kpi(): void
    {
        $district = $this->someDistrictId();
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);
        $kpi = $this->kpiIdByCode('v_x1');

        $this->actingAs($tuman, 'sanctum')->postJson('/api/advisor/kpi/entries', [
            'kpi_id' => $kpi, 'district_id' => $district, 'period' => '2099-Q2', 'value' => 3,
        ])->assertForbidden();
    }

    public function test_tuman_sees_only_own_district_entries(): void
    {
        $mine = $this->someDistrictId();
        $other = $this->anotherDistrictId($mine);
        $tumanMine = $this->makeAdvisor('advisor_tuman', 'tuman', $mine);
        $tumanOther = $this->makeAdvisor('advisor_tuman', 'tuman', $other);
        $kpi = $this->kpiIdByCode('t_x9');

        $mineId = $this->actingAs($tumanMine, 'sanctum')->postJson('/api/advisor/kpi/entries', [
            'kpi_id' => $kpi, 'district_id' => $mine, 'period' => '2099-Q2', 'value' => 60,
        ])->json('id');
        $otherId = $this->actingAs($tumanOther, 'sanctum')->postJson('/api/advisor/kpi/entries', [
            'kpi_id' => $kpi, 'district_id' => $other, 'period' => '2099-Q2', 'value' => 70,
        ])->json('id');

        $ids = collect($this->actingAs($tumanMine, 'sanctum')->getJson('/api/advisor/kpi/entries?period=2099-Q2')->json())
            ->pluck('id')->all();

        $this->assertContains($mineId, $ids);
        $this->assertNotContains($otherId, $ids);
    }

    public function test_tuman_cannot_approve_or_derive_or_compute(): void
    {
        $district = $this->someDistrictId();
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);
        $kpi = $this->kpiIdByCode('t_x9');

        $id = $this->actingAs($tuman, 'sanctum')->postJson('/api/advisor/kpi/entries', [
            'kpi_id' => $kpi, 'district_id' => $district, 'period' => '2099-Q2', 'value' => 50,
        ])->json('id');

        $this->actingAs($tuman, 'sanctum')->postJson('/api/advisor/kpi/entries/'.$id.'/approve')->assertForbidden();
        $this->actingAs($tuman, 'sanctum')->postJson('/api/advisor/rankings/compute', ['period' => '2099-Q2'])->assertForbidden();
        // Xulosa ham viloyat/bo'linma huquqi.
        $this->actingAs($tuman, 'sanctum')->getJson('/api/advisor/kpi/summary?period=2099-Q2')->assertForbidden();
    }

    public function test_bolinma_can_enter_and_view_but_not_approve(): void
    {
        $district = $this->someDistrictId();
        $bolinma = $this->makeAdvisor('advisor_bolinma', 'bolinma');
        $kpi = $this->kpiIdByCode('t_x9');

        // Bo'linma barcha tuman uchun kirita oladi (agregatsiya — spec §7).
        $id = $this->actingAs($bolinma, 'sanctum')->postJson('/api/advisor/kpi/entries', [
            'kpi_id' => $kpi, 'district_id' => $district, 'period' => '2099-Q2', 'value' => 65,
        ])->assertCreated()->json('id');

        $this->actingAs($bolinma, 'sanctum')->getJson('/api/advisor/kpi/summary?period=2099-Q2')->assertOk();

        // Tasdiqlay OLMAYDI (viloyat huquqi).
        $this->actingAs($bolinma, 'sanctum')->postJson('/api/advisor/kpi/entries/'.$id.'/approve')->assertForbidden();
    }
}
