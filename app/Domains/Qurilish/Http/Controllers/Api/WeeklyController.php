<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Http\Controllers\Api;

use App\Domains\Qurilish\Models\ConstructionObject;
use App\Domains\Qurilish\Models\ObjectMedia;
use App\Domains\Qurilish\Models\WeeklyReport;
use App\Domains\Qurilish\Services\ObjectService;
use App\Domains\Qurilish\Services\WeeklyReportService;
use App\Domains\Qurilish\Support\QurilishAccess;
use App\Domains\Qurilish\Support\QurilishScope;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Haftalik ijro hisoboti va uning arxivi.
 *
 * Arxiv — shu domenning eng qimmatli mahsuloti: obyekt topshirilgandan
 * keyin ham «qaysi haftada nima bo'lgani» surat bilan birga qolib ketadi.
 */
class WeeklyController extends QurilishController
{
    public function __construct(
        QurilishAccess $access,
        private readonly ObjectService $objects,
        private readonly WeeklyReportService $weekly,
        private readonly QurilishScope $scope,
    ) {
        parent::__construct($access);
    }

    public function index(Request $request, string $objectId): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.view');
        $object = $this->objects->findOrFail($request->user(), $objectId);

        $year = $request->query('year') === null ? null : (int) $request->query('year');
        $rows = $this->weekly->archive($object, $year);

        return response()->json([
            'data' => $rows->map(fn (WeeklyReport $r) => $this->present($r, $request->user()))->all(),
            // Kiritilmagan haftalar — nazoratning asosiy signali.
            'missing' => $this->weekly->missingWeeks($object),
            'years' => $rows->pluck('year')->unique()->sortDesc()->values()->all(),
            'can_fill' => $this->access->can($request->user(), 'qurilish.stage.update'),
            'can_moderate' => $this->access->can($request->user(), 'qurilish.stage.moderate'),
        ]);
    }

    public function store(Request $request, string $objectId): JsonResponse
    {
        $object = $this->objects->findOrFail($request->user(), $objectId);

        $data = $request->validate([
            'date' => ['nullable', 'date'],
            'year' => ['nullable', 'integer', 'min:2020', 'max:2050'],
            'week_no' => ['nullable', 'integer', 'min:1', 'max:53'],
            'progress_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'disbursed_amount' => ['nullable', 'numeric', 'min:0'],
            'workers_count' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'equipment_count' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'works_done' => ['nullable', 'string', 'max:5000'],
            'problems' => ['nullable', 'string', 'max:5000'],
        ]);

        $report = $this->weekly->saveDraft($object, $data, $request->user());

        return response()->json(['data' => $this->present($report, $request->user())], 201);
    }

    public function submit(Request $request, string $objectId, string $reportId): JsonResponse
    {
        [$object, $report] = $this->pair($request, $objectId, $reportId);

        return response()->json([
            'data' => $this->present($this->weekly->submit($report, $object, $request->user()), $request->user()),
        ]);
    }

    public function review(Request $request, string $objectId, string $reportId): JsonResponse
    {
        [$object, $report] = $this->pair($request, $objectId, $reportId);

        return response()->json([
            'data' => $this->present($this->weekly->review($report, $object, $request->user()), $request->user()),
        ]);
    }

    public function approve(Request $request, string $objectId, string $reportId): JsonResponse
    {
        [$object, $report] = $this->pair($request, $objectId, $reportId);

        return response()->json([
            'data' => $this->present($this->weekly->approve($report, $object, $request->user()), $request->user()),
        ]);
    }

    public function reject(Request $request, string $objectId, string $reportId): JsonResponse
    {
        [$object, $report] = $this->pair($request, $objectId, $reportId);

        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);

        return response()->json([
            'data' => $this->present(
                $this->weekly->reject($report, $object, $data['reason'], $request->user()),
                $request->user(),
            ),
        ]);
    }

    /**
     * Butun portfel bo'yicha haftalik hisobot navbati.
     * Prokuratura uchun: qaysi obyektlar hisobot yubordi.
     */
    public function queue(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeAction($user, 'qurilish.view');

        $visible = $this->scope->apply(ConstructionObject::query()->select('id'), $user);

        $rows = WeeklyReport::query()
            ->whereIn('status', WeeklyReport::PENDING_STATUSES)
            ->whereIn('object_id', $visible)
            ->orderBy('submitted_at')
            ->limit(200)
            ->get();

        $objects = ConstructionObject::query()
            ->whereIn('id', $rows->pluck('object_id')->unique()->all())
            ->get(['id', 'name'])
            ->keyBy('id');

        return response()->json([
            'data' => $rows->map(fn (WeeklyReport $r) => [
                'id' => $r->id,
                'object_id' => $r->object_id,
                'object_name' => $objects[$r->object_id]->name ?? null,
                'year' => $r->year,
                'week_no' => $r->week_no,
                'period_start' => $r->period_start?->toDateString(),
                'period_end' => $r->period_end?->toDateString(),
                'progress_pct' => $r->progress_pct,
                'status' => $r->status,
                'submitted_at' => $r->submitted_at?->toIso8601String(),
            ])->all(),
            'total' => $rows->count(),
        ]);
    }

    /** @return array{0: ConstructionObject, 1: WeeklyReport} */
    private function pair(Request $request, string $objectId, string $reportId): array
    {
        $object = $this->objects->findOrFail($request->user(), $objectId);

        $report = WeeklyReport::query()
            ->where('object_id', $object->id)->whereKey($reportId)->firstOrFail();

        return [$object, $report];
    }

    /** @return array<string, mixed> */
    private function present(WeeklyReport $report, User $user): array
    {
        $media = $report->relationLoaded('media')
            ? $report->media
            : ObjectMedia::query()->where('weekly_report_id', $report->id)
                ->orderByRaw('taken_at asc nulls last')->get();

        return [
            'id' => $report->id,
            'year' => $report->year,
            'week_no' => $report->week_no,
            'period_start' => $report->period_start?->toDateString(),
            'period_end' => $report->period_end?->toDateString(),
            'progress_pct' => $report->progress_pct,
            'week_progress_pct' => $report->week_progress_pct,
            'disbursed_amount' => $report->disbursed_amount,
            'workers_count' => $report->workers_count,
            'equipment_count' => $report->equipment_count,
            'works_done' => $report->works_done,
            'problems' => $report->problems,
            'status' => $report->status,
            'rejection_reason' => $report->rejection_reason,
            'submitted_at' => $report->submitted_at?->toIso8601String(),
            'reviewed_at' => $report->reviewed_at?->toIso8601String(),
            'media' => $media->map(fn (ObjectMedia $m) => [
                'id' => $m->id,
                'kind' => $m->kind,
                'title' => $m->title,
                'taken_at' => $m->taken_at?->toDateString(),
                'url' => "/api/qurilish/objects/{$m->object_id}/media/{$m->id}/file",
            ])->values()->all(),
            'actions' => $this->actionsFor($report, $user),
        ];
    }

    /** @return array<int, string> */
    private function actionsFor(WeeklyReport $report, User $user): array
    {
        $canFill = $this->access->can($user, 'qurilish.stage.update');
        $canModerate = $this->access->can($user, 'qurilish.stage.moderate');
        $actions = [];

        if ($canFill && in_array($report->status, WeeklyReport::EDITABLE_STATUSES, true)) {
            $actions[] = 'draft';
            $actions[] = 'submit';
        }
        if ($canModerate && $report->status === 'tasdiqlash_kutilmoqda') {
            $actions[] = 'review';
        }
        if ($canModerate && in_array($report->status, WeeklyReport::PENDING_STATUSES, true)) {
            $actions[] = 'approve';
            $actions[] = 'reject';
        }

        return $actions;
    }
}
