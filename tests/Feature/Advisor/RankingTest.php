<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor;

/**
 * Reyting (spec §8): viloyat hisoblaydi (score + rank), hamma ko'radi; tuman
 * hisoblay olmaydi.
 */
class RankingTest extends AdvisorTestCase
{
    public function test_viloyat_computes_ranking_and_orders_by_score(): void
    {
        $mine = $this->someDistrictId();
        $other = $this->anotherDistrictId($mine);
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');
        $tumanMine = $this->makeAdvisor('advisor_tuman', 'tuman', $mine);
        $tumanOther = $this->makeAdvisor('advisor_tuman', 'tuman', $other);

        $kpi = $this->kpiIdByCode('t_x9');
        $this->setKpiTarget($kpi, $mine, '2099-Q2', 100.0);
        $this->setKpiTarget($kpi, $other, '2099-Q2', 100.0);

        // mine ijro% 90 > other ijro% 50 -> mine 1-o'rin.
        $this->actingAs($tumanMine, 'sanctum')->postJson('/api/advisor/kpi/entries', [
            'kpi_id' => $kpi, 'district_id' => $mine, 'period' => '2099-Q2', 'value' => 90,
        ])->assertCreated();
        $this->actingAs($tumanOther, 'sanctum')->postJson('/api/advisor/kpi/entries', [
            'kpi_id' => $kpi, 'district_id' => $other, 'period' => '2099-Q2', 'value' => 50,
        ])->assertCreated();

        $computed = $this->actingAs($viloyat, 'sanctum')
            ->postJson('/api/advisor/rankings/compute', ['period' => '2099-Q2'])
            ->assertOk()
            ->json('rankings');

        $this->assertCount(2, $computed);

        // GET reyting shakli + tartib.
        $data = $this->actingAs($viloyat, 'sanctum')
            ->getJson('/api/advisor/rankings?period=2099-Q2')
            ->assertOk()
            ->assertJsonStructure([['district' => ['id', 'name'], 'score', 'rank']])
            ->json();

        $byDistrict = collect($data)->keyBy('district.id');
        $this->assertSame(1, $byDistrict[$mine]['rank']);
        $this->assertSame(2, $byDistrict[$other]['rank']);
        $this->assertGreaterThan($byDistrict[$other]['score'], $byDistrict[$mine]['score']);
    }

    public function test_recompute_replaces_previous_ranking(): void
    {
        $mine = $this->someDistrictId();
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $mine);
        $kpi = $this->kpiIdByCode('t_x9');
        $this->setKpiTarget($kpi, $mine, '2099-Q2', 100.0);

        $this->actingAs($tuman, 'sanctum')->postJson('/api/advisor/kpi/entries', [
            'kpi_id' => $kpi, 'district_id' => $mine, 'period' => '2099-Q2', 'value' => 50,
        ])->assertCreated();

        $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/rankings/compute', ['period' => '2099-Q2'])->assertOk();
        $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/rankings/compute', ['period' => '2099-Q2'])->assertOk();

        // Qayta hisoblansa dubl bo'lmaydi (period+district unique).
        $data = $this->actingAs($viloyat, 'sanctum')->getJson('/api/advisor/rankings?period=2099-Q2')->json();
        $this->assertCount(1, $data);
    }

    public function test_tuman_can_view_but_not_compute(): void
    {
        $district = $this->someDistrictId();
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);

        $this->actingAs($tuman, 'sanctum')->getJson('/api/advisor/rankings?period=2099-Q2')->assertOk();
        $this->actingAs($tuman, 'sanctum')->postJson('/api/advisor/rankings/compute', ['period' => '2099-Q2'])->assertForbidden();
    }
}
