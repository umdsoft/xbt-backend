<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Services;

use App\Domains\Advisor\Support\AdvisorScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * DASHBOARD (spec §10) — rolga qarab agregat. Mahalla dashboard dizayni qayta
 * ishlatiladi; backend rolga mos tarkib beradi:
 *
 *   VILOYAT  — ochiq topshiriq/loyiha, kutayotgan tasdiq/QA, topshiriq ijro %,
 *              tuman o'rtacha KPI, muddati o'tganlar (alerts), tuman reyting jadvali,
 *              so'nggi faoliyat.
 *   BO'LINMA — QA navbati, tasdiq kutayotgan, qaytarilgan, KPI o'rtacha; reyting;
 *              so'nggi faoliyat.
 *   TUMAN    — mening ochiq topshiriqlarim, muddati o'tган, loyihalarim, KPI ijrom,
 *              reytingdagi o'rnim; yaqin muddat/qaytarish (alerts); so'nggi faoliyat.
 *
 * SHARTNOMA: { cards:[{key,label,value,tone?}], alerts:[{type,text,ref?}],
 *              ranking?:[{district,rank,score}], recent_activity?:[...] }.
 */
class DashboardService
{
    public function __construct(
        private readonly KpiService $kpi,
        private readonly RankingService $rankings,
        private readonly TaskService $tasks,
        private readonly ActivityService $activity,
    ) {}

    /**
     * Rolга qarab dashboard tarkibi. Qisqa TTL (45s) keshlanadi — umumiy ko'rinish
     * uchun bir necha soniyalik eskirish maqbul (aniq bekor qilish SHART emas).
     * Kalit = rol + tuman + davr (tarkib advisor_id'ga bog'liq EMAS — tuman uchun
     * ham FAQAT district_id kesimi; shu bois bir tumandagi maslahatchilar bir xil).
     *
     * @return array<string, mixed>
     */
    public function forUser(AdvisorScope $scope, string $period): array
    {
        $role = $scope->role ?? 'none';
        $district = $scope->districtId ?? 'all';
        $key = "advisor.dashboard.{$role}.{$district}.{$period}";

        return Cache::remember($key, 45, fn () => $this->build($scope, $period));
    }

    /**
     * YILLIK TAHLIL (grafik uchun) — chorak tanlashsiz, butun yil kesimi. Dashboard
     * kartalaridan farqli: bu yerda KPI tendensiyasi (4 chorak), topshiriq/loyiha
     * holati taqsimoti va (viloyat/bo'linma) tuman reytingi grafik shaklida.
     *
     * Qamrov: tuman FAQAT o'z tumani; viloyat/bo'linma — butun viloyat.
     * Qisqa TTL (120s) — ma'lumot ~2 haftaда bir yangilanadi, lekin topshiriq/loyiha
     * tez-tez o'zgaradi; 2 daqiqalik eskirish maqbul.
     *
     * @return array<string, mixed>
     */
    public function analytics(AdvisorScope $scope, int $year): array
    {
        $role = $scope->role ?? 'none';
        $district = $scope->districtId ?? 'all';
        $key = "advisor.analytics.{$role}.{$district}.{$year}";

        return Cache::remember($key, 120, fn () => $this->buildAnalytics($scope, $year));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAnalytics(AdvisorScope $scope, int $year): array
    {
        $isTuman = $scope->isTuman();
        $districtId = $isTuman ? $scope->districtId : null;

        $out = [
            'year' => $year,
            'kpi_trend' => $this->kpiTrend($scope, $year),
            'task_status' => $this->taskStatusDist($districtId),
            'project_status' => $this->projectStatusDist($districtId),
        ];

        // Reyting grafigi — faqat viloyat/bo'linma (tuman o'z o'rnini panelda ko'radi).
        // Joriy chorak bo'sh bo'lса (masalan Q3 hali kiritilmagan), yilдаги ENG SO'NGGI
        // ma'lumotli chorak reytingини ko'rsatamiz — bo'sh grafik o'rniga.
        if (! $isTuman) {
            $out['ranking'] = $this->rankingList($this->latestRankingPeriod($year));
        }

        return $out;
    }

    /**
     * KPI ijro% tendensiyasi — 4 chorak bo'yicha (viloyat: tuman o'rtachasi;
     * tuman: o'z tumani o'rtachasi). Grafik (area/line) uchun.
     *
     * @return array<int, array{period: string, label: string, value: ?float}>
     */
    private function kpiTrend(AdvisorScope $scope, int $year): array
    {
        $labels = ['I чорак', 'II чорак', 'III чорак', 'IV чорак'];
        $isTuman = $scope->isTuman();
        $districtId = $scope->districtId;

        $trend = [];
        for ($q = 1; $q <= 4; $q++) {
            $period = "{$year}-Q{$q}";
            $value = ($isTuman && $districtId === null)
                ? null
                : ($isTuman
                    ? $this->kpiAvgForDistrict($period, (string) $districtId)
                    : $this->kpiAvgOverall($period));
            $trend[] = ['period' => $period, 'label' => $labels[$q - 1], 'value' => $value];
        }

        return $trend;
    }

    /**
     * Topshiriq (task_targets) holati taqsimoti — donut grafik uchun. O'zaro
     * chegaralanган to'plamlar (bir target bir bo'limда): faol / muddati o'tган /
     * qaytarilган / yopilган. Postgres `count(*) filter` bilan bitta so'rov.
     *
     * @return array<int, array{key: string, label: string, value: int, tone: string}>
     */
    private function taskStatusDist(?string $districtId): array
    {
        $r = DB::connection('advisor')->table('task_targets')
            ->when($districtId !== null, fn ($q) => $q->where('district_id', $districtId))
            ->selectRaw(
                "count(*) filter (where status = 'closed') as closed,
                 count(*) filter (where status = 'returned') as returned,
                 count(*) filter (where status not in ('closed','returned') and due_at is not null and due_at < now()) as overdue,
                 count(*) filter (where status not in ('closed','returned') and (due_at is null or due_at >= now())) as active"
            )->first();

        return [
            ['key' => 'active', 'label' => 'Фаол', 'value' => (int) ($r->active ?? 0), 'tone' => 'info'],
            ['key' => 'overdue', 'label' => 'Муддати ўтган', 'value' => (int) ($r->overdue ?? 0), 'tone' => 'danger'],
            ['key' => 'returned', 'label' => 'Қайтарилган', 'value' => (int) ($r->returned ?? 0), 'tone' => 'warn'],
            ['key' => 'closed', 'label' => 'Ёпилган', 'value' => (int) ($r->closed ?? 0), 'tone' => 'ok'],
        ];
    }

    /**
     * Loyiha holati taqsimoti — donut grafik uchun.
     *
     * @return array<int, array{key: string, label: string, value: int, tone: string}>
     */
    private function projectStatusDist(?string $districtId): array
    {
        $r = DB::connection('advisor')->table('projects')
            ->whereNull('deleted_at')
            ->when($districtId !== null, fn ($q) => $q->where('district_id', $districtId))
            ->selectRaw(
                "count(*) filter (where status = 'planned') as planned,
                 count(*) filter (where status = 'in_progress') as in_progress,
                 count(*) filter (where status = 'done') as done,
                 count(*) filter (where status = 'paused') as paused"
            )->first();

        return [
            ['key' => 'planned', 'label' => 'Режалаштирилган', 'value' => (int) ($r->planned ?? 0), 'tone' => 'info'],
            ['key' => 'in_progress', 'label' => 'Жараёнда', 'value' => (int) ($r->in_progress ?? 0), 'tone' => 'warn'],
            ['key' => 'done', 'label' => 'Бажарилган', 'value' => (int) ($r->done ?? 0), 'tone' => 'ok'],
            ['key' => 'paused', 'label' => 'Тўхтатилган', 'value' => (int) ($r->paused ?? 0), 'tone' => 'slate'],
        ];
    }

    /**
     * Berilган yil uchun reyting davri — joriy yilда joriy chorak, aks holда Q4.
     */
    private function currentPeriodForYear(int $year): string
    {
        $now = Carbon::now();
        $q = $now->year === $year ? (int) ceil($now->month / 3) : 4;

        return "{$year}-Q{$q}";
    }

    /**
     * Yilда reyting HISOBLANGAN eng so'nggi chorak (masalan Q3 bo'sh bo'lса — Q2).
     * Umuman yo'q bo'lса — joriy chorakка qaytadi.
     */
    private function latestRankingPeriod(int $year): string
    {
        $p = DB::connection('advisor')->table('rankings')
            ->where('period', 'like', "{$year}-Q%")
            ->orderByDesc('period')
            ->value('period');

        return $p !== null ? (string) $p : $this->currentPeriodForYear($year);
    }

    /**
     * @return array<string, mixed>
     */
    private function build(AdvisorScope $scope, string $period): array
    {
        if ($scope->isViloyat()) {
            return $this->viloyat($scope, $period);
        }
        if ($scope->isBolinma()) {
            return $this->bolinma($scope, $period);
        }
        if ($scope->isTuman()) {
            return $this->tuman($scope, $period);
        }

        return ['cards' => [], 'alerts' => []];
    }

    // -------------------------------------------------------------------- viloyat

    /**
     * @return array<string, mixed>
     */
    private function viloyat(AdvisorScope $scope, string $period): array
    {
        $conn = DB::connection('advisor');

        $openTasks = (int) $conn->table('tasks')->where('status', '!=', 'closed')->count();
        $openProjects = (int) $conn->table('projects')->whereNull('deleted_at')->where('status', '!=', 'done')->count();
        $pendingReview = (int) $conn->table('task_reports')->whereIn('status', ['pending', 'qa_checked'])->count();
        $overdue = (int) $this->overdueTargets(null)->count();
        $taskExec = (float) $this->tasks->disciplineStats($scope)['closed_rate'];
        $kpiAvg = $this->kpiAvgOverall($period);

        return [
            'cards' => [
                $this->card('open_tasks', 'Очиқ топшириқлар', $openTasks, 'info'),
                $this->card('open_projects', 'Фаол лойиҳалар', $openProjects, 'info'),
                $this->card('pending_review', 'Кутаётган тасдиқ/QA', $pendingReview, $pendingReview > 0 ? 'warn' : 'ok'),
                $this->card('overdue', 'Муддати ўтган', $overdue, $overdue > 0 ? 'danger' : 'ok'),
                $this->card('task_exec', 'Топшириқ ижро', $this->pct($taskExec), 'info'),
                $this->card('kpi_avg', 'Туман ўртача KPI', $this->pctOrDash($kpiAvg), 'info'),
            ],
            'alerts' => $this->overdueAlerts(null),
            'ranking' => $this->rankingList($period),
            'recent_activity' => $this->activity->feed($scope, ['limit' => 8]),
        ];
    }

    // ------------------------------------------------------------------- bo'linma

    /**
     * @return array<string, mixed>
     */
    private function bolinma(AdvisorScope $scope, string $period): array
    {
        $conn = DB::connection('advisor');

        $qaQueue = (int) $conn->table('task_reports')->where('status', 'pending')->count();
        $pendingApproval = (int) $conn->table('task_reports')->where('status', 'qa_checked')->count();
        $returned = (int) $conn->table('task_reports')->where('status', 'returned')->count();
        $openTasks = (int) $conn->table('tasks')->where('status', '!=', 'closed')->count();
        $kpiAvg = $this->kpiAvgOverall($period);

        return [
            'cards' => [
                $this->card('qa_queue', 'QA навбати', $qaQueue, $qaQueue > 0 ? 'warn' : 'ok'),
                $this->card('pending_approval', 'Тасдиқ кутмоқда', $pendingApproval, 'info'),
                $this->card('returned', 'Қайтарилган', $returned, $returned > 0 ? 'warn' : 'ok'),
                $this->card('open_tasks', 'Очиқ топшириқлар', $openTasks, 'info'),
                $this->card('kpi_avg', 'Туман ўртача KPI', $this->pctOrDash($kpiAvg), 'info'),
            ],
            'alerts' => $this->qaAlerts(),
            'ranking' => $this->rankingList($period),
            'recent_activity' => $this->activity->feed($scope, ['limit' => 8]),
        ];
    }

    // ---------------------------------------------------------------------- tuman

    /**
     * @return array<string, mixed>
     */
    private function tuman(AdvisorScope $scope, string $period): array
    {
        $district = $scope->districtId;

        if ($district === null) {
            // Profil to'liq emas (tuman biriktirilmagan) — bo'sh dashboard.
            return [
                'cards' => [
                    $this->card('my_open_tasks', 'Менинг очиқ топшириқларим', 0, 'info'),
                    $this->card('overdue', 'Муддати ўтган', 0, 'ok'),
                    $this->card('my_projects', 'Лойиҳаларим', 0, 'info'),
                    $this->card('kpi_avg', 'KPI ижром', '—', 'info'),
                    $this->card('my_rank', 'Рейтингдаги ўрним', '—', 'info'),
                ],
                'alerts' => [],
                'recent_activity' => [],
            ];
        }

        $conn = DB::connection('advisor');

        $myOpen = (int) $conn->table('task_targets')->where('district_id', $district)->where('status', '!=', 'closed')->count();
        $overdue = (int) $this->overdueTargets($district)->count();
        $returned = (int) $conn->table('task_targets')->where('district_id', $district)->where('status', 'returned')->count();
        $myProjects = (int) $conn->table('projects')->whereNull('deleted_at')->where('district_id', $district)->where('status', '!=', 'done')->count();
        $kpiAvg = $this->kpiAvgForDistrict($period, $district);
        $myRank = $this->rankForDistrict($period, $district);

        return [
            'cards' => [
                $this->card('my_open_tasks', 'Менинг очиқ топшириқларим', $myOpen, 'info'),
                $this->card('overdue', 'Муддати ўтган', $overdue, $overdue > 0 ? 'danger' : 'ok'),
                $this->card('returned', 'Қайтарилган', $returned, $returned > 0 ? 'warn' : 'ok'),
                $this->card('my_projects', 'Лойиҳаларим', $myProjects, 'info'),
                $this->card('kpi_avg', 'KPI ижром', $this->pctOrDash($kpiAvg), 'info'),
                $this->card('my_rank', 'Рейтингдаги ўрним', $myRank ?? '—', 'info'),
            ],
            'alerts' => array_merge(
                $this->returnedAlerts($district),
                $this->overdueAlerts($district),
                $this->dueSoonAlerts($district),
            ),
            'recent_activity' => $this->activity->feed($scope, ['limit' => 8]),
        ];
    }

    // ----------------------------------------------------------------- ogohlantirishlar

    /**
     * Muddati o'tган nishonlar so'rovi (ixtiyoriy tuman kesimida).
     *
     * @return \Illuminate\Database\Query\Builder
     */
    private function overdueTargets(?string $districtId)
    {
        return DB::connection('advisor')->table('task_targets')
            ->where('status', '!=', 'closed')
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->when($districtId !== null, fn ($q) => $q->where('district_id', $districtId));
    }

    /**
     * @return array<int, array{type: string, text: string, ref: ?string}>
     */
    private function overdueAlerts(?string $districtId, int $limit = 8): array
    {
        $rows = DB::connection('advisor')->table('task_targets as tt')
            ->join('tasks as t', 't.id', '=', 'tt.task_id')
            ->leftJoin('districts as d', 'd.id', '=', 'tt.district_id')
            ->where('tt.status', '!=', 'closed')
            ->whereNotNull('tt.due_at')
            ->where('tt.due_at', '<', now())
            ->when($districtId !== null, fn ($q) => $q->where('tt.district_id', $districtId))
            ->orderBy('tt.due_at')
            ->limit($limit)
            ->get(['t.id as task_id', 't.title', 'd.name_cyr as district_name']);

        return $rows->map(fn ($r) => [
            'type' => 'overdue',
            'text' => 'Муддати ўтган: '.$r->title.($r->district_name ? ' — '.$r->district_name : ''),
            'ref' => $r->task_id,
        ])->all();
    }

    /**
     * @return array<int, array{type: string, text: string, ref: ?string}>
     */
    private function dueSoonAlerts(string $districtId, int $limit = 8): array
    {
        $rows = DB::connection('advisor')->table('task_targets as tt')
            ->join('tasks as t', 't.id', '=', 'tt.task_id')
            ->where('tt.district_id', $districtId)
            ->where('tt.status', '!=', 'closed')
            ->whereNotNull('tt.due_at')
            ->whereBetween('tt.due_at', [now(), now()->copy()->addDays(7)])
            ->orderBy('tt.due_at')
            ->limit($limit)
            ->get(['t.id as task_id', 't.title']);

        return $rows->map(fn ($r) => [
            'type' => 'due_soon',
            'text' => 'Яқин муддат: '.$r->title,
            'ref' => $r->task_id,
        ])->all();
    }

    /**
     * @return array<int, array{type: string, text: string, ref: ?string}>
     */
    private function returnedAlerts(string $districtId, int $limit = 8): array
    {
        $rows = DB::connection('advisor')->table('task_targets as tt')
            ->join('tasks as t', 't.id', '=', 'tt.task_id')
            ->where('tt.district_id', $districtId)
            ->where('tt.status', 'returned')
            ->orderByDesc('tt.updated_at')
            ->limit($limit)
            ->get(['t.id as task_id', 't.title']);

        return $rows->map(fn ($r) => [
            'type' => 'returned',
            'text' => 'Ҳисобот қайтарилди: '.$r->title,
            'ref' => $r->task_id,
        ])->all();
    }

    /**
     * QA navbatidagi hisobotlar (bo'linma ogohlantirishlari).
     *
     * @return array<int, array{type: string, text: string, ref: ?string}>
     */
    private function qaAlerts(int $limit = 8): array
    {
        $rows = DB::connection('advisor')->table('task_reports as r')
            ->join('task_targets as tt', 'tt.id', '=', 'r.task_target_id')
            ->join('tasks as t', 't.id', '=', 'tt.task_id')
            ->leftJoin('districts as d', 'd.id', '=', 'tt.district_id')
            ->where('r.status', 'pending')
            ->orderByDesc(DB::raw('coalesce(r.submitted_at, r.created_at)'))
            ->limit($limit)
            ->get(['t.id as task_id', 't.title', 'd.name_cyr as district_name']);

        return $rows->map(fn ($r) => [
            'type' => 'qa',
            'text' => 'QA кутмоқда: '.$r->title.($r->district_name ? ' — '.$r->district_name : ''),
            'ref' => $r->task_id,
        ])->all();
    }

    // ------------------------------------------------------------------- reyting/KPI

    /**
     * Reyting jadvali dashboard shakli — [{district(nomi), rank, score}].
     *
     * @return array<int, array{district: ?string, rank: int, score: float}>
     */
    private function rankingList(string $period): array
    {
        return array_map(fn ($r) => [
            'district' => $r['district']['name'],
            'rank' => $r['rank'],
            'score' => $r['score'],
        ], $this->rankings->rankings($period));
    }

    private function rankForDistrict(string $period, string $districtId): ?int
    {
        $rank = DB::connection('advisor')->table('rankings')
            ->where('period', $period)->where('district_id', $districtId)->value('rank');

        return $rank === null ? null : (int) $rank;
    }

    private function kpiAvgOverall(string $period): ?float
    {
        $vals = array_values(array_filter(
            array_map(fn ($s) => $s['avg_fulfillment'], $this->kpi->summary($period)),
            fn ($v) => $v !== null,
        ));

        return $vals === [] ? null : round(array_sum($vals) / count($vals), 1);
    }

    private function kpiAvgForDistrict(string $period, string $districtId): ?float
    {
        foreach ($this->kpi->summary($period) as $s) {
            if ($s['district']['id'] === $districtId) {
                return $s['avg_fulfillment'];
            }
        }

        return null;
    }

    // ----------------------------------------------------------------- yordamchi

    /**
     * @param  int|float|string  $value
     * @return array{key: string, label: string, value: int|float|string, tone?: string}
     */
    private function card(string $key, string $label, $value, ?string $tone = null): array
    {
        $card = ['key' => $key, 'label' => $label, 'value' => $value];
        if ($tone !== null) {
            $card['tone'] = $tone;
        }

        return $card;
    }

    private function pct(float $n): string
    {
        return round($n, 1).'%';
    }

    /**
     * @param  float|null  $n
     * @return string
     */
    private function pctOrDash(?float $n): string
    {
        return $n === null ? '—' : $this->pct($n);
    }
}
