<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor;

/**
 * KPI katalogi (Excel svodi — spec §7): 22 viloyat + 21 tuman ko'rsatkich,
 * scope filtri, shakl. Faqat faol (active) kodlar qaytadi.
 */
class KpiCatalogTest extends AdvisorTestCase
{
    public function test_catalog_returns_full_set_and_shape(): void
    {
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');

        $data = $this->actingAs($viloyat, 'sanctum')
            ->getJson('/api/advisor/kpis')
            ->assertOk()
            ->assertJsonStructure([['id', 'code', 'name', 'unit', 'scope']])
            ->json();

        $this->assertCount(43, $data);
        $this->assertSame(22, collect($data)->where('scope', 'viloyat')->count());
        $this->assertSame(21, collect($data)->where('scope', 'tuman')->count());

        // Barqaror Excel kodlari mavjud (frontend/seed tayanadi).
        $codes = collect($data)->pluck('code')->all();
        foreach (['v_x1', 'v_x4_1', 't_x1', 't_x9', 't_x10_1'] as $code) {
            $this->assertContains($code, $codes);
        }
    }

    public function test_scope_filter(): void
    {
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $this->someDistrictId());

        $data = $this->actingAs($tuman, 'sanctum')
            ->getJson('/api/advisor/kpis?scope=tuman')
            ->assertOk()
            ->json();

        $this->assertCount(21, $data);
        foreach ($data as $row) {
            $this->assertSame('tuman', $row['scope']);
        }
    }
}
