<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Http\Controllers\Api;

use App\Domains\Yoshlar\Models\PatronageLog;
use App\Domains\Yoshlar\Models\YouthCase;
use App\Domains\Yoshlar\Services\CaseService;
use App\Domains\Yoshlar\Support\YoshlarAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CaseController extends Controller
{
    public function __construct(
        private readonly CaseService $service,
        private readonly YoshlarAccess $access,
    ) {}

    // ---------------- Muammolar ----------------

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAction($request, 'yoshlar.case.view');

        $page = $this->service->paginate($request->user(), $request->query());

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $this->authorizeAction($request, 'yoshlar.case.view');

        return response()->json($this->service->caseStats($request->user()));
    }

    public function show(Request $request, string $case): JsonResponse
    {
        $this->authorizeAction($request, 'yoshlar.case.view');

        $model = $this->service->findCase($request->user(), $case);
        abort_if($model === null, 404, 'Muammo topilmadi.');

        return response()->json(['data' => $model]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAction($request, 'yoshlar.case.manage');

        $data = $request->validate([
            'youth_id' => ['required', 'uuid'],
            'category' => ['required', Rule::in(YouthCase::CATEGORIES)],
            'source' => ['required', Rule::in(YouthCase::SOURCES)],
            'source_ref' => ['nullable', 'string', 'max:200'],
            'title' => ['required', 'string', 'max:500'],
            'description' => ['nullable', 'string'],
            'sla_deadline' => ['nullable', 'date'],
            'assigned_org_id' => ['nullable', 'uuid'],
            'assigned_staff_id' => ['nullable', 'uuid'],
        ]);

        return response()->json(['data' => $this->service->createCase($request->user(), $data)], 201);
    }

    public function update(Request $request, string $case): JsonResponse
    {
        $this->authorizeAction($request, 'yoshlar.case.manage');

        $model = $this->service->findCase($request->user(), $case);
        abort_if($model === null, 404, 'Muammo topilmadi.');

        $data = $request->validate([
            'status' => ['sometimes', Rule::in(YouthCase::STATUSES)],
            'assigned_org_id' => ['nullable', 'uuid'],
            'assigned_staff_id' => ['nullable', 'uuid'],
            'sla_deadline' => ['nullable', 'date'],
            'resolution_note' => ['nullable', 'string', 'max:2000'],
            'description' => ['nullable', 'string'],
        ]);

        return response()->json(['data' => $this->service->updateCase($request->user(), $model, $data)]);
    }

    // ---------------- Otaliq ----------------

    public function patronageIndex(Request $request): JsonResponse
    {
        $this->authorizeAction($request, 'yoshlar.patronage.view');

        return response()->json(['data' => $this->service->patronageList($request->user())]);
    }

    public function patronageStats(Request $request): JsonResponse
    {
        $this->authorizeAction($request, 'yoshlar.patronage.view');

        return response()->json($this->service->patronageStats($request->user()));
    }

    public function patronageShow(Request $request, string $patronage): JsonResponse
    {
        $this->authorizeAction($request, 'yoshlar.patronage.view');

        $model = $this->service->findPatronage($request->user(), $patronage);
        abort_if($model === null, 404, 'Otaliq topilmadi.');

        return response()->json(['data' => $model]);
    }

    public function patronageStore(Request $request): JsonResponse
    {
        $this->authorizeAction($request, 'yoshlar.patronage.manage');

        $data = $request->validate([
            'youth_id' => ['required', 'uuid'],
            'mentor_staff_id' => ['required', 'uuid'],
            'started_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json(['data' => $this->service->assignPatronage($request->user(), $data)], 201);
    }

    public function patronageEnd(Request $request, string $patronage): JsonResponse
    {
        $this->authorizeAction($request, 'yoshlar.patronage.manage');

        $model = $this->service->findPatronage($request->user(), $patronage);
        abort_if($model === null, 404, 'Otaliq topilmadi.');

        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        return response()->json(['data' => $this->service->endPatronage($request->user(), $model, $data['note'] ?? null)]);
    }

    public function patronageLog(Request $request, string $patronage): JsonResponse
    {
        $this->authorizeAction($request, 'yoshlar.patronage.manage');

        $model = $this->service->findPatronage($request->user(), $patronage);
        abort_if($model === null, 404, 'Otaliq topilmadi.');

        $data = $request->validate([
            'log_date' => ['nullable', 'date'],
            'kind' => ['required', Rule::in(PatronageLog::KINDS)],
            'note' => ['required', 'string', 'max:2000'],
            'case_id' => ['nullable', 'uuid'],
        ]);

        return response()->json(['data' => $this->service->addLog($request->user(), $model, $data)], 201);
    }

    private function authorizeAction(Request $request, string $permission): void
    {
        abort_unless($this->access->can($request->user(), $permission), 403, 'Ruxsat yoʻq.');
    }
}
