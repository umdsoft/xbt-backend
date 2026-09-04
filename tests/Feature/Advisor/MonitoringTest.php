<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor;

use App\Models\User;

/**
 * «СВОД ЖАДВАЛЛАР» — svod yaratish (viloyat), tuman o'z satrини kiritish/yuborish,
 * og'irlikли UMUMIY TAYYORLIK, avto ijro holati, viloyat tasdiق/qaytariш.
 */
class MonitoringTest extends AdvisorTestCase
{
    /**
     * Viloyat 3 ustunли (20/35/45) svod yaratadi.
     *
     * @return array{0: string, 1: array<int, string>} [sheetId, [metricId...]]
     */
    private function makeSheet(User $viloyat): array
    {
        $id = $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/monitoring', [
            'title' => 'Синов свод',
            'category' => 'qaror',
            'metrics' => [
                ['name' => 'Обект', 'weight' => 20],
                ['name' => 'Тармоқ', 'weight' => 35],
                ['name' => 'Техник', 'weight' => 45],
            ],
        ])->assertCreated()->json('id');

        $metrics = $this->actingAs($viloyat, 'sanctum')->getJson("/api/advisor/monitoring/{$id}")
            ->assertOk()->json('metrics');

        return [$id, collect($metrics)->pluck('id')->all()];
    }

    public function test_viloyat_creates_sheet_with_weighted_metrics(): void
    {
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');
        [$sheetId, $metricIds] = $this->makeSheet($viloyat);

        $body = $this->actingAs($viloyat, 'sanctum')->getJson("/api/advisor/monitoring/{$sheetId}")
            ->assertOk()
            ->assertJsonStructure([
                'sheet' => ['id', 'title', 'category', 'completion_threshold'],
                'metrics' => [['id', 'name', 'weight']],
                'rows' => [['district' => ['id', 'name'], 'cells', 'overall', 'exec_status', 'review_status']],
                'totals' => ['per_metric', 'overall'],
            ])
            ->json();

        $this->assertCount(3, $body['metrics']);
        $this->assertCount(13, $body['rows']); // 13 tuman
        $this->assertSame(20.0, (float) $body['metrics'][0]['weight']);
    }

    public function test_tuman_enters_row_weighted_overall_and_auto_status(): void
    {
        $district = $this->someDistrictId();
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);
        [$sheetId, $m] = $this->makeSheet($viloyat);

        // Qiymatlar 100 / 10 / 0 (og'irlik 20/35/45) -> 100*.2 + 10*.35 + 0 = 23.5.
        $this->actingAs($tuman, 'sanctum')->postJson("/api/advisor/monitoring/{$sheetId}/entry", [
            'values' => [
                ['metric_id' => $m[0], 'value' => 100],
                ['metric_id' => $m[1], 'value' => 10],
                ['metric_id' => $m[2], 'value' => 0],
            ],
            'submit' => true,
        ])->assertOk();

        $rows = $this->actingAs($viloyat, 'sanctum')->getJson("/api/advisor/monitoring/{$sheetId}")
            ->assertOk()->json('rows');
        $mine = collect($rows)->firstWhere('district.id', $district);

        $this->assertSame(23.5, (float) $mine['overall']);
        $this->assertSame('in_progress', $mine['exec_status']);
        $this->assertSame('submitted', $mine['review_status']);
    }

    public function test_confirm_and_return_workflow(): void
    {
        $district = $this->someDistrictId();
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);
        [$sheetId, $m] = $this->makeSheet($viloyat);

        $this->actingAs($tuman, 'sanctum')->postJson("/api/advisor/monitoring/{$sheetId}/entry", [
            'values' => [['metric_id' => $m[0], 'value' => 100], ['metric_id' => $m[1], 'value' => 100], ['metric_id' => $m[2], 'value' => 100]],
            'submit' => true,
        ])->assertOk();

        // done (100% >= threshold 100).
        $rows = $this->actingAs($viloyat, 'sanctum')->getJson("/api/advisor/monitoring/{$sheetId}")->json('rows');
        $this->assertSame('done', collect($rows)->firstWhere('district.id', $district)['exec_status']);

        // Viloyat tasdiqlaydi.
        $this->actingAs($viloyat, 'sanctum')
            ->postJson("/api/advisor/monitoring/{$sheetId}/entries/{$district}/confirm")
            ->assertOk()->assertJsonPath('review_status', 'confirmed');

        // Viloyat qaytaradi (izoh bilan).
        $this->actingAs($viloyat, 'sanctum')
            ->postJson("/api/advisor/monitoring/{$sheetId}/entries/{$district}/return", ['comment' => 'Тузатинг'])
            ->assertOk()->assertJsonPath('review_status', 'returned');

        $this->assertDatabaseHas('monitoring_entries', [
            'sheet_id' => $sheetId, 'district_id' => $district, 'review_status' => 'returned',
        ], 'advisor');
    }

    public function test_stats_export_and_duplicate(): void
    {
        $district = $this->someDistrictId();
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);
        [$sheetId, $m] = $this->makeSheet($viloyat);

        $this->actingAs($tuman, 'sanctum')->postJson("/api/advisor/monitoring/{$sheetId}/entry", [
            'values' => [['metric_id' => $m[0], 'value' => 100], ['metric_id' => $m[1], 'value' => 100], ['metric_id' => $m[2], 'value' => 100]],
            'submit' => true,
        ])->assertOk();

        // Viloyat statistikasi — umumiy + tuman kesimi.
        $vstats = $this->actingAs($viloyat, 'sanctum')->getJson('/api/advisor/monitoring/stats')
            ->assertOk()
            ->assertJsonStructure(['role', 'sheets', 'avg_readiness', 'confirmation', 'per_district' => [['district' => ['id', 'name'], 'avg_readiness', 'confirmed']]])
            ->json();
        $this->assertGreaterThanOrEqual(1, $vstats['sheets']);

        // Tuman statistikasi — o'ziники (per_district yo'q).
        $this->actingAs($tuman, 'sanctum')->getJson('/api/advisor/monitoring/stats')
            ->assertOk()
            ->assertJsonPath('role', 'tuman')
            ->assertJsonMissingPath('per_district');

        // Excel eksport — xlsx binary.
        $this->actingAs($viloyat, 'sanctum')->get("/api/advisor/monitoring/{$sheetId}/export")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        // Nusxa (shablon) — ustunlar ko'chiriladi, satrlar YO'Q.
        $copyId = $this->actingAs($viloyat, 'sanctum')->postJson("/api/advisor/monitoring/{$sheetId}/duplicate")
            ->assertCreated()->json('id');
        $copy = $this->actingAs($viloyat, 'sanctum')->getJson("/api/advisor/monitoring/{$copyId}")->assertOk()->json();
        $this->assertCount(3, $copy['metrics']);
        // Nusxada tasdiqланган/kiritilган satr yo'q (barcha review_status draft).
        $this->assertEmpty(collect($copy['rows'])->where('review_status', 'confirmed')->all());
    }

    public function test_access_rules(): void
    {
        $district = $this->someDistrictId();
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);
        $bolinma = $this->makeAdvisor('advisor_bolinma', 'bolinma');
        [$sheetId, $m] = $this->makeSheet($viloyat);

        // Tuman/bo'linма svod yarata olmaydi.
        $payload = ['title' => 'X', 'metrics' => [['name' => 'A', 'weight' => 100]]];
        $this->actingAs($tuman, 'sanctum')->postJson('/api/advisor/monitoring', $payload)->assertForbidden();
        $this->actingAs($bolinma, 'sanctum')->postJson('/api/advisor/monitoring', $payload)->assertForbidden();

        // Viloyat/bo'линма satr KIRITА olmaydi (faqat tuman).
        $entry = ['values' => [['metric_id' => $m[0], 'value' => 50]]];
        $this->actingAs($viloyat, 'sanctum')->postJson("/api/advisor/monitoring/{$sheetId}/entry", $entry)->assertForbidden();
        $this->actingAs($bolinma, 'sanctum')->postJson("/api/advisor/monitoring/{$sheetId}/entry", $entry)->assertForbidden();

        // Bo'линма/tuman tasdiqлай olmaydi.
        $this->actingAs($bolinma, 'sanctum')->postJson("/api/advisor/monitoring/{$sheetId}/entries/{$district}/confirm")->assertForbidden();
        $this->actingAs($tuman, 'sanctum')->postJson("/api/advisor/monitoring/{$sheetId}/entries/{$district}/confirm")->assertForbidden();

        // Barcha rol svodни KO'RADI (shaffoflik).
        foreach ([$viloyat, $tuman, $bolinma] as $u) {
            $this->actingAs($u, 'sanctum')->getJson("/api/advisor/monitoring/{$sheetId}")->assertOk();
        }
    }
}
