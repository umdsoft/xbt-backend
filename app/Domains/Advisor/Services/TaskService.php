<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Services;

use App\Domains\Advisor\Models\ReportFile;
use App\Domains\Advisor\Models\Task;
use App\Domains\Advisor\Models\TaskReport;
use App\Domains\Advisor\Models\TaskReview;
use App\Domains\Advisor\Models\TaskTarget;
use App\Domains\Advisor\Support\AdvisorScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TOPSHIRIQ OQIMI + ARXIV (spec §5).
 *
 * Viloyat topshiriq beradi -> tumanlarga tarqatiladi (task_targets) -> tuman
 * hisobot+dalil yuboradi -> bo'linma QA -> viloyat tasdiq/qaytarish -> yopiladi.
 *
 * Qamrov (AdvisorScope) HAR joyda tekshiriladi: tuman FAQAT o'z tumanini
 * ko'radi/ta'sir qiladi (IDOR himoyasi — mahalla naqshi).
 *
 * Barcha yozuv `advisor` ulanishida; district nomlari master.districts'dan
 * (advisor search_path'ida master bor). Maslahatchi FIO markaziy auth.users'dan.
 */
class TaskService
{
    /** Topshiriq holatlari. */
    public const TASK_STATUSES = ['open', 'in_progress', 'closed'];

    /** Nishon (tuman) holatlari. */
    public const TARGET_STATUSES = ['pending', 'reported', 'qa_checked', 'approved', 'returned', 'closed'];

    /** Hisobot holatlari. */
    public const REPORT_STATUSES = ['pending', 'qa_checked', 'approved', 'returned'];

    private function disk(): string
    {
        return (string) config('advisor.files_disk', 'local');
    }

    // ---------------------------------------------------------------- katalog

    /**
     * Topshiriq kategoriyalari — [{id,name}] (frontend picker).
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function categories(): array
    {
        return DB::connection('advisor')->table('task_categories')
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])
            ->all();
    }

    // --------------------------------------------------------------- ro'yxat

    /**
     * Topshiriqlar ro'yxati — qamrov + filtr bilan sahifalab.
     *
     * @param  array<string, mixed>  $filters  status,district_id,category_id,q,from,to
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function listTasks(AdvisorScope $scope, array $filters, int $page = 1, int $perPage = 20): array
    {
        // Tuman FAQAT o'z tumani; viloyat/bo'linma filtr bergan tumani (yoki hammasi).
        $districtId = $scope->isTuman() ? $scope->districtId : ($filters['district_id'] ?? null);

        $base = fn () => DB::connection('advisor')->table('tasks as t')
            ->when(($filters['status'] ?? null) !== null, fn ($q) => $q->where('t.status', $filters['status']))
            ->when(($filters['category_id'] ?? null) !== null, fn ($q) => $q->where('t.category_id', $filters['category_id']))
            ->when(($filters['from'] ?? null) !== null, fn ($q) => $q->whereDate('t.created_at', '>=', $filters['from']))
            ->when(($filters['to'] ?? null) !== null, fn ($q) => $q->whereDate('t.created_at', '<=', $filters['to']))
            ->when(($filters['q'] ?? null) !== null && $filters['q'] !== '', function ($q) use ($filters) {
                $like = '%'.$filters['q'].'%';
                $q->where(fn ($x) => $x->where('t.title', 'ilike', $like)
                    ->orWhere('t.source', 'ilike', $like)
                    ->orWhere('t.description', 'ilike', $like)
                    ->orWhere('t.expected_result', 'ilike', $like));
            })
            ->when($districtId !== null, fn ($q) => $q->whereExists(fn ($sub) => $sub
                ->select(DB::raw(1))->from('task_targets as tt')
                ->whereColumn('tt.task_id', 't.id')
                ->where('tt.district_id', $districtId)));

        // Tuman tumansiz (profil to'liq emas) — hech narsa ko'rmaydi.
        if ($scope->isTuman() && $districtId === null) {
            return ['data' => [], 'meta' => $this->meta(0, 1, $perPage)];
        }

        $total = (int) $base()->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $lastPage));

        $rows = $base()
            ->leftJoin('task_categories as c', 'c.id', '=', 't.category_id')
            ->orderByRaw("array_position(array['open','in_progress','closed']::text[], t.status)")
            ->orderByDesc('t.created_at')
            ->forPage($page, $perPage)
            ->get([
                't.id', 't.source', 't.title', 't.deadline', 't.status', 't.created_at',
                't.category_id', 'c.name as category_name',
            ]);

        $targetsByTask = $this->targetsByTask($rows->pluck('id')->all(), $scope);

        return [
            'data' => $rows->map(fn ($t) => [
                'id' => $t->id,
                'source' => $t->source,
                'title' => $t->title,
                'deadline' => $t->deadline,
                'status' => $t->status,
                'category' => $t->category_id === null ? null
                    : ['id' => $t->category_id, 'name' => $t->category_name],
                'targets' => $targetsByTask[$t->id] ?? [],
                'created_at' => Carbon::parse($t->created_at)->toIso8601String(),
            ])->all(),
            'meta' => $this->meta($total, $page, $perPage),
        ];
    }

    /**
     * Berilgan topshiriqlar bo'yicha nishonlar (qisqa: district+status) — task_id kesimida.
     *
     * @param  array<int, string>  $taskIds
     * @return array<string, array<int, array{district: array{id: string, name: ?string}, status: string}>>
     */
    private function targetsByTask(array $taskIds, AdvisorScope $scope): array
    {
        if ($taskIds === []) {
            return [];
        }

        $rows = DB::connection('advisor')->table('task_targets as tt')
            ->leftJoin('districts as d', 'd.id', '=', 'tt.district_id')
            ->whereIn('tt.task_id', $taskIds)
            ->when($scope->isTuman(), fn ($q) => $q->where('tt.district_id', $scope->districtId))
            ->orderBy('d.sort_order')
            ->get(['tt.task_id', 'tt.district_id', 'tt.status', 'd.name_cyr as district_name']);

        $out = [];
        foreach ($rows as $r) {
            $out[$r->task_id][] = [
                'district' => ['id' => $r->district_id, 'name' => $r->district_name],
                'status' => $r->status,
            ];
        }

        return $out;
    }

    // --------------------------------------------------------------- yaratish

    /**
     * Yangi topshiriq + tumanlarga tarqatish (task_targets) + teglar.
     *
     * @param  array<string, mixed>  $data
     * @return string yaratilgan topshiriq id
     */
    public function createTask(array $data, string $creatorUserId): string
    {
        return DB::connection('advisor')->transaction(function () use ($data, $creatorUserId) {
            $deadline = $data['deadline'] ?? null;

            $task = Task::create([
                'source' => $data['source'] ?? null,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'expected_result' => $data['expected_result'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'priority' => $data['priority'] ?? 'normal',
                'deadline' => $deadline,
                'created_by' => $creatorUserId,
                'status' => 'open',
            ]);

            $dueAt = $deadline !== null ? Carbon::parse($deadline)->endOfDay() : null;

            foreach (array_unique($data['district_ids']) as $districtId) {
                TaskTarget::create([
                    'task_id' => $task->id,
                    'district_id' => $districtId,
                    'assigned_advisor_id' => $this->advisorForDistrict((string) $districtId),
                    'due_at' => $dueAt,
                    'status' => 'pending',
                ]);
            }

            foreach ($this->cleanTags($data['tags'] ?? []) as $tag) {
                DB::connection('advisor')->table('task_tags')->insertOrIgnore([
                    'task_id' => $task->id,
                    'tag' => $tag,
                ]);
            }

            return $task->id;
        });
    }

    /** Tumanning faol maslahatchisi (avto-biriktirish uchun) — yoki null. */
    private function advisorForDistrict(string $districtId): ?string
    {
        return DB::connection('advisor')->table('advisors')
            ->where('district_id', $districtId)
            ->where('level', 'tuman')
            ->where('active', true)
            ->value('id');
    }

    // ----------------------------------------------------------------- ko'rish

    /**
     * Bitta topshiriq — nishonlar + hisobotlar (dalil bilan). Qamrov: tuman
     * o'z tumanini ko'radi; boshqa tumandagi topshiriqni ochsa null (404).
     *
     * @return array<string, mixed>|null
     */
    public function showTask(string $taskId, AdvisorScope $scope): ?array
    {
        $t = DB::connection('advisor')->table('tasks as t')
            ->leftJoin('task_categories as c', 'c.id', '=', 't.category_id')
            ->where('t.id', $taskId)
            ->first([
                't.id', 't.source', 't.title', 't.description', 't.expected_result',
                't.priority', 't.deadline', 't.status', 't.created_by', 't.created_at',
                't.category_id', 'c.name as category_name',
            ]);

        if ($t === null) {
            return null;
        }

        $targetsQuery = DB::connection('advisor')->table('task_targets as tt')
            ->leftJoin('districts as d', 'd.id', '=', 'tt.district_id')
            ->where('tt.task_id', $taskId)
            ->when($scope->isTuman(), fn ($q) => $q->where('tt.district_id', $scope->districtId))
            ->orderBy('d.sort_order');

        $targets = $targetsQuery->get([
            'tt.id', 'tt.district_id', 'tt.status', 'tt.due_at', 'd.name_cyr as district_name',
        ]);

        // Tuman o'z tumanida nishoni bo'lmagan topshiriqni ko'rmaydi.
        if ($scope->isTuman() && $targets->isEmpty()) {
            return null;
        }

        $targetIds = $targets->pluck('id')->all();

        // Barcha hisobotlar (dalil + FIO bilan) — target_id bilan (eng yangi birinchi).
        $reports = $this->reportsForTargets($targetIds);

        // Har nishonning oxirgi hisoboti — shu ro'yxatdan (DRY): global desc tartib
        // har target ichida ham desc bo'lgani uchun BIRINCHI uchragan = eng yangi.
        $latestByTarget = [];
        foreach ($reports as $r) {
            $latestByTarget[$r['target_id']] ??= $r;
        }

        return [
            'task' => [
                'id' => $t->id,
                'source' => $t->source,
                'title' => $t->title,
                'description' => $t->description,
                'expected_result' => $t->expected_result,
                'priority' => $t->priority,
                'deadline' => $t->deadline,
                'status' => $t->status,
                'category' => $t->category_id === null ? null
                    : ['id' => $t->category_id, 'name' => $t->category_name],
                'tags' => $this->tagsFor($taskId),
                'created_at' => Carbon::parse($t->created_at)->toIso8601String(),
            ],
            'targets' => $targets->map(fn ($tt) => [
                'id' => $tt->id,
                'district' => ['id' => $tt->district_id, 'name' => $tt->district_name],
                'status' => $tt->status,
                'due_at' => $tt->due_at === null ? null : Carbon::parse($tt->due_at)->toIso8601String(),
                'report' => $latestByTarget[$tt->id] ?? null,
            ])->all(),
            'reports' => $reports,
        ];
    }

    /**
     * Nishonlar bo'yicha barcha hisobotlar (dalil fayllari + maslahatchi FIO).
     * Eng yangi hisobot birinchi (created_at desc). Har element `target_id` bilan
     * (frontend: nishon -> oxirgi hisobot moslashuvi).
     *
     * @param  array<int, string>  $targetIds
     * @return array<int, array<string, mixed>>
     */
    private function reportsForTargets(array $targetIds): array
    {
        if ($targetIds === []) {
            return [];
        }

        $reports = DB::connection('advisor')->table('task_reports')
            ->whereIn('task_target_id', $targetIds)
            ->orderByDesc('created_at')
            ->get(['id', 'task_target_id', 'advisor_id', 'body', 'status', 'submitted_at']);

        if ($reports->isEmpty()) {
            return [];
        }

        $reportIds = $reports->pluck('id')->all();

        $files = DB::connection('advisor')->table('report_files')
            ->whereIn('report_id', $reportIds)
            ->orderBy('created_at')
            ->get(['id', 'report_id', 'kind', 'url', 'geo', 'original_name']);
        $filesByReport = [];
        foreach ($files as $f) {
            $filesByReport[$f->report_id][] = $this->presentFile($f);
        }

        // Ko'rib chiqish izlari (QA/tasdiq/qaytarish) — qaytarish sababi tumanga ko'rinsin.
        $reviewsByReport = $this->reviewsForReports($reportIds);

        $names = $this->advisorNames($reports->pluck('advisor_id')->all());

        return $reports->map(fn ($r) => [
            'id' => $r->id,
            'target_id' => $r->task_target_id,
            'body' => $r->body,
            'status' => $r->status,
            'submitted_at' => $r->submitted_at === null ? null : Carbon::parse($r->submitted_at)->toIso8601String(),
            'advisor' => ['name' => $r->advisor_id === null ? null : ($names[$r->advisor_id] ?? null)],
            'files' => $filesByReport[$r->id] ?? [],
            'reviews' => $reviewsByReport[$r->id] ?? [],
        ])->all();
    }

    /**
     * Hisobotlar bo'yicha ko'rib chiqish izlari (QA/tasdiq/qaytarish) — audit +
     * qaytarish sababi. Xronologik (eng eski birinchi). reviewer_id -> auth.users FIO.
     *
     * @param  array<int, string>  $reportIds
     * @return array<string, array<int, array{action: string, comment: ?string, at: ?string, reviewer: ?string}>>
     */
    private function reviewsForReports(array $reportIds): array
    {
        if ($reportIds === []) {
            return [];
        }

        $reviews = DB::connection('advisor')->table('task_reviews')
            ->whereIn('report_id', $reportIds)
            ->orderBy('created_at')
            ->get(['report_id', 'reviewer_id', 'action', 'comment', 'created_at']);

        if ($reviews->isEmpty()) {
            return [];
        }

        $names = $this->userNames($reviews->pluck('reviewer_id')->all());

        $out = [];
        foreach ($reviews as $rv) {
            $out[$rv->report_id][] = [
                'action' => $rv->action,
                'comment' => $rv->comment,
                'at' => $rv->created_at === null ? null : Carbon::parse($rv->created_at)->toIso8601String(),
                'reviewer' => $rv->reviewer_id === null ? null : ($names[$rv->reviewer_id] ?? null),
            ];
        }

        return $out;
    }

    /**
     * auth.users id -> FIO (ko'rib chiquvchi nomi uchun). Cross-schema.
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
            ->whereIn('id', $userIds)
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Dalil faylini frontend shartnomasiga moslab beradi: fayl/foto -> vakolatli
     * stream route orqali (url null); havola -> tashqi url; GPS -> geo koordinata +
     * xarita havolasi (frontend ReportFiles: `url ?? original_name` bilan ochadi).
     *
     * @return array{id: string, kind: string, original_name: ?string, url: ?string, geo: array<string, mixed>|null}
     */
    private function presentFile(object $f): array
    {
        $geo = null;
        $url = null;
        $originalName = $f->original_name;

        if ($f->kind === 'link') {
            // Havola: original_name bo'lmasa url ko'rsatiladi.
            $url = $f->url;
            $originalName ??= $f->url;
        } elseif ($f->kind === 'gps') {
            $geo = is_string($f->geo) ? json_decode($f->geo, true) : $f->geo;
            if (is_array($geo) && isset($geo['lat'], $geo['lng'])) {
                $lat = (float) $geo['lat'];
                $lng = (float) $geo['lng'];
                $originalName = round($lat, 6).', '.round($lng, 6);
                $url = 'https://maps.google.com/?q='.$lat.','.$lng;
            }
        }

        return [
            'id' => $f->id,
            'kind' => $f->kind,
            'original_name' => $originalName,
            'url' => $url,
            'geo' => $geo,
        ];
    }

    // ------------------------------------------------------------- hisobot

    /**
     * Hisobot + dalil yuborish (tuman). Nishon 'reported' bo'ladi, topshiriq
     * 'in_progress'. Har chaqiruv YANGI hisobot (qaytarilsa qayta yuboriladi).
     *
     * @param  array<int, UploadedFile>  $files  dalil fayllari/rasmlari
     * @param  array<int, string>  $links  havola dalillari
     * @param  array{lat: float, lng: float, accuracy?: float}|null  $gps
     * @return string hisobot id
     */
    public function submitReport(
        TaskTarget $target,
        string $advisorUserId,
        ?string $advisorId,
        ?string $body,
        array $files = [],
        array $links = [],
        ?array $gps = null,
    ): string {
        return DB::connection('advisor')->transaction(function () use ($target, $advisorUserId, $advisorId, $body, $files, $links, $gps) {
            $report = TaskReport::create([
                'task_target_id' => $target->id,
                'advisor_id' => $advisorId,
                'body' => $body,
                'submitted_at' => now(),
                'status' => 'pending',
            ]);

            foreach ($files as $file) {
                $path = $file->store("advisor/reports/{$report->id}", $this->disk());
                $mime = (string) $file->getClientMimeType();
                ReportFile::create([
                    'report_id' => $report->id,
                    'kind' => str_starts_with($mime, 'image/') ? 'photo' : 'file',
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime' => $mime,
                    'size_bytes' => $file->getSize(),
                    'uploaded_by' => $advisorUserId,
                ]);
            }

            foreach ($links as $url) {
                $url = trim((string) $url);
                if ($url === '') {
                    continue;
                }
                ReportFile::create([
                    'report_id' => $report->id,
                    'kind' => 'link',
                    'url' => $url,
                    'uploaded_by' => $advisorUserId,
                ]);
            }

            if ($gps !== null && isset($gps['lat'], $gps['lng'])) {
                ReportFile::create([
                    'report_id' => $report->id,
                    'kind' => 'gps',
                    'geo' => $gps,
                    'uploaded_by' => $advisorUserId,
                ]);
            }

            $target->update(['status' => 'reported']);

            // Birinchi hisobot kelganda topshiriq jarayonga o'tadi.
            DB::connection('advisor')->table('tasks')
                ->where('id', $target->task_id)
                ->where('status', 'open')
                ->update(['status' => 'in_progress', 'updated_at' => now()]);

            return $report->id;
        });
    }

    // ---------------------------------------------------------- QA / tasdiq

    /**
     * Sifat-tekshiruv (bo'linma|viloyat): hisobot + nishon 'qa_checked'.
     */
    public function qaReport(TaskReport $report, string $reviewerUserId, ?string $note): void
    {
        DB::connection('advisor')->transaction(function () use ($report, $reviewerUserId, $note) {
            $report->update(['status' => 'qa_checked']);
            TaskTarget::whereKey($report->task_target_id)->update(['status' => 'qa_checked', 'updated_at' => now()]);
            $this->review($report->id, $reviewerUserId, 'qa_check', $note);
        });
    }

    /**
     * Tasdiq (viloyat): hisobot 'approved', nishon 'closed'. Barcha nishon
     * yopilsa topshiriq 'closed' (arxivga o'tadi).
     */
    public function approveReport(TaskReport $report, string $reviewerUserId, ?string $note = null): void
    {
        DB::connection('advisor')->transaction(function () use ($report, $reviewerUserId, $note) {
            $report->update(['status' => 'approved']);

            $target = TaskTarget::findOrFail($report->task_target_id);
            $target->update(['status' => 'closed']);
            $this->review($report->id, $reviewerUserId, 'approve', $note);

            // Topshiriqning barcha nishoni yopilgan bo'lsa — topshiriq yopiladi.
            $open = DB::connection('advisor')->table('task_targets')
                ->where('task_id', $target->task_id)
                ->where('status', '!=', 'closed')
                ->exists();

            if (! $open) {
                DB::connection('advisor')->table('tasks')
                    ->where('id', $target->task_id)
                    ->update(['status' => 'closed', 'updated_at' => now()]);
            }
        });
    }

    /**
     * Qaytarish (viloyat|bo'linma) izoh bilan: hisobot + nishon 'returned'
     * (tuman qayta yuborishi mumkin).
     */
    public function returnReport(TaskReport $report, string $reviewerUserId, string $comment): void
    {
        DB::connection('advisor')->transaction(function () use ($report, $reviewerUserId, $comment) {
            $report->update(['status' => 'returned']);
            TaskTarget::whereKey($report->task_target_id)->update(['status' => 'returned', 'updated_at' => now()]);
            $this->review($report->id, $reviewerUserId, 'return', $comment);
        });
    }

    private function review(string $reportId, string $reviewerUserId, string $action, ?string $comment): void
    {
        TaskReview::create([
            'report_id' => $reportId,
            'reviewer_id' => $reviewerUserId,
            'action' => $action,
            'comment' => $comment,
        ]);
    }

    // ------------------------------------------------------------------ arxiv

    /**
     * Arxiv qidiruv (bilim bazasi): topshiriq + hisobot bo'yicha erkin qidiruv +
     * filtr (tuman, kategoriya, teg, sana, holat). Qamrov: tuman o'z tumani.
     *
     * @param  array<string, mixed>  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function archiveSearch(AdvisorScope $scope, array $filters, int $page = 1, int $perPage = 20): array
    {
        $base = fn () => $this->archiveBaseQuery($scope, $filters);

        if ($scope->isTuman() && $scope->districtId === null) {
            return ['data' => [], 'meta' => $this->meta(0, 1, $perPage)];
        }

        $total = (int) $base()->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $lastPage));

        $rows = $base()
            ->leftJoin('task_categories as c', 'c.id', '=', 't.category_id')
            ->orderByDesc('t.created_at')
            ->forPage($page, $perPage)
            ->get([
                't.id', 't.source', 't.title', 't.deadline', 't.status', 't.created_at',
                't.category_id', 'c.name as category_name',
            ]);

        $targetsByTask = $this->targetsByTask($rows->pluck('id')->all(), $scope);
        $tagsByTask = $this->tagsForMany($rows->pluck('id')->all());

        return [
            'data' => $rows->map(fn ($t) => [
                'id' => $t->id,
                'source' => $t->source,
                'title' => $t->title,
                'deadline' => $t->deadline,
                'status' => $t->status,
                'category' => $t->category_id === null ? null
                    : ['id' => $t->category_id, 'name' => $t->category_name],
                'districts' => array_values(array_filter(array_map(
                    fn ($tt) => $tt['district']['name'],
                    $targetsByTask[$t->id] ?? [],
                ))),
                'tags' => $tagsByTask[$t->id] ?? [],
                'created_at' => Carbon::parse($t->created_at)->toIso8601String(),
            ])->all(),
            'meta' => $this->meta($total, $page, $perPage),
        ];
    }

    /**
     * Arxiv qidiruv asosiy so'rovi (search + export baham ko'radi).
     *
     * @param  array<string, mixed>  $filters
     * @return Builder
     */
    private function archiveBaseQuery(AdvisorScope $scope, array $filters)
    {
        $districtId = $scope->isTuman() ? $scope->districtId : ($filters['district_id'] ?? null);

        return DB::connection('advisor')->table('tasks as t')
            ->when(($filters['status'] ?? null) !== null, fn ($q) => $q->where('t.status', $filters['status']))
            ->when(($filters['category_id'] ?? null) !== null, fn ($q) => $q->where('t.category_id', $filters['category_id']))
            ->when(($filters['from'] ?? null) !== null, fn ($q) => $q->whereDate('t.created_at', '>=', $filters['from']))
            ->when(($filters['to'] ?? null) !== null, fn ($q) => $q->whereDate('t.created_at', '<=', $filters['to']))
            ->when(($filters['tag'] ?? null) !== null && $filters['tag'] !== '', fn ($q) => $q->whereExists(fn ($sub) => $sub
                ->select(DB::raw(1))->from('task_tags as tg')
                ->whereColumn('tg.task_id', 't.id')
                ->where('tg.tag', $filters['tag'])))
            ->when(($filters['q'] ?? null) !== null && $filters['q'] !== '', function ($q) use ($filters) {
                $like = '%'.$filters['q'].'%';
                $q->where(function ($x) use ($like) {
                    $x->where('t.title', 'ilike', $like)
                        ->orWhere('t.source', 'ilike', $like)
                        ->orWhere('t.description', 'ilike', $like)
                        ->orWhere('t.expected_result', 'ilike', $like)
                        // hisobot matnida ham qidiramiz (bilim bazasi).
                        ->orWhereExists(fn ($sub) => $sub
                            ->select(DB::raw(1))
                            ->from('task_reports as r')
                            ->join('task_targets as rt', 'rt.id', '=', 'r.task_target_id')
                            ->whereColumn('rt.task_id', 't.id')
                            ->where('r.body', 'ilike', $like));
                });
            })
            ->when($districtId !== null, fn ($q) => $q->whereExists(fn ($sub) => $sub
                ->select(DB::raw(1))->from('task_targets as tt')
                ->whereColumn('tt.task_id', 't.id')
                ->where('tt.district_id', $districtId)));
    }

    /**
     * Arxiv eksport satrlari (hisobot kesimida): FIO, tuman, topshiriq, holat, sana.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    public function archiveExportRows(AdvisorScope $scope, array $filters): array
    {
        if ($scope->isTuman() && $scope->districtId === null) {
            return [];
        }

        // Filtrlangan topshiriqlar to'plami (id).
        $taskIds = $this->archiveBaseQuery($scope, $filters)->pluck('t.id')->all();
        if ($taskIds === []) {
            return [];
        }

        $districtId = $scope->isTuman() ? $scope->districtId : ($filters['district_id'] ?? null);

        $rows = DB::connection('advisor')->table('task_reports as r')
            ->join('task_targets as tt', 'tt.id', '=', 'r.task_target_id')
            ->join('tasks as t', 't.id', '=', 'tt.task_id')
            ->leftJoin('districts as d', 'd.id', '=', 'tt.district_id')
            ->whereIn('tt.task_id', $taskIds)
            ->when($districtId !== null, fn ($q) => $q->where('tt.district_id', $districtId))
            ->orderBy('d.sort_order')
            ->orderByDesc('r.submitted_at')
            ->get([
                'r.advisor_id', 'r.status', 'r.submitted_at',
                't.title as task_title', 'd.name_cyr as district_name', 't.created_at',
            ]);

        $names = $this->advisorNames($rows->pluck('advisor_id')->all());

        return $rows->map(fn ($r) => [
            $r->advisor_id === null ? '' : ($names[$r->advisor_id] ?? ''),
            (string) ($r->district_name ?? ''),
            (string) $r->task_title,
            $this->reportStatusLabel($r->status),
            Carbon::parse($r->submitted_at ?? $r->created_at)->format('Y-m-d'),
        ])->all();
    }

    // ------------------------------------------------------ intizom statistikasi

    /**
     * Ijro intizomi statistikasi (nazorat paneli / stage-5 uchun tayyor): qamrov
     * ichidagi nishonlar holat kesimida + yopish/qaytarish darajasi.
     *
     * @return array<string, int|float>
     */
    public function disciplineStats(AdvisorScope $scope): array
    {
        $counts = DB::connection('advisor')->table('task_targets as tt')
            ->when($scope->isTuman(), fn ($q) => $q->where('tt.district_id', $scope->districtId))
            ->groupBy('tt.status')
            ->selectRaw('tt.status, count(*) as n')
            ->pluck('n', 'status');

        $out = ['total' => 0];
        foreach (self::TARGET_STATUSES as $s) {
            $out[$s] = (int) ($counts[$s] ?? 0);
            $out['total'] += $out[$s];
        }

        $total = max(1, $out['total']);
        $out['closed_rate'] = round($out['closed'] / $total * 100, 1);
        $out['returned_rate'] = round($out['returned'] / $total * 100, 1);

        return $out;
    }

    // ----------------------------------------------------------------- yordamchi

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

        // advisor.advisors: id -> user_id
        $advisors = DB::connection('advisor')->table('advisors')
            ->whereIn('id', $advisorIds)
            ->pluck('user_id', 'id');

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
     * @return array<int, string>
     */
    private function tagsFor(string $taskId): array
    {
        return DB::connection('advisor')->table('task_tags')
            ->where('task_id', $taskId)->orderBy('tag')->pluck('tag')->all();
    }

    /**
     * @param  array<int, string>  $taskIds
     * @return array<string, array<int, string>>
     */
    private function tagsForMany(array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }

        $rows = DB::connection('advisor')->table('task_tags')
            ->whereIn('task_id', $taskIds)->orderBy('tag')->get(['task_id', 'tag']);

        $out = [];
        foreach ($rows as $r) {
            $out[$r->task_id][] = $r->tag;
        }

        return $out;
    }

    /**
     * @param  mixed  $tags
     * @return array<int, string>
     */
    private function cleanTags($tags): array
    {
        if (! is_array($tags)) {
            return [];
        }

        $clean = [];
        foreach ($tags as $tag) {
            $tag = Str::of((string) $tag)->trim()->limit(60, '')->value();
            if ($tag !== '') {
                $clean[$tag] = $tag;
            }
        }

        return array_values($clean);
    }

    private function reportStatusLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'Кутилмоқда',
            'qa_checked' => 'Сифат текширувдан ўтди',
            'approved' => 'Тасдиқланди',
            'returned' => 'Қайтарилди',
            default => $status,
        };
    }

    /**
     * @return array{total: int, current_page: int, last_page: int, per_page: int}
     */
    private function meta(int $total, int $page, int $perPage): array
    {
        return [
            'total' => $total,
            'current_page' => $page,
            'last_page' => max(1, (int) ceil($total / $perPage)),
            'per_page' => $perPage,
        ];
    }
}
