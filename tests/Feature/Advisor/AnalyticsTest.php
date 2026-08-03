<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor;

/**
 * Boshqaruv paneli TAHLILI (grafik) — butun yil kesimi. Rolга qarab qamrov:
 * viloyat reyting grafigi ko'radi; tuman FAQAT o'z tumani (reytingsiz).
 */
class AnalyticsTest extends AdvisorTestCase
{
    public function test_viloyat_analytics_has_trend_distributions_and_ranking(): void
    {
        $district = $this->someDistrictId();
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');

        $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/tasks', [
            'title' => 'Таҳлил учун топшириқ',
            'district_ids' => [$district],
        ])->assertCreated();

        $body = $this->actingAs($viloyat, 'sanctum')
            ->getJson('/api/advisor/dashboard/analytics?year=2026')
            ->assertOk()
            ->assertJsonStructure([
                'year',
                'kpi_trend' => [['period', 'label', 'value']],
                'task_status' => [['key', 'label', 'value', 'tone']],
                'project_status' => [['key', 'label', 'value', 'tone']],
                'ranking',
            ])
            ->json();

        $this->assertSame(2026, $body['year']);
        $this->assertCount(4, $body['kpi_trend']); // 4 chorak
        $this->assertSame('2026-Q1', $body['kpi_trend'][0]['period']);

        // Faol topshiriq taqsimotда aks etadi (>=1).
        $active = collect($body['task_status'])->firstWhere('key', 'active')['value'];
        $this->assertGreaterThanOrEqual(1, (int) $active);
    }

    public function test_tuman_analytics_is_self_scoped_without_ranking(): void
    {
        $district = $this->someDistrictId();
        $other = $this->anotherDistrictId($district);
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);

        // Boshqa tumanга topshiriq — tuman tahlilida ko'rinmasligi kerak.
        $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/tasks', [
            'title' => 'Бошқа туман топшириғи',
            'district_ids' => [$other],
        ])->assertCreated();

        $body = $this->actingAs($tuman, 'sanctum')
            ->getJson('/api/advisor/dashboard/analytics')
            ->assertOk()
            ->assertJsonStructure([
                'kpi_trend' => [['period', 'label', 'value']],
                'task_status',
                'project_status',
            ])
            ->json();

        // Tuman uchun reyting grafigi qaytarilmaydi.
        $this->assertArrayNotHasKey('ranking', $body);

        // O'z tumaniда topshiriq yo'q -> faol 0 (boshqa tuman topshirig'i sanalmaydi).
        $active = collect($body['task_status'])->firstWhere('key', 'active')['value'];
        $this->assertSame(0, (int) $active);
    }
}
