<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

        // Topshiriq ijro% — BITTA guruhlangan so'rov (tuman kesimida total/closed).
        // Avvalgi per-tuman disciplineStats N+1 chaqiruvi o'rniga (bir xil formula:
        // closed_rate = closed / max(1,total) * 100).
        $targetStats = DB::connection('advisor')->table('task_targets')
            ->groupBy('district_id')
            ->selectRaw("district_id, count(*) as total, count(*) FILTER (WHERE status = 'closed') as closed")
            ->get()
            ->keyBy('district_id');

        $scored = [];
        foreach ($summary as $ordinal => $row) {
            $districtId = $row['district']['id'];
            $kpiAvg = (float) ($row['avg_fulfillment'] ?? 0.0);
            $stat = $targetStats[$districtId] ?? null;
            $total = $stat === null ? 0 : (int) $stat->total;
            $closed = $stat === null ? 0 : (int) $stat->closed;
            $taskExec = round($closed / max(1, $total) * 100, 1);
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
        $rows = [];
        $rank = 1;
        foreach ($scored as $s) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'period' => $period,
                'district_id' => $s['district_id'],
                'score' => $s['score'],
                'rank' => $rank,
                'computed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $rank++;
        }

        // Eski yozuvni o'chirib, YAGONA batch insert (13 ta Ranking::create o'rniga).
        DB::connection('advisor')->transaction(function () use ($rows, $period) {
            DB::connection('advisor')->table('rankings')->where('period', $period)->delete();
            if ($rows !== []) {
                DB::connection('advisor')->table('rankings')->insert($rows);
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
