<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Controllers\Api;

use App\Domains\Hr\Actions\Appeals\CreateAppealAction;
use App\Domains\Hr\Actions\Appeals\RecordCouncilDecisionAction;
use App\Domains\Hr\Http\Requests\Appeals\RecordDecisionRequest;
use App\Domains\Hr\Http\Requests\Appeals\StoreAppealRequest;
use App\Domains\Hr\Models\AppealCategory;
use App\Domains\Hr\Models\CitizenAppeal;
use App\Domains\Hr\Models\Mahalla;
use App\Domains\Hr\Models\YouthMeeting;
use App\Domains\Hr\Services\Appeals\AppealRoutingService;
use App\Domains\Hr\Services\Appeals\AppealStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CitizenAppealController extends HrController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CitizenAppeal::class);

        $appeals = CitizenAppeal::query()
            ->with(['category', 'subCategory', 'mahalla', 'activeAssignment'])
            ->when($request->search, fn ($q, $s) => $q->whereLike('applicant_name', "%{$s}%")
                ->orWhereLike('body', "%{$s}%"))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->priority, fn ($q, $p) => $q->where('priority', $p))
            ->when($request->category_id, fn ($q, $c) => $q->where('category_id', $c))
            ->when($request->mahalla_id, fn ($q, $m) => $q->where('mahalla_id', $m))
            ->orderByDesc('submitted_at')
            ->paginate(25);

        return response()->json([
            'appeals' => $appeals,
            'filters' => $request->only(['search', 'status', 'priority', 'category_id', 'mahalla_id']),
            'categories' => AppealCategory::roots()->with('children')->orderBy('sort_order')->get(),
        ]);
    }

    public function create(): JsonResponse
    {
        $this->authorize('create', CitizenAppeal::class);

        return response()->json($this->formData());
    }

    public function store(StoreAppealRequest $request, CreateAppealAction $action): JsonResponse
    {
        $this->authorize('create', CitizenAppeal::class);

        $appeal = $action->execute($request->validated());

        return response()->json([
            'message' => 'Мурожаат қабул қилинди.',
            'appeal' => $appeal,
        ], 201);
    }

    public function show(CitizenAppeal $appeal): JsonResponse
    {
        $this->authorize('view', $appeal);

        $appeal->load([
            'category', 'subCategory', 'mahalla', 'meeting',
            'creator', 'assignments.assigner',
            'decisions.council.mahalla', 'decisions.decider',
            'documents.uploader', 'comments.author',
            'statusHistory.changer',
            'aiClassifications', 'aiDrafts',
            'feedback',
        ]);

        // Active assignment'ni resolve
        $activeAssignee = null;
        if ($appeal->activeAssignment) {
            $resolved = $appeal->activeAssignment->resolveAssignee();
            $activeAssignee = $resolved ? [
                'type' => $appeal->activeAssignment->assignee_type,
                'name' => $this->resolveName($resolved, $appeal->activeAssignment->assignee_type),
                'id' => $resolved->id,
            ] : null;
        }

        return response()->json([
            'appeal' => $appeal,
            'active_assignee' => $activeAssignee,
        ]);
    }

    public function edit(CitizenAppeal $appeal): JsonResponse
    {
        $this->authorize('update', $appeal);

        return response()->json([
            ...$this->formData(),
            'appeal' => $appeal,
        ]);
    }

    public function update(StoreAppealRequest $request, CitizenAppeal $appeal): JsonResponse
    {
        $this->authorize('update', $appeal);

        $appeal->update($request->validated());

        return response()->json([
            'message' => 'Мурожаат янгиланди.',
            'appeal' => $appeal,
        ]);
    }

    public function destroy(CitizenAppeal $appeal): JsonResponse
    {
        $this->authorize('delete', $appeal);

        $appeal->delete();

        return response()->json([
            'message' => 'Мурожаат ўчирилди.',
        ]);
    }

    /** Manual triage va routing — operator murojaatga kategoriya berdi */
    public function triage(
        Request $request,
        CitizenAppeal $appeal,
        AppealRoutingService $router,
        AppealStatusService $statusService,
    ): JsonResponse {
        $this->authorize('assign', $appeal);

        $validated = $request->validate([
            'category_id' => ['required', 'string', 'exists:appeal_categories,id'],
            'sub_category_id' => ['nullable', 'string', 'exists:appeal_categories,id'],
            'priority' => ['nullable', 'in:low,normal,high,urgent'],
        ]);

        // Уч босқичли ёзув битта транзаксияда — ўрта йўлда хато бўлса номувофиқ ҳолат қолмаслиги учун.
        DB::transaction(function () use ($appeal, $validated, $statusService, $router): void {
            $appeal->update($validated);
            $statusService->transition($appeal, 'triaged', 'Manual triage by operator');
            $router->route($appeal->refresh());
        });

        return response()->json(['message' => 'Мурожаат йўналтирилди.']);
    }

    /** Mahalla yettiligi qarori */
    public function recordDecision(
        RecordDecisionRequest $request,
        CitizenAppeal $appeal,
        RecordCouncilDecisionAction $action,
    ): JsonResponse {
        $this->authorize('decide', $appeal);

        $action->execute($appeal, $request->validated());

        return response()->json(['message' => 'Қарор қайд этилди.']);
    }

    /** @return array<string, mixed> */
    private function formData(): array
    {
        return [
            'categories' => AppealCategory::roots()->with('children')->orderBy('sort_order')->get(),
            'mahallas' => Mahalla::orderBy('name_cyr')->get(['id', 'district_id', 'name_cyr']),
            'meetings' => YouthMeeting::orderByDesc('meeting_date')->limit(20)->get(['id', 'meeting_date', 'location']),
        ];
    }

    private function resolveName($model, string $type): string
    {
        return match ($type) {
            'council' => $model->mahalla?->name_cyr.' (yettilik)',
            'department' => $model->name_cyr,
            'user' => $model->name,
            default => '—',
        };
    }
}
