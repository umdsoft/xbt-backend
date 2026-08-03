<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Http\Controllers\Api;

use App\Domains\Advisor\Services\TaskService;
use App\Domains\Advisor\Support\AdvisorAccess;
use App\Http\Controllers\Controller;
use App\Support\SimpleXlsx;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ARXIV / bilim bazasi (spec §5): topshiriq+hisobot qidiruv + XLSX eksport.
 * "Kelgusida yana so'ralganda darhol topiladi." Qamrov: tuman o'z tumani.
 */
class ArchiveController extends Controller
{
    public function __construct(
        private readonly AdvisorAccess $access,
        private readonly TaskService $tasks,
    ) {}

    /** Arxiv qidiruv (filtr + sahifalash). */
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'archive.view'), 403);

        $v = $this->filters($request);
        $scope = $this->access->scopeFor($request->user());

        return response()->json($this->tasks->archiveSearch($scope, $v, (int) ($v['page'] ?? 1)));
    }

    /** Arxiv eksport (xlsx): Ф.И.О / Туман / Топшириқ / Ҳолат / Сана. */
    public function export(Request $request): Response
    {
        abort_unless($this->access->can($request->user(), 'archive.export'), 403);

        $v = $this->filters($request);
        $scope = $this->access->scopeFor($request->user());

        $rows = $this->tasks->archiveExportRows($scope, $v);

        $xlsx = SimpleXlsx::build(
            ['Ф.И.О', 'Туман', 'Топшириқ', 'Ҳолат', 'Сана'],
            $rows,
            'Архив',
        );

        return response($xlsx, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="advisor-arxiv.xlsx"',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'district_id' => ['nullable', 'uuid'],
            'category_id' => ['nullable', 'uuid'],
            'tag' => ['nullable', 'string', 'max:60'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'in:open,in_progress,closed'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
    }
}
