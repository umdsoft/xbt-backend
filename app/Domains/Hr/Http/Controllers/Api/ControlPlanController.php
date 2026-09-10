<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Controllers\Api;

use App\Domains\Hr\Actions\ControlPlans\SaveControlPlanItemsAction;
use App\Domains\Hr\Actions\ControlPlans\UpdateOverdueStatusAction;
use App\Domains\Hr\Enums\ExecutionStatus;
use App\Domains\Hr\Exports\ControlPlanDocxExporter;
use App\Domains\Hr\Http\Requests\StoreControlPlanRequest;
use App\Domains\Hr\Http\Requests\UpdateControlPlanRequest;
use App\Domains\Hr\Models\Activity;
use App\Domains\Hr\Models\ControlPlan;
use App\Domains\Hr\Models\ControlPlanItem;
use App\Domains\Hr\Models\HrProfile;
use App\Domains\Hr\Models\Organization;
use App\Domains\Hr\Services\ControlPlanAccessService;
use App\Domains\Hr\Services\PositionDisplayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ControlPlanController extends HrController
{
    public function __construct(
        private ControlPlanAccessService $access,
        private PositionDisplayService $positionDisplay,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        // Manba: `can:tadbirlar.view` route middleware (butun resource uchun).
        $this->authorize('viewAny', ControlPlan::class);

        $plans = ControlPlan::with('creator')
            ->withCount('items')
            ->when($request->search, fn ($q, $s) => $q->whereLike('title', "%{$s}%")
                ->orWhereLike('document_number', "%{$s}%"))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->paginate(25);

        return response()->json([
            'plans' => $plans,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    public function create(): JsonResponse
    {
        // Manba: `can:tadbirlar.view` (route) + authorizeResource `create`.
        $this->authorize('viewAny', ControlPlan::class);
        $this->authorize('create', ControlPlan::class);

        return response()->json([
            'users' => HrProfile::whereNull('organization_id')->orderBy('name')->get(['id', 'name']),
            'organizations' => Organization::where('is_active', true)->orderBy('name_cyr')->get(['id', 'name_cyr']),
        ]);
    }

    public function store(StoreControlPlanRequest $request, SaveControlPlanItemsAction $saveItems): JsonResponse
    {
        // Manba: `can:tadbirlar.view` (route) + authorizeResource `create`.
        $this->authorize('viewAny', ControlPlan::class);
        $this->authorize('create', ControlPlan::class);

        $validated = $request->validated();

        $plan = ControlPlan::create([
            'title' => $validated['title'],
            'document_number' => $validated['document_number'] ?? null,
            'document_date' => $validated['document_date'] ?? null,
            'status_date' => $validated['status_date'] ?? null,
            'created_by' => $this->actor()->id,
        ]);

        $saveItems->execute($plan, $validated['items'] ?? []);

        return response()->json([
            'message' => 'Назорат режа яратилди.',
            'plan' => $plan,
        ], 201);
    }

    public function show(string $id, UpdateOverdueStatusAction $updateOverdue): JsonResponse
    {
        // Manba: `can:tadbirlar.view` route middleware (authorizeResource `show`'ni except qilgan).
        $this->authorize('viewAny', ControlPlan::class);

        $plan = ControlPlan::with([
            'creator',
            'items.responsibles.user.position',
            'items.responsibles.user.department',
            'items.documents.uploader',
        ])->findOrFail($id);

        $updateOverdue->execute($plan);

        $user = $this->actor();
        $isCreator = $plan->created_by === $user->id;
        $isSuperAdmin = $user->hasRole('super-admin');

        // Har bir band uchun: tahrirlash huquqi va lavozim matni
        foreach ($plan->items as $item) {
            $item->can_edit = $this->access->canEditItem($user, $item);

            foreach ($item->responsibles as $resp) {
                $resp->display_position = $this->positionDisplay->forResponsible($resp);
            }
        }

        return response()->json([
            'plan' => $plan,
            'isCreator' => $isCreator || $isSuperAdmin,
        ]);
    }

    public function edit(string $id): JsonResponse
    {
        // Manba: `can:tadbirlar.view` (route) + authorizeResource `update`.
        $this->authorize('viewAny', ControlPlan::class);

        $plan = ControlPlan::with(['items.responsibles'])->findOrFail($id);

        $this->authorize('update', $plan);

        return response()->json([
            'plan' => $plan,
            'users' => HrProfile::whereNull('organization_id')->orderBy('name')->get(['id', 'name']),
            'organizations' => Organization::where('is_active', true)->orderBy('name_cyr')->get(['id', 'name_cyr']),
        ]);
    }

    public function update(
        UpdateControlPlanRequest $request,
        string $id,
        SaveControlPlanItemsAction $saveItems,
    ): JsonResponse {
        // Manba: `can:tadbirlar.view` (route) + authorizeResource `update`.
        $this->authorize('viewAny', ControlPlan::class);

        $plan = ControlPlan::findOrFail($id);

        $this->authorize('update', $plan);

        $validated = $request->validated();

        $plan->update([
            'title' => $validated['title'],
            'document_number' => $validated['document_number'] ?? null,
            'document_date' => $validated['document_date'] ?? null,
            'status' => $validated['status'],
            'status_date' => $validated['status_date'] ?? null,
        ]);

        $saveItems->execute($plan, $validated['items'] ?? [], replace: true);

        return response()->json([
            'message' => 'Назорат режа янгиланди.',
            'plan' => $plan,
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        // Manba: `can:tadbirlar.view` (route) + authorizeResource `delete`.
        $this->authorize('viewAny', ControlPlan::class);

        $plan = ControlPlan::findOrFail($id);

        $this->authorize('delete', $plan);

        $plan->delete();

        return response()->json([
            'message' => 'Назорат режа ўчирилди.',
        ]);
    }

    public function showItem(string $item): JsonResponse
    {
        // Manba: `can:tadbirlar.view` route group middleware.
        $this->authorize('viewAny', ControlPlan::class);

        $planItem = ControlPlanItem::with([
            'plan',
            'responsibles.user.position',
            'responsibles.user.department',
            'documents.uploader',
        ])->findOrFail($item);

        $activities = Activity::where('subject_type', ControlPlanItem::class)
            ->where('subject_id', $planItem->id)
            ->with('causer')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'item' => $planItem,
            'activities' => $activities,
            'canEdit' => $this->access->canEditItem($this->actor(), $planItem),
        ]);
    }

    public function updateItemStatus(Request $request, string $item): JsonResponse
    {
        // Manba: `can:tadbirlar.view` route group middleware.
        $this->authorize('viewAny', ControlPlan::class);

        $planItem = ControlPlanItem::with('responsibles', 'plan')->findOrFail($item);

        abort_unless($this->access->canEditItem($this->actor(), $planItem), 403);

        $validated = $request->validate([
            'execution_status' => ['required', 'in:'.ExecutionStatus::values()],
            'execution_report' => ['nullable', 'string'],
        ]);

        $planItem->update($validated);

        return response()->json([
            'message' => 'Банд ҳолати янгиланди.',
            'item' => $planItem,
        ]);
    }

    public function export(string $controlPlan, ControlPlanDocxExporter $exporter): BinaryFileResponse
    {
        // Manba: `can:tadbirlar.view` route group middleware.
        $this->authorize('viewAny', ControlPlan::class);

        $plan = ControlPlan::findOrFail($controlPlan);
        $path = $exporter->export($plan);

        return response()->download($path, $exporter->downloadName($plan))->deleteFileAfterSend(true);
    }
}
