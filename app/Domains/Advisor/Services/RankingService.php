<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Services;

use App\Domains\Advisor\Models\Ranking;
use App\Domains\Advisor\Support\AdvisorScope;
use Illuminate\Support\Facades\DB;

/**
 * REYTING moduli (spec §8). Choraklik/oylik tuman reytingi — deterministik:
 *
 *   score = 0.6 * (tuman KPI o'rtacha ijro %)   [summary avg_fulfillment]
 *         + 0.3 * (topshiriq ijro %)             [TaskService::disciplineStats]
 *         + 0.1 * (loyiha ballari)               [min(loyiha soni * 10, 100)]
 *
 * Baholanadigan to'plam = period bo'yicha KPI entrysi bor tumanlar (derive'dан
 * keyin avto entrylar ham kiradi). Score bo'yicha kamayish tartibida rank (1..N);
 * teng bo'lsa tuman sort_order bilan barqaror.
 */
class RankingService
{
    public function __construct(
        private readonly KpiService $kpi,
        private readonly TaskService $tasks,
    ) {}

    /**
     * Berilgan period uchun reytingni hisoblaydi va yozadi (eski yozuv o'chiriladi).
     *
     * @return array<int, array{district: array{id: string, name: ?string}, score: float, rank: int}>
     */
    public function compute(string $period): array
    {
        // summary — tuman kesimida (sort_order bo'yicha tartiblangan) KPI xulosasi.
        $summary = $this->kpi->summary($period);

        if ($summary === []) {
            // KPI entrysi yo'q — reyting bo'sh (mavjud period yozuvlari tozalanadi).
            DB::connection('advisor')->table('rankings')->where('period', $period)->delete();

            return [];
        }

        $projectCounts = DB::connection('advisor')->table('projects')
            ->whereNull('deleted_at')
            ->whereNotNull('district_id')
            ->groupBy('district_id')
            ->selectRaw('district_id, count(*) as n')
            ->pluck('n', 'district_id');

        $scored = [];
        foreach ($summary as $ordinal => $row) {
            $districtId = $row['district']['id'];
            $kpiAvg = (float) ($row['avg_fulfillment'] ?? 0.0);
            $taskExec = (float) $this->tasks->disciplineStats(
                new AdvisorScope('advisor_tuman', $districtId, null),
            )['closed_rate'];
            $projectScore = min(((int) ($projectCounts[$districtId] ?? 0)) * 10, 100);

            $scored[] = [
                'district_id' => $districtId,
                'score' => round(0.6 * $kpiAvg + 0.3 * $taskExec + 0.1 * $projectScore, 2),
                'ordinal' => $ordinal, // sort_order tie-break (barqaror tartib)
            ];
        }

        // Score kamayish; teng bo'lsa sort_order (ordinal) o'sish.
        usort($scored, function ($a, $b) {
            if ($a['score'] === $b['score']) {
                return $a['ordinal'] <=> $b['ordinal'];
            }

            return $b['score'] <=> $a['score'];
        });

        $now = now();
        DB::connection('advisor')->transaction(function () use ($scored, $period, $now) {
            DB::connection('advisor')->table('rankings')->where('period', $period)->delete();

            $rank = 1;
            foreach ($scored as $s) {
                Ranking::create([
                    'period' => $period,
                    'district_id' => $s['district_id'],
                    'score' => $s['score'],
                    'rank' => $rank,
                    'computed_at' => $now,
                ]);
                $rank++;
            }
        });

        return $this->rankings($period);
    }

    /**
     * Berilgan period reyting jadvali (rank bo'yicha tartiblangan).
     *
     * @return array<int, array{district: array{id: string, name: ?string}, score: float, rank: int}>
     */
    public function rankings(string $period): array
    {
        return DB::connection('advisor')->table('rankings as r')
            ->leftJoin('districts as d', 'd.id', '=', 'r.district_id')
            ->where('r.period', $period)
            ->orderBy('r.rank')
            ->get(['r.district_id', 'r.score', 'r.rank', 'd.name_cyr as district_name'])
            ->map(fn ($r) => [
                'district' => ['id' => $r->district_id, 'name' => $r->district_name],
                'score' => (float) $r->score,
                'rank' => (int) $r->rank,
            ])->all();
    }
}
