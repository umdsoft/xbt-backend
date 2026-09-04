<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Database\Seeders;

use App\Domains\Advisor\Models\KpiEntry;
use App\Domains\Advisor\Models\KpiTarget;
use App\Domains\Advisor\Services\KpiService;
use App\Domains\Advisor\Support\KpiCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * KPI REAL MA'LUMOT seed — Xorazm 2026 svodi (Excel namunasi) asosida:
 *   - Reja (kpi_targets): Q1..Q4 (mavjud bo'lganlari).
 *   - Bajarilish (kpi_entries): Q1, Q2 (real natija; status=approved, source=manual).
 *
 * Viloyat ko'rsatkichlari district_id=null; tuman ko'rsatkichlari master.districts
 * (soato_code bo'yicha). Kod: v_x{raqam}/t_x{raqam} (KpiCatalog). Idempotent
 * (updateOrCreate kpi_id+district_id+period). Faqat PostgreSQL. KpiCatalog::seed
 * dan KEYIN chaqiriladi (kpis to'ldirilgan bo'lishi shart).
 */
class KpiDataSeeder extends Seeder
{
    public function run(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $data = KpiCatalog::data();
        if (($data['viloyat'] ?? null) === null && ($data['districts'] ?? []) === []) {
            return;
        }

        // code -> kpi_id (Excel kodlari).
        $kpiByCode = DB::connection('advisor')->table('kpis')->pluck('id', 'code');
        // soato_code -> district_id.
        $districtBySoato = DB::connection('master')->table('districts')
            ->whereNotNull('soato_code')->pluck('id', 'soato_code');

        // Viloyat (district null) — v_x kodlari.
        if (($data['viloyat'] ?? null) !== null) {
            $this->seedScope($data['viloyat']['indicators'] ?? [], 'v_x', null, $kpiByCode);
        }

        // Tumanlar — t_x kodlari (soato -> district_id).
        foreach (($data['districts'] ?? []) as $soato => $district) {
            $districtId = $districtBySoato[$soato] ?? null;
            if ($districtId === null) {
                $this->command?->warn("Туман топилмади (SOATO {$soato}) — KPI ўтказиб юборилди.");

                continue;
            }
            $this->seedScope($district['indicators'] ?? [], 't_x', (string) $districtId, $kpiByCode);
        }

        // Real ma'lumot yozildi — KPI keshini bekor qilish.
        KpiService::invalidateCache();
    }

    /**
     * Bir kesim (viloyat yoki bitta tuman) ko'rsatkichlarini yozadi.
     *
     * @param  array<int, array<string, mixed>>  $indicators
     * @param  Collection<string, string>  $kpiByCode
     */
    private function seedScope(array $indicators, string $prefix, ?string $districtId, $kpiByCode): void
    {
        foreach ($indicators as $ind) {
            $code = $prefix.KpiCatalog::codeNum((string) $ind['num']);
            $kpiId = $kpiByCode[$code] ?? null;
            if ($kpiId === null) {
                continue;
            }

            // Reja (target) — Q1..Q4 (mavjud qiymatlar).
            $targets = [
                '2026-Q1' => $ind['q1_plan'] ?? null,
                '2026-Q2' => $ind['q2_plan'] ?? null,
                '2026-Q3' => $ind['q3_plan'] ?? null,
                '2026-Q4' => $ind['q4_plan'] ?? null,
            ];
            foreach ($targets as $period => $plan) {
                if ($plan === null) {
                    continue;
                }
                KpiTarget::updateOrCreate(
                    ['kpi_id' => $kpiId, 'district_id' => $districtId, 'period' => $period],
                    ['target' => (float) $plan],
                );
            }

            // Bajarilish (entry) — Q1, Q2 (real natija).
            $facts = [
                '2026-Q1' => $ind['q1_fact'] ?? null,
                '2026-Q2' => $ind['q2_fact'] ?? null,
            ];
            foreach ($facts as $period => $fact) {
                if ($fact === null) {
                    continue;
                }
                KpiEntry::updateOrCreate(
                    ['kpi_id' => $kpiId, 'district_id' => $districtId, 'period' => $period],
                    [
                        'value' => (float) $fact,
                        'source' => 'manual',
                        'status' => 'approved',
                        'entered_by' => null,
                        'approved_by' => null,
                    ],
                );
            }
        }
    }
}
