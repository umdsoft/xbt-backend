<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Services;

use Illuminate\Support\Facades\DB;

/**
 * YUQORI IDORAГА SVOD (spec §1, §10) — tuman kesimida umumlashtirilgan hisobot
 * (xlsx). Har tuman uchun: topshiriq ijro %, KPI o'rtacha ijro %, reyting o'rni,
 * loyiha soni. Barcha 13 tuman kiritiladi (ma'lumot yo'q bo'lsa 0 / «—»).
 *
 * Deterministik: topshiriq ijro % = yopilgan nishonlar ulushi (disciplineStats
 * bilan bir xil formula); KPI o'rtacha = KpiService::summary; reyting =
 * rankings; loyiha soni = projects count. Faqat viloyat/bo'linma (kontroller).
 */
class SvodService
{
    public function __construct(
        private readonly KpiService $kpi,
    ) {}

    /**
     * Svod satrlari (xlsx uchun) — sort_order bo'yicha barcha tuman.
     *
     * @return array<int, array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    public function rows(string $period): array
    {
        $districts = DB::connection('advisor')->table('districts')
            ->whereNotNull('soato_code')
            ->orderBy('sort_order')
            ->get(['id', 'name_cyr']);

        // Topshiriq ijro % — tuman kesimida (yopilgan / jami nishon).
        $taskAgg = DB::connection('advisor')->table('task_targets')
            ->groupBy('district_id')
            ->selectRaw("district_id, count(*) as total, count(*) filter (where status = 'closed') as closed")
            ->get()->keyBy('district_id');

        // KPI o'rtacha ijro % — tuman kesimida.
        $kpiAvg = [];
        foreach ($this->kpi->summary($period) as $s) {
            $kpiAvg[$s['district']['id']] = $s['avg_fulfillment'];
        }

        // Reyting o'rni.
        $ranks = DB::connection('advisor')->table('rankings')
            ->where('period', $period)->pluck('rank', 'district_id');

        // Loyiha soni.
        $projects = DB::connection('advisor')->table('projects')
            ->whereNull('deleted_at')
            ->groupBy('district_id')
            ->selectRaw('district_id, count(*) as n')
            ->pluck('n', 'district_id');

        $rows = [];
        foreach ($districts as $d) {
            $agg = $taskAgg[$d->id] ?? null;
            $taskExec = $agg === null || (int) $agg->total === 0
                ? 0.0
                : round((int) $agg->closed / (int) $agg->total * 100, 1);
            $avg = $kpiAvg[$d->id] ?? null;

            $rows[] = [
                (string) $d->name_cyr,
                (string) $taskExec,
                $avg === null ? '—' : (string) $avg,
                isset($ranks[$d->id]) ? (string) (int) $ranks[$d->id] : '—',
                (string) (int) ($projects[$d->id] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * Xlsx ustun sarlavhalari (svod tartibida).
     *
     * @return array<int, string>
     */
    public function headers(): array
    {
        return ['Туман', 'Топшириқ ижро %', 'KPI ўртача %', 'Рейтинг ўрни', 'Лойиҳа сони'];
    }
}
