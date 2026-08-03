<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Http\Controllers\Api;

use App\Domains\Advisor\Models\ReportFile;
use App\Domains\Advisor\Models\TaskReport;
use App\Domains\Advisor\Services\TaskService;
use App\Domains\Advisor\Support\AdvisorAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * HISOBOT ustidan amallar: QA (bo'linma|viloyat), tasdiq (viloyat),
 * qaytarish (viloyat|bo'linma) + dalil faylini uzatish (spec §5).
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly AdvisorAccess $access,
        private readonly TaskService $tasks,
    ) {}

    /** Sifat-tekshiruv (bo'linma|viloyat) -> qa_checked. */
    public function qa(Request $request, string $report): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'reports.qa'), 403, 'Сифат текширувга рухсат йўқ.');

        $v = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        $model = $this->findReport($report);

        $this->tasks->qaReport($model, (string) $request->user()->id, $v['note'] ?? null);

        return response()->json(['ok' => true, 'status' => 'qa_checked']);
    }

    /** Tasdiq (viloyat) -> approved; nishon closed. */
    public function approve(Request $request, string $report): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'reports.approve'), 403, 'Тасдиқлашга рухсат йўқ.');

        $v = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        $model = $this->findReport($report);

        $this->tasks->approveReport($model, (string) $request->user()->id, $v['note'] ?? null);

        return response()->json(['ok' => true, 'status' => 'approved']);
    }

    /** Qaytarish (viloyat|bo'linma) izoh bilan -> returned. */
    public function return(Request $request, string $report): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'reports.return'), 403, 'Қайтаришга рухсат йўқ.');

        $v = $request->validate(['comment' => ['required', 'string', 'max:2000']]);
        $model = $this->findReport($report);

        $this->tasks->returnReport($model, (string) $request->user()->id, $v['comment']);

        return response()->json(['ok' => true, 'status' => 'returned']);
    }

    /**
     * Dalil faylini maxfiy diskdan uzatish (URL orqali ochib bo'lmaydi;
     * mahalla PhotoController naqshi). Tuman FAQAT o'z tumani fayllarini ko'radi.
     */
    public function file(Request $request, string $file): StreamedResponse
    {
        $scope = $this->access->scopeFor($request->user());

        // Qamrov: tuman boshqa tuman dalilini ko'ra olmasin (IDOR himoyasi).
        $allowed = DB::connection('advisor')->table('report_files as rf')
            ->join('task_reports as r', 'r.id', '=', 'rf.report_id')
            ->join('task_targets as tt', 'tt.id', '=', 'r.task_target_id')
            ->where('rf.id', $file)
            ->when($scope->isTuman(), fn ($q) => $q->where('tt.district_id', $scope->districtId))
            ->value('rf.id');

        if ($allowed === null) {
            throw new NotFoundHttpException;
        }

        $rf = ReportFile::findOrFail($file);
        if (! in_array($rf->kind, ['file', 'photo'], true) || $rf->path === null) {
            throw new NotFoundHttpException;
        }

        $disk = (string) config('advisor.files_disk', 'local');
        if (! Storage::disk($disk)->exists($rf->path)) {
            throw new NotFoundHttpException;
        }

        return Storage::disk($disk)->response($rf->path, $rf->original_name);
    }

    private function findReport(string $report): TaskReport
    {
        $model = TaskReport::find($report);
        if ($model === null) {
            throw new NotFoundHttpException('Ҳисобот топилмади');
        }

        return $model;
    }
}
