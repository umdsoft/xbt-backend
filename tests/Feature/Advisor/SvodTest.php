<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor;

use App\Domains\Advisor\Services\SvodService;

/**
 * Yuqori idoraга svod eksport (spec §1, §10) — tuman × [topshiriq ijro %, KPI
 * o'rtacha %, reyting o'rni, loyiha soni] xlsx. FAQAT viloyat/bo'linma.
 */
class SvodTest extends AdvisorTestCase
{
    public function test_viloyat_downloads_svod_xlsx(): void
    {
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');

        $response = $this->actingAs($viloyat, 'sanctum')->get('/api/advisor/export/svod?period=2026-Q2');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('advisor-svod-2026-Q2.xlsx', (string) $response->headers->get('Content-Disposition'));

        // Haqiqiy xlsx (ZIP) — PK imzosi bilan boshlanadi.
        $this->assertStringStartsWith('PK', (string) $response->getContent());
    }

    public function test_bolinma_can_export_but_tuman_cannot(): void
    {
        $district = $this->someDistrictId();
        $bolinma = $this->makeAdvisor('advisor_bolinma', 'bolinma');
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);

        $this->actingAs($bolinma, 'sanctum')->get('/api/advisor/export/svod?period=2026-Q2')->assertOk();
        $this->actingAs($tuman, 'sanctum')->get('/api/advisor/export/svod?period=2026-Q2')->assertForbidden();
    }

    public function test_svod_rows_cover_all_districts_with_project_counts(): void
    {
        $district = $this->someDistrictId();
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');

        // Bitta loyiha -> shu tuman satrida loyiha soni 1.
        $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/projects', [
            'district_id' => $district,
            'title' => 'Свод лойиҳаси',
        ])->assertCreated();

        $rows = app(SvodService::class)->rows('2026-Q2');

        // Barcha 13 tuman satri (kamida shuncha).
        $this->assertGreaterThanOrEqual(13, count($rows));

        $districtName = (string) \Illuminate\Support\Facades\DB::connection('master')
            ->table('districts')->where('id', $district)->value('name_cyr');
        $row = collect($rows)->firstWhere(0, $districtName);

        $this->assertNotNull($row);
        // 0=Туман, 1=Топшириқ ижро %, 2=KPI ўртача, 3=Рейтинг, 4=Лойиҳа сони.
        // Loyiha soni ustuni to'ldirilgan va yaratilган loyiha(lar)ни qamraydi
        // (svod loyihани davrsiz sanaydi; seed namunasi ham qo'shilishi mumkin).
        $this->assertGreaterThanOrEqual(1, (int) $row[4]);
    }
}
