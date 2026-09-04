<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Services;

use App\Domains\Advisor\Support\AdvisorScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * FAOLIYAT NAZORATI (spec §9). Ikki ko'rinish:
 *
 *  1) feed()      — vaqt bo'yicha faoliyat LENTASI (DERIVED, yangi jadval shart
 *                   emas): mavjud jadvallardan UNION — topshiriq yaratildi,
 *                   hisobot yuborildi, loyiha yangilandi, KPI kiritildi.
 *  2) oversight() — har maslahatchi bo'yicha AGREGAT satr (viloyat/bo'linma):
 *                   ochiq topshiriq, muddati o'tgan, hisobot navbati, KPI o'rtacha,
 *                   so'nggi faoliyat.
 *
 * Qamrov (AdvisorScope): viloyat/bo'linma HAMMANI ko'radi; tuman FAQAT o'z tumani
 * (IDOR himoyasi — advisor Task/Project/Kpi naqshi). Ism markaziy auth.users'dan
 * (cross-schema PHP'da yig'iladi — advisorNames/userNames).
 */
class ActivityService
{
    public function __construct(
        private readonly KpiService $kpi,
    ) {}

    // ------------------------------------------------------------- faoliyat lentasi

    /**
     * Faoliyat lentasi — mavjud jadvallardan derived UNION, vaqt bo'yicha kamayish.
     * Viloyat/bo'linma: hammani (advisor_id/district_id filtri ixtiyoriy); tuman:
     * FAQAT o'z tumani (filtr e'tiborsiz).
     *
     * @param  array<string, mixed>  $filters  advisor_id, district_id, limit
     * @return array<int, array{time: string, advisor: array{name: ?string}|null, type: string, summary: string, ref: ?string}>
     */
    public function feed(AdvisorScope $scope, array $filters): array
    {
        $limit = min(max((int) ($filters['limit'] ?? 30), 1), 200);

        if ($scope->isTuman()) {
            // Tuman FAQAT o'z tumani lentasini ko'radi (advisor/district filtri e'tiborsiz).
            if ($scope->districtId === null) {
                return [];
            }
            $districtId = $scope->districtId;
            $advisorUserId = null;
            $advisorId = null;
        } else {
            $districtId = $filters['district_id'] ?? null;
            $advisorId = $filters['advisor_id'] ?? null;
            // advisor_id (advisor.advisors.id) -> user_id (tasks/projects/kpi ustunlari
            // user_id bilan bog'langan; hisobot esa to'g'ridan advisor_id bilan).
            $advisorUserId = $advisorId === null ? null
                : DB::connection('advisor')->table('advisors')->where('id', $advisorId)->value('user_id');
        }

        $events = array_merge(
            $this->tasksStream($districtId, $advisorUserId, $limit),
            $this->reportsStream($districtId, $advisorId, $limit),
            $this->projectsStream($districtId, $advisorUserId, $limit),
            $this->kpiStream($districtId, $advisorUserId, $limit),
        );

        // Vaqt bo'yicha kamayish (barqaror) + limit.
        usort($events, fn ($a, $b) => $b['ts'] <=> $a['ts']);
        $events = array_slice($events, 0, $limit);

        return $this->present($events);
    }

    /**
     * Topshiriq yaratildi (tasks). Tuman/district filtr: shu tumanga nishoni bor
     * topshiriqlar. Aktor = created_by (auth.users).
     *
     * @return array<int, array<string, mixed>>
     */
    private function tasksStream(?string $districtId, ?string $advisorUserId, int $limit): array
    {
        $rows = DB::connection('advisor')->table('tasks as t')
            ->when($advisorUserId !== null, fn ($q) => $q->where('t.created_by', $advisorUserId))
            ->when($districtId !== null, fn ($q) => $q->whereExists(fn ($sub) => $sub
                ->select(DB::raw(1))->from('task_targets as tt')
                ->whereColumn('tt.task_id', 't.id')
                ->where('tt.district_id', $districtId)))
            ->orderByDesc('t.created_at')
            ->limit($limit)
            ->get(['t.id', 't.title', 't.created_by', 't.created_at']);

        return $rows->map(fn ($r) => [
            'ts' => Carbon::parse($r->created_at)->getTimestamp(),
            'time' => $r->created_at,
            'type' => 'task_created',
            'summary' => 'Топшириқ: '.$r->title,
            'ref' => $r->id,
            'user_id' => $r->created_by,
            'advisor_id' => null,
        ])->all();
    }

    /**
     * Hisobot yuborildi (task_reports). Aktor = advisor_id (advisor.advisors).
     * ref = topshiriq id (frontend topshiriqni ochadi).
     *
     * @return array<int, array<string, mixed>>
     */
    private function reportsStream(?string $districtId, ?string $advisorId, int $limit): array
    {
        $rows = DB::connection('advisor')->table('task_reports as r')
            ->join('task_targets as tt', 'tt.id', '=', 'r.task_target_id')
            ->join('tasks as t', 't.id', '=', 'tt.task_id')
            ->when($advisorId !== null, fn ($q) => $q->where('r.advisor_id', $advisorId))
            ->when($districtId !== null, fn ($q) => $q->where('tt.district_id', $districtId))
            ->orderByDesc(DB::raw('coalesce(r.submitted_at, r.created_at)'))
            ->limit($limit)
            ->get(['r.id', 'r.advisor_id', 'r.submitted_at', 'r.created_at', 't.id as task_id', 't.title']);

        return $rows->map(function ($r) {
            $when = $r->submitted_at ?? $r->created_at;

            return [
                'ts' => Carbon::parse($when)->getTimestamp(),
                'time' => $when,
                'type' => 'report_submitted',
                'summary' => 'Ҳисобот: '.$r->title,
                'ref' => $r->task_id,
                'user_id' => null,
                'advisor_id' => $r->advisor_id,
            ];
        })->all();
    }

    /**
     * Loyiha yangilandi (project_updates). Aktor = user_id (auth.users).
     *
     * @return array<int, array<string, mixed>>
     */
    private function projectsStream(?string $districtId, ?string $advisorUserId, int $limit): array
    {
        $rows = DB::connection('advisor')->table('project_updates as u')
            ->join('projects as p', 'p.id', '=', 'u.project_id')
            ->whereNull('p.deleted_at')
            ->when($advisorUserId !== null, fn ($q) => $q->where('u.user_id', $advisorUserId))
            ->when($districtId !== null, fn ($q) => $q->where('p.district_id', $districtId))
            ->orderByDesc(DB::raw('coalesce(u.occurred_at, u.created_at)'))
            ->limit($limit)
            ->get(['u.id', 'u.user_id', 'u.occurred_at', 'u.created_at', 'p.id as project_id', 'p.title']);

        return $rows->map(function ($r) {
            $when = $r->occurred_at ?? $r->created_at;

            return [
                'ts' => Carbon::parse($when)->getTimestamp(),
                'time' => $when,
                'type' => 'project_updated',
                'summary' => 'Лойиҳа: '.$r->title,
                'ref' => $r->project_id,
                'user_id' => $r->user_id,
                'advisor_id' => null,
            ];
        })->all();
    }

    /**
     * KPI kiritildi (kpi_entries, faqat qo'lda — auto derivatsiya lentaga tushmaydi).
     * Aktor = entered_by (auth.users).
     *
     * @return array<int, array<string, mixed>>
     */
    private function kpiStream(?string $districtId, ?string $advisorUserId, int $limit): array
    {
        $rows = DB::connection('advisor')->table('kpi_entries as e')
            ->join('kpis as k', 'k.id', '=', 'e.kpi_id')
            ->where('e.source', 'manual')
            // Faqat INSON kiritgan yozuvlar lentada (import/seed — entered_by null — kirmaydi).
            ->whereNotNull('e.entered_by')
            ->when($advisorUserId !== null, fn ($q) => $q->where('e.entered_by', $advisorUserId))
            ->when($districtId !== null, fn ($q) => $q->where('e.district_id', $districtId))
            ->orderByDesc('e.updated_at')
            ->limit($limit)
            ->get(['e.id', 'e.entered_by', 'e.updated_at', 'e.period', 'k.name as kpi_name']);

        return $rows->map(fn ($r) => [
            'ts' => Carbon::parse($r->updated_at)->getTimestamp(),
            'time' => $r->updated_at,
            'type' => 'kpi_entry',
            'summary' => 'KPI: '.$r->kpi_name.' ('.$r->period.')',
            'ref' => $r->id,
            'user_id' => $r->entered_by,
            'advisor_id' => null,
        ])->all();
    }

    /**
     * Xom hodisalarni shartnoma shakliga keltiradi (ism markaziy auth.users'dan).
     *
     * @param  array<int, array<string, mixed>>  $events
     * @return array<int, array{time: string, advisor: array{name: ?string}|null, type: string, summary: string, ref: ?string}>
     */
    private function present(array $events): array
    {
        $advisorNames = $this->advisorNames(array_column($events, 'advisor_id'));
        $userNames = $this->userNames(array_column($events, 'user_id'));

        return array_map(function ($e) use ($advisorNames, $userNames) {
            $name = $e['advisor_id'] !== null
                ? ($advisorNames[$e['advisor_id']] ?? null)
                : ($e['user_id'] !== null ? ($userNames[$e['user_id']] ?? null) : null);

            return [
                'time' => Carbon::parse($e['time'])->toIso8601String(),
                'advisor' => $name === null ? null : ['name' => $name],
                'type' => $e['type'],
                'summary' => $e['summary'],
                'ref' => $e['ref'],
            ];
        }, $events);
    }

    // ------------------------------------------------------------------- oversight

    /**
     * Har maslahatchi bo'yicha nazorat satri (viloyat/bo'linma). Tuman-darajali
     * maslahatchi metrikasi o'z tumani kesimida; viloyat/bo'linma (tumansiz) uchun
     * BUTUN viloyat yig'indisi. So'nggi faoliyat — barcha oqimlar bo'yicha.
     *
     * @return array<int, array{advisor: array{id: string, name: ?string}, district: array{id: string, name: ?string}|null, level: string, open_tasks: int, overdue: int, reports_pending: int, kpi_avg: ?float, last_activity: ?string}>
     */
    public function oversight(string $period): array
    {
        $advisors = DB::connection('advisor')->table('advisors as a')
            ->leftJoin('districts as d', 'd.id', '=', 'a.district_id')
            ->where('a.active', true)
            ->orderByRaw('d.sort_order asc nulls last')
            ->orderBy('a.level')
            ->get(['a.id', 'a.user_id', 'a.level', 'a.district_id', 'd.name_cyr as district_name']);

        if ($advisors->isEmpty()) {
            return [];
        }

        $openByDistrict = $this->countByDistrict(
            DB::connection('advisor')->table('task_targets')->where('status', '!=', 'closed'),
        );
        $overdueByDistrict = $this->countByDistrict(
            DB::connection('advisor')->table('task_targets')
                ->where('status', '!=', 'closed')
                ->whereNotNull('due_at')
                ->where('due_at', '<', now()),
        );
        $pendingByDistrict = DB::connection('advisor')->table('task_reports as r')
            ->join('task_targets as tt', 'tt.id', '=', 'r.task_target_id')
            ->whereIn('r.status', ['pending', 'qa_checked'])
            ->groupBy('tt.district_id')
            ->selectRaw('tt.district_id, count(*) as n')
            ->pluck('n', 'district_id');

        // KPI o'rtacha ijro % (tuman kesimida) — bitta summary chaqiruvi.
        $kpiAvgByDistrict = [];
        foreach ($this->kpi->summary($period) as $s) {
            $kpiAvgByDistrict[$s['district']['id']] = $s['avg_fulfillment'];
        }

        [$lastByAdvisor, $lastByUser] = $this->lastActivityMaps();

        $openTotal = (int) array_sum($openByDistrict->all());
        $overdueTotal = (int) array_sum($overdueByDistrict->all());
        $pendingTotal = (int) array_sum($pendingByDistrict->all());
        $kpiAvgOverall = $kpiAvgByDistrict === []
            ? null
            : $this->avg(array_values(array_filter($kpiAvgByDistrict, fn ($v) => $v !== null)));

        $names = $this->userNames($advisors->pluck('user_id')->all());

        return $advisors->map(function ($a) use (
            $names, $openByDistrict, $overdueByDistrict, $pendingByDistrict, $kpiAvgByDistrict,
            $lastByAdvisor, $lastByUser, $openTotal, $overdueTotal, $pendingTotal, $kpiAvgOverall,
        ) {
            $did = $a->district_id;
            $hasDistrict = $did !== null;

            $last = $this->maxTime(
                $lastByAdvisor[$a->id] ?? null,
                $a->user_id === null ? null : ($lastByUser[$a->user_id] ?? null),
            );

            return [
                'advisor' => ['id' => $a->id, 'name' => $a->user_id === null ? null : ($names[$a->user_id] ?? null)],
                'district' => $hasDistrict ? ['id' => $did, 'name' => $a->district_name] : null,
                'level' => $a->level,
                'open_tasks' => $hasDistrict ? (int) ($openByDistrict[$did] ?? 0) : $openTotal,
                'overdue' => $hasDistrict ? (int) ($overdueByDistrict[$did] ?? 0) : $overdueTotal,
                'reports_pending' => $hasDistrict ? (int) ($pendingByDistrict[$did] ?? 0) : $pendingTotal,
                'kpi_avg' => $hasDistrict ? ($kpiAvgByDistrict[$did] ?? null) : $kpiAvgOverall,
                'last_activity' => $last,
            ];
        })->all();
    }

    /**
     * Tuman kesimi hisoblagich (task_targets so'roviga status filtri qo'llangan).
     *
     * @param  Builder  $query
     * @return Collection<string, int>
     */
    private function countByDistrict($query)
    {
        return $query->groupBy('district_id')->selectRaw('district_id, count(*) as n')->pluck('n', 'district_id');
    }

    /**
     * So'nggi faoliyat vaqti xaritalari: advisor_id (hisobot) + user_id (topshiriq/
     * loyiha/KPI) bo'yicha maksimum vaqt.
     *
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function lastActivityMaps(): array
    {
        $byAdvisor = DB::connection('advisor')->table('task_reports')
            ->whereNotNull('advisor_id')
            ->groupBy('advisor_id')
            ->selectRaw('advisor_id, max(coalesce(submitted_at, created_at)) as t')
            ->pluck('t', 'advisor_id')
            ->all();

        $byUser = [];
        $merge = function ($rows) use (&$byUser) {
            foreach ($rows as $r) {
                $byUser[$r->uid] = $this->maxTime($byUser[$r->uid] ?? null, $r->t);
            }
        };

        $merge(DB::connection('advisor')->table('tasks')
            ->whereNotNull('created_by')->groupBy('created_by')
            ->selectRaw('created_by as uid, max(created_at) as t')->get());
        $merge(DB::connection('advisor')->table('project_updates')
            ->whereNotNull('user_id')->groupBy('user_id')
            ->selectRaw('user_id as uid, max(coalesce(occurred_at, created_at)) as t')->get());
        $merge(DB::connection('advisor')->table('kpi_entries')
            ->whereNotNull('entered_by')->groupBy('entered_by')
            ->selectRaw('entered_by as uid, max(updated_at) as t')->get());

        return [$byAdvisor, $byUser];
    }

    /** Ikki vaqtdan kattarog'ini ISO'da qaytaradi (yoki null). */
    private function maxTime(?string $a, ?string $b): ?string
    {
        if ($a === null && $b === null) {
            return null;
        }
        if ($a === null) {
            return Carbon::parse($b)->toIso8601String();
        }
        if ($b === null) {
            return Carbon::parse($a)->toIso8601String();
        }

        return Carbon::parse(max(Carbon::parse($a)->getTimestamp(), Carbon::parse($b)->getTimestamp()))->toIso8601String();
    }

    /**
     * @param  array<int, float>  $values
     */
    private function avg(array $values): ?float
    {
        return $values === [] ? null : round(array_sum($values) / count($values), 1);
    }

    // ----------------------------------------------------------------- ism yordamchi

    /**
     * advisor_id -> maslahatchi FIO (auth.users). Cross-schema: PHP'da yig'iladi.
     *
     * @param  array<int, ?string>  $advisorIds
     * @return array<string, ?string>
     */
    private function advisorNames(array $advisorIds): array
    {
        $advisorIds = array_values(array_filter(array_unique($advisorIds)));
        if ($advisorIds === []) {
            return [];
        }

        $advisors = DB::connection('advisor')->table('advisors')
            ->whereIn('id', $advisorIds)->pluck('user_id', 'id');

        $userIds = array_values(array_filter($advisors->all()));
        $names = $userIds === [] ? collect()
            : DB::connection('auth')->table('users')->whereIn('id', $userIds)->pluck('name', 'id');

        $out = [];
        foreach ($advisors as $advisorId => $userId) {
            $out[$advisorId] = $userId === null ? null : ($names[$userId] ?? null);
        }

        return $out;
    }

    /**
     * user_id -> FIO (auth.users). Cross-schema: PHP'da yig'iladi.
     *
     * @param  array<int, ?string>  $userIds
     * @return array<string, ?string>
     */
    private function userNames(array $userIds): array
    {
        $userIds = array_values(array_filter(array_unique($userIds)));
        if ($userIds === []) {
            return [];
        }

        return DB::connection('auth')->table('users')
            ->whereIn('id', $userIds)->pluck('name', 'id')->all();
    }
}
