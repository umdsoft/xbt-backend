<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor;

/**
 * KPI xulosasi (viloyat) — tuman kesimida o'rtacha ijro %, kiritilgan/tasdiqlangan
 * soni (spec §7, §10).
 */
class KpiSummaryTest extends AdvisorTestCase
{
    public function test_summary_shape_and_aggregation(): void
    {
        $district = $this->someDistrictId();
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);

        $kpiA = $this->kpiIdByCode('t_x9');
        $kpiB = $this->kpiIdByCode('t_x10_1');
        $this->setKpiTarget($kpiA, $district, '2099-Q2', 100.0);
        $this->setKpiTarget($kpiB, $district, '2099-Q2', 100.0);

        // Ijro% 80 va 60 -> o'rtacha 70.
        $idA = $this->actingAs($tuman, 'sanctum')->postJson('/api/advisor/kpi/entries', [
            'kpi_id' => $kpiA, 'district_id' => $district, 'period' => '2099-Q2', 'value' => 80,
        ])->json('id');
        $this->actingAs($tuman, 'sanctum')->postJson('/api/advisor/kpi/entries', [
            'kpi_id' => $kpiB, 'district_id' => $district, 'period' => '2099-Q2', 'value' => 60,
        ])->assertCreated();

        // Bittasini tasdiqlaymiz (approved hisoblanadi).
        $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/kpi/entries/'.$idA.'/approve')->assertOk();

        $data = $this->actingAs($viloyat, 'sanctum')
            ->getJson('/api/advisor/kpi/summary?period=2099-Q2')
            ->assertOk()
            ->assertJsonStructure([['district' => ['id', 'name'], 'avg_fulfillment', 'entered', 'approved']])
            ->json();

        $row = collect($data)->firstWhere('district.id', $district);
        $this->assertNotNull($row);
        $this->assertSame(70.0, (float) $row['avg_fulfillment']);
        $this->assertSame(2, $row['entered']);
        $this->assertSame(1, $row['approved']);
    }
}
