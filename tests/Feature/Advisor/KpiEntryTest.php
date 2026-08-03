<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor;

use Illuminate\Support\Facades\DB;

/**
 * KPI kiritish + ijro% (value/target) + tasdiq (spec §7).
 */
class KpiEntryTest extends AdvisorTestCase
{
    public function test_tuman_enters_value_and_fulfillment_is_computed(): void
    {
        $district = $this->someDistrictId();
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);
        $kpi = $this->kpiIdByCode('t_x9');
        $this->setKpiTarget($kpi, $district, '2099-Q2', 100.0);

        $id = $this->actingAs($tuman, 'sanctum')
            ->postJson('/api/advisor/kpi/entries', [
                'kpi_id' => $kpi,
                'district_id' => $district,
                'period' => '2099-Q2',
                'value' => 80,
                'note' => 'Сўров натижаси',
            ])
            ->assertCreated()
            ->assertJson(['status' => 'submitted'])
            ->json('id');

        $this->assertNotNull($id);

        // Kiritilgan qiymatlar shakli + ijro% = 80/100*100 = 80.
        $rows = $this->actingAs($tuman, 'sanctum')
            ->getJson('/api/advisor/kpi/entries?period=2099-Q2')
            ->assertOk()
            ->assertJsonStructure([[
                'id', 'kpi' => ['id', 'name', 'unit'], 'district' => ['id', 'name'],
                'period', 'target', 'value', 'fulfillment', 'status', 'source',
            ]])
            ->json();

        $row = collect($rows)->firstWhere('id', $id);
        $this->assertNotNull($row);
        // JSON butun sonli float'ni int qilib qaytaradi — float'ga keltirib solishtiramiz.
        $this->assertSame(100.0, (float) $row['target']);
        $this->assertSame(80.0, (float) $row['value']);
        $this->assertSame(80.0, (float) $row['fulfillment']);
        $this->assertSame('manual', $row['source']);
        $this->assertSame('submitted', $row['status']);
        $this->assertSame($district, $row['district']['id']);
    }

    public function test_reentry_updates_value_and_resets_to_submitted(): void
    {
        $district = $this->someDistrictId();
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');
        $kpi = $this->kpiIdByCode('t_x9');

        $id = $this->actingAs($tuman, 'sanctum')->postJson('/api/advisor/kpi/entries', [
            'kpi_id' => $kpi, 'district_id' => $district, 'period' => '2099-Q2', 'value' => 40,
        ])->json('id');

        // Viloyat tasdiqlaydi.
        $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/kpi/entries/'.$id.'/approve')
            ->assertOk()->assertJson(['status' => 'approved']);
        $this->assertSame('approved', DB::connection('advisor')->table('kpi_entries')->where('id', $id)->value('status'));

        // Qayta kiritish — o'sha yozuv (bir kpi/district/period), qiymat yangilanadi,
        // status 'submitted'ga qaytadi (qayta tasdiq talab).
        $id2 = $this->actingAs($tuman, 'sanctum')->postJson('/api/advisor/kpi/entries', [
            'kpi_id' => $kpi, 'district_id' => $district, 'period' => '2099-Q2', 'value' => 55,
        ])->json('id');

        $this->assertSame($id, $id2);
        $this->assertSame(1, DB::connection('advisor')->table('kpi_entries')
            ->where('kpi_id', $kpi)->where('district_id', $district)->where('period', '2099-Q2')->count());
        $this->assertSame('submitted', DB::connection('advisor')->table('kpi_entries')->where('id', $id)->value('status'));
        $this->assertSame('55.00', (string) DB::connection('advisor')->table('kpi_entries')->where('id', $id)->value('value'));
    }

    public function test_viloyat_enters_viloyat_kpi_with_null_district(): void
    {
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');
        $kpi = $this->kpiIdByCode('v_x1');

        $id = $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/kpi/entries', [
            'kpi_id' => $kpi, 'period' => '2099-Q2', 'value' => 12,
        ])->assertCreated()->json('id');

        $this->assertNull(DB::connection('advisor')->table('kpi_entries')->where('id', $id)->value('district_id'));

        // Ro'yxatda district null (viloyat-daraja).
        $row = collect($this->actingAs($viloyat, 'sanctum')->getJson('/api/advisor/kpi/entries?period=2099-Q2')->json())
            ->firstWhere('id', $id);
        $this->assertNull($row['district']);
    }

    public function test_fulfillment_null_when_no_target(): void
    {
        $district = $this->someDistrictId();
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);
        $kpi = $this->kpiIdByCode('t_x5');

        $id = $this->actingAs($tuman, 'sanctum')->postJson('/api/advisor/kpi/entries', [
            'kpi_id' => $kpi, 'district_id' => $district, 'period' => '2099-Q2', 'value' => 7,
        ])->json('id');

        $row = collect($this->actingAs($tuman, 'sanctum')->getJson('/api/advisor/kpi/entries?period=2099-Q2')->json())
            ->firstWhere('id', $id);
        $this->assertNull($row['target']);
        $this->assertNull($row['fulfillment']);
    }
}
