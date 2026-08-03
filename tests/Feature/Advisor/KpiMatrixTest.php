<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor;

/**
 * KPI TO'LIQ matritsa (viloyat/bo'linma) — tuman KPIlari × 13 tuman, har katakда
 * Reja (target) + Bajarilish (value) + ijro%. Faqat seesAllDistricts.
 */
class KpiMatrixTest extends AdvisorTestCase
{
    public function test_viloyat_matrix_has_target_value_and_fulfillment(): void
    {
        $district = $this->someDistrictId();
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);

        $kpi = $this->kpiIdByCode('t_x9'); // tuman-scope % ko'rsatkich
        $this->setKpiTarget($kpi, $district, '2099-Q2', 100.0);
        $this->actingAs($tuman, 'sanctum')->postJson('/api/advisor/kpi/entries', [
            'kpi_id' => $kpi, 'district_id' => $district, 'period' => '2099-Q2', 'value' => 80,
        ])->assertCreated();

        $data = $this->actingAs($viloyat, 'sanctum')
            ->getJson('/api/advisor/kpi/matrix?period=2099-Q2')
            ->assertOk()
            ->assertJsonStructure([
                'districts' => [['id', 'name']],
                'rows' => [['kpi' => ['id', 'code', 'name', 'unit'], 'cells' => [['district_id', 'target', 'value', 'fulfillment']]]],
            ])
            ->json();

        // 13 tuman ustuni + tuman KPIlari qatori.
        $this->assertGreaterThanOrEqual(13, count($data['districts']));

        $row = collect($data['rows'])->firstWhere('kpi.id', $kpi);
        $this->assertNotNull($row);
        $cell = collect($row['cells'])->firstWhere('district_id', $district);
        $this->assertNotNull($cell);
        $this->assertSame(100.0, (float) $cell['target']);   // Reja
        $this->assertSame(80.0, (float) $cell['value']);     // Bajarilish
        $this->assertSame(80.0, (float) $cell['fulfillment']); // ijro%
    }

    public function test_tuman_cannot_access_matrix(): void
    {
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $this->someDistrictId());

        $this->actingAs($tuman, 'sanctum')
            ->getJson('/api/advisor/kpi/matrix?period=2099-Q2')->assertForbidden();
    }

    public function test_viloyat_enters_value_and_target_and_tuman_sees_year(): void
    {
        $district = $this->someDistrictId();
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);
        $kpi = $this->kpiIdByCode('t_x9');

        // VILOYAT natija (value) + reja (target) ni birga kiritadi.
        $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/kpi/entries', [
            'kpi_id' => $kpi, 'district_id' => $district, 'period' => '2099-Q2', 'value' => 80, 'target' => 100,
        ])->assertCreated();

        // TUMAN o'z tumanini butun yil bo'yicha ko'radi (4 chorak, tanlashsiz).
        $data = $this->actingAs($tuman, 'sanctum')
            ->getJson('/api/advisor/kpi/year?year=2099')
            ->assertOk()
            ->assertJsonStructure([
                'year', 'quarters', 'district_id',
                'rows' => [['kpi' => ['id', 'code', 'name', 'unit'], 'cells']],
            ])
            ->json();

        $this->assertCount(4, $data['quarters']);
        $this->assertSame($district, $data['district_id']);

        $row = collect($data['rows'])->firstWhere('kpi.id', $kpi);
        $this->assertNotNull($row);
        // Q2 — viloyat kiritган reja/natija/ijro%.
        $this->assertSame(100.0, (float) $row['cells']['2099-Q2']['target']);
        $this->assertSame(80.0, (float) $row['cells']['2099-Q2']['value']);
        $this->assertSame(80.0, (float) $row['cells']['2099-Q2']['fulfillment']);
        // Q1 — ma'lumot yo'q.
        $this->assertNull($row['cells']['2099-Q1']['value']);
    }

    public function test_tuman_year_forced_to_own_district(): void
    {
        $mine = $this->someDistrictId();
        $other = $this->anotherDistrictId($mine);
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $mine);

        // Boshqa tuman so'ralса ham — o'z tumani qaytadi (IDOR himoyasi).
        $data = $this->actingAs($tuman, 'sanctum')
            ->getJson('/api/advisor/kpi/year?year=2099&district_id='.$other)
            ->assertOk()->json();

        $this->assertSame($mine, $data['district_id']);
    }
}
