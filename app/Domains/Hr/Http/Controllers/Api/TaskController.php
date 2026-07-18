<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Controllers\Api;

use App\Domains\Hr\Actions\ControlPlans\RemoveFromControlAction;
use App\Domains\Hr\Actions\ControlPlans\ReviewTaskAction;
use App\Domains\Hr\Enums\AssigneeType;
use App\Domains\Hr\Enums\ExecutionStatus;
use App\Domains\Hr\Enums\ReviewStatus;
use App\Domains\Hr\Enums\TaskSource;
use App\Domains\Hr\Http\Requests\StoreTaskRequest;
use App\Domains\Hr\Models\ControlPlanItem;
use App\Domains\Hr\Models\Department;
use App\Domains\Hr\Models\HrProfile;
use App\Domains\Hr\Models\Organization;
use App\Domains\Hr\Models\TaskResponse;
use App\Domains\Hr\Services\ControlPlanAccessService;
use App\Domains\Hr\Support\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Топшириқлар (EDO inbox).
 *  - Котибият мудири: яратади, ходим/ташкилотга бириктиради, назоратдан ечади.
 *  - Ташкилот фойдаланувчиси: ўзига келган барча топшириқларни (мустақил + назорат режа)
 *    кўради ва ижро ҳолатини/ҳужжатини юклайди.
 */
class TaskController extends HrController
{
    public function __construct(
        private TenantContext $context,
        private ControlPlanAccessService $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->actor();
        $today = today();
        $filter = (string) $request->input('f', 'all');

        // Filtrlar bo'yicha sonlar (kartalar uchun)
        $counts = [
            'all' => $this->applyTaskFilter($this->baseTaskQuery($user), 'all', $today)->count(),
            'under_control' => $this->applyTaskFilter($this->baseTaskQuery($user), 'under_control', $today)->count(),
            'pending' => $this->applyTaskFilter($this->baseTaskQuery($user), 'pending', $today)->count(),
            'overdue' => $this->applyTaskFilter($this->baseTaskQuery($user), 'overdue', $today)->count(),
            'awaiting' => $this->applyTaskFilter($this->baseTaskQuery($user), 'awaiting', $today)->count(),
            'completed' => $this->applyTaskFilter($this->baseTaskQuery($user), 'completed', $today)->count(),
        ];

        $query = $this->applyTaskFilter($this->baseTaskQuery($user), $filter, $today)
            ->with(['responsibles', 'creator:id,name', 'kompleks:id,name_cyr', 'plan:id,title'])
            ->withCount('documents')
            ->latest();

        $tasks = $query->paginate(20)->withQueryString()
            ->through(fn (ControlPlanItem $t) => $this->presentTask($t));

        return response()->json([
            'tasks' => $tasks,
            'isOrgUser' => $user->organization_id !== null,
            'counts' => $counts,
            'filter' => $filter,
        ]);
    }

    /**
     * Foydalanuvchi rolига qarab topshiriqlar bazaviy query (eager-loadsiz).
     * Inbox = mustaqil topshiriqlar + nazorat reja bandlari (ijrochi bo'lib yoki yaratuvchi sifatida).
     */
    private function baseTaskQuery(HrProfile $user): Builder
    {
        $q = ControlPlanItem::query();

        // Ташкилот фойдаланувчиси — фақат ўзига (ташкилотга) бириктирилган топшириқлар
        if ($user->organization_id !== null) {
            return $q->whereHas('responsibles', fn ($r) => $r
                ->where('assignee_type', AssigneeType::ORGANIZATION->value)
                ->where('assignee_id', $user->organization_id));
        }

        // Вилоят/туман админ — барча топшириқлар (tenant scope чеклайди)
        if ($user->hasRole('super-admin') || $user->hasRole('viloyat-admin') || $user->hasRole('tuman-admin')) {
            return $q;
        }

        // Ички фойдаланувчи (котибият мудири ёки ҳокимлик ходими):
        //  - ўзи ижрочи (user) бўлган бандлар — мустақил + назорат режа
        //  - ўзи яратган топшириқлар
        //  - котибият мудири — ўз комплексидаги бандлар
        return $q->where(function ($w) use ($user) {
            $w->whereHas('responsibles', fn ($r) => $r
                ->where('assignee_type', AssigneeType::USER->value)
                ->where('assignee_id', $user->id))
                ->orWhere('created_by', $user->id);

            if ($user->hasRole('kotibyat-mudiri') && $user->department_id) {
                $w->orWhere('kompleks_id', $user->department_id);
            }
        });
    }

    /**
     * @param  Builder<ControlPlanItem>  $q
     * @return Builder<ControlPlanItem>
     */
    private function applyTaskFilter(Builder $q, string $filter, $today): Builder
    {
        return match ($filter) {
            'under_control' => $q->whereNull('control_removed_at'),
            'pending' => $q->whereNull('control_removed_at')
                ->whereIn('execution_status', ['not_started', 'in_progress']),
            'overdue' => $q->whereNull('control_removed_at')
                ->where('execution_status', '!=', 'completed')
                ->whereNotNull('deadline')->whereDate('deadline', '<', $today),
            'awaiting' => $q->where('review_status', ReviewStatus::SUBMITTED->value),
            'completed' => $q->where('execution_status', 'completed'),
            default => $q,
        };
    }

    public function create(Request $request): JsonResponse
    {
        $this->authorizeCreate($this->actor());

        return response()->json([
            'organizations' => $this->tenantOrganizations(),
            'users' => $this->assignableUsers($this->actor()),
        ]);
    }

    public function store(StoreTaskRequest $request): JsonResponse
    {
        $user = $this->actor();
        $validated = $request->validated();
        $responsibles = $request->resolvedResponsibles();

        $task = DB::transaction(function () use ($validated, $responsibles, $user) {
            /** @var ControlPlanItem $task */
            $task = ControlPlanItem::create([
                'source' => TaskSource::STANDALONE->value,
                'control_plan_id' => null,
                'hokimlik_id' => $this->context->id(),
                'kompleks_id' => $user->department?->type === 'kompleks' ? $user->department_id : null,
                'created_by' => $user->id,
                'title' => $validated['title'],
                'task_description' => $validated['task_description'],
                'implementation' => $validated['implementation'] ?? null,
                'link' => $validated['link'] ?? null,
                'deadline' => $validated['deadline'] ?? null,
                'execution_status' => ExecutionStatus::NOT_STARTED->value,
            ]);

            // Bir nechta mas'ul — agar hech biri asosiy belgilanmagan bo'lsa, birinchisi asosiy
            $hasPrimary = collect($responsibles)->contains(fn ($r) => filter_var($r['is_primary'] ?? false, FILTER_VALIDATE_BOOLEAN));
            foreach ($responsibles as $i => $r) {
                $type = $r['assignee_type'];
                $id = (string) $r['assignee_id'];
                $task->responsibles()->create([
                    'assignee_type' => $type,
                    'assignee_id' => $id,
                    'user_id' => $type === AssigneeType::USER->value ? $id : null,
                    'responsible_name' => ! empty($r['responsible_name'])
                        ? $r['responsible_name']
                        : $this->assigneeName($type, $id),
                    'responsible_position' => $r['responsible_position'] ?? null,
                    'is_primary' => $hasPrimary ? filter_var($r['is_primary'] ?? false, FILTER_VALIDATE_BOOLEAN) : $i === 0,
                ]);
            }

            return $task;
        });

        // Asosiy hujjat(lar) — task_response_id NULL → "Бириктирилган файллар" bo'limida ko'rinadi
        if ($request->hasFile('files')) {
            $folder = "task-docs/{$task->id}";
            foreach ($request->file('files') as $file) {
                $task->documents()->create([
                    'file_path' => $file->store($folder, 'local'),
                    'original_name' => $file->getClientOriginalName(),
                    'file_size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                    'uploaded_by' => $user->id,
                ]);
            }
        }

        return response()->json([
            'message' => 'Топшириқ яратилди ва бириктирилди.',
            'task' => $task,
        ], 201);
    }

    public function show(ControlPlanItem $task): JsonResponse
    {
        abort_unless($this->access->canViewItem($this->actor(), $task), 403);

        $task->load([
            'responsibles', 'creator:id,name', 'kompleks:id,name_cyr',
            'plan:id,title,document_number,document_date', 'documents.uploader:id,name',
            'responses.author:id,name', 'responses.documents',
            'removedBy:id,name', 'reviewer:id,name',
        ]);

        return response()->json([
            'task' => $this->presentTask($task, withDetail: true),
            'canReport' => $this->access->canEditItem($this->actor(), $task),
            'canRemoveFromControl' => $this->access->canRemoveFromControl($this->actor(), $task),
            'canReview' => $this->access->canReview($this->actor(), $task),
        ]);
    }

    /** Ижро ҳисоботи — масъул (ташкилот ёки ходим) янгилайди. */
    public function updateStatus(Request $request, ControlPlanItem $task, ReviewTaskAction $review): JsonResponse
    {
        $user = $this->actor();
        abort_unless($this->access->canEditItem($user, $task), 403);
        abort_if(! $task->isUnderControl(), 422, 'Топшириқ назоратдан ечилган.');

        $validated = $request->validate([
            'execution_status' => ['required', 'string', 'in:'.ExecutionStatus::values()],
            'execution_report' => ['nullable', 'string'],
        ]);

        $submittedForReview = $user->organization_id !== null
            && $validated['execution_status'] === ExecutionStatus::COMPLETED->value;

        DB::transaction(function () use ($task, $validated, $user, $review, $submittedForReview): void {
            $task->update($validated);

            // Ташкилот ижрони 'бажарилган' деб белгиласа — котибият мудирига тасдиққа юборилади (EDO)
            if ($user->organization_id !== null) {
                $submittedForReview ? $review->submit($task) : $review->withdraw($task);
            }
        });

        return response()->json([
            'message' => $submittedForReview
                ? 'Ижро тасдиқлаш учун юборилди.'
                : 'Ижро ҳолати янгиланди.',
            'task' => $task,
        ]);
    }

    /**
     * Ташкилот/ходим жавоб юборади (матн + файл) → «Кўриб чиқилмоқда» ҳолатига ўтади.
     * Ижро статусини бу ерда ўзгартирмаймиз — уни топшириқ берган котибият мудири бошқаради.
     */
    public function respond(Request $request, ControlPlanItem $task, ReviewTaskAction $review): JsonResponse
    {
        abort_unless($this->access->canEditItem($this->actor(), $task), 403);
        abort_if(! $task->isUnderControl(), 422, 'Топшириқ назоратдан ечилган.');

        $validated = $request->validate([
            'execution_report' => ['nullable', 'string'],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png'],
        ]);

        if (empty($validated['execution_report']) && ! $request->hasFile('files')) {
            return response()->json([
                'message' => 'Камида битта файл ёки изоҳ киритинг.',
                'errors' => ['files' => ['Камида битта файл ёки изоҳ киритинг.']],
            ], 422);
        }

        $user = $this->actor();
        $folder = $task->control_plan_id ? "control-plan-docs/{$task->control_plan_id}" : "task-docs/{$task->id}";

        // Барча DB ёзувлари битта транзаксияда (timeline + task update + hujjatlar + review).
        DB::transaction(function () use ($task, $user, $validated, $request, $review, $folder): void {
            // Жавоб ёзуви (timeline банди) — файллар шу жавобга бириктирилади
            $response = $this->logResponse($task, $user, 'response', $validated['execution_report'] ?? null);

            // Сўнгги ижро ҳисоботи сифатида ҳам сақлаб қўямиз (snapshot/қидирув учун)
            if (! empty($validated['execution_report'])) {
                $task->update(['execution_report' => $validated['execution_report']]);
            }

            foreach ((array) $request->file('files', []) as $file) {
                $task->documents()->create([
                    'task_response_id' => $response->id,
                    'file_path' => $file->store($folder, 'local'),
                    'original_name' => $file->getClientOriginalName(),
                    'file_size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                    'uploaded_by' => $user->id,
                ]);
            }

            // Жавоб юборилди → кўриб чиқиш учун котибият мудирига боради
            $review->submit($task);
        });

        return response()->json([
            'message' => 'Жавоб юборилди — кўриб чиқилмоқда.',
            'task' => $task,
        ]);
    }

    /** Котибият мудири ижрони тасдиқлайди. */
    public function approve(ControlPlanItem $task, ReviewTaskAction $review): JsonResponse
    {
        abort_unless($this->access->canReview($this->actor(), $task), 403);

        $review->approve($task, $this->actor());
        $this->logResponse($task, $this->actor(), 'approved');

        return response()->json([
            'message' => 'Ижро тасдиқланди.',
            'task' => $task,
        ]);
    }

    /** Котибият мудири қайта ишлашга қайтаради. */
    public function returnForRework(Request $request, ControlPlanItem $task, ReviewTaskAction $review): JsonResponse
    {
        abort_unless($this->access->canReview($this->actor(), $task), 403);

        $validated = $request->validate([
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $review->returnForRework($task, $this->actor(), $validated['comment'] ?? null);
        $this->logResponse($task, $this->actor(), 'returned', $validated['comment'] ?? null);

        return response()->json([
            'message' => 'Топшириқ қайта ишлашга қайтарилди.',
            'task' => $task,
        ]);
    }

    /** Назоратдан ечиш — фақат яратган котибият мудири ёки масъул ходим. */
    public function removeFromControl(Request $request, ControlPlanItem $task, RemoveFromControlAction $action): JsonResponse
    {
        abort_unless($this->access->canRemoveFromControl($this->actor(), $task), 403);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $action->remove($task, $this->actor(), $validated['reason'] ?? null);
        $this->logResponse($task, $this->actor(), 'control_removed', $validated['reason'] ?? null);

        return response()->json([
            'message' => 'Топшириқ назоратдан ечилди.',
            'task' => $task,
        ]);
    }

    /** Қайта назоратга қайтариш. */
    public function restoreControl(ControlPlanItem $task, RemoveFromControlAction $action): JsonResponse
    {
        abort_unless($this->access->canRemoveFromControl($this->actor(), $task), 403);

        $action->restore($task);

        return response()->json([
            'message' => 'Топшириқ қайта назоратга қайтарилди.',
            'task' => $task,
        ]);
    }

    // ===== Yordamchilar =====

    private function authorizeCreate(HrProfile $user): void
    {
        abort_unless(
            $user->can('tadbirlar.create') || $user->can('topshiriqlar.assign-org'),
            403,
        );
    }

    /** Joriy tenant tashkilotlari (dropdown uchun). */
    private function tenantOrganizations()
    {
        return Organization::query()
            ->where('is_active', true)
            ->orderBy('name_cyr')
            ->get(['id', 'name_cyr']);
    }

    /**
     * Biriktirish mumkin bo'lgan ichki xodimlar — kotibyat mudirining kompleks shajarasi.
     */
    private function assignableUsers(HrProfile $user)
    {
        $deptIds = collect([$user->department_id])->filter();
        if ($user->department_id) {
            $childIds = Department::where('parent_id', $user->department_id)->pluck('id');
            $deptIds = $deptIds->merge($childIds);
        }

        return HrProfile::query()
            ->whereIn('department_id', $deptIds)
            ->whereNull('organization_id')
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function assigneeName(string $type, string $id): string
    {
        if ($type === AssigneeType::ORGANIZATION->value) {
            return Organization::find($id)?->name_cyr ?? '';
        }

        return HrProfile::find($id)?->name ?? '';
    }

    /**
     * @return array<string, mixed>
     */
    private function presentTask(ControlPlanItem $task, bool $withDetail = false): array
    {
        $primary = $task->responsibles->firstWhere('is_primary', true) ?? $task->responsibles->first();

        $data = [
            'id' => $task->id,
            'title' => $task->title ?? $task->task_description,
            'task_description' => $task->task_description,
            'deadline' => $task->deadline?->format('d.m.Y'),
            'execution_status' => $task->execution_status,
            'assignee' => $primary?->displayName(),
            'assignee_type' => $primary?->assignee_type instanceof AssigneeType ? $primary->assignee_type->value : null,
            'source' => $task->source instanceof TaskSource ? $task->source->value : $task->source,
            'plan_title' => $task->plan?->title,
            'documents_count' => $task->documents_count ?? $task->documents?->count() ?? 0,
            'under_control' => $task->isUnderControl(),
            'review_status' => $task->review_status instanceof ReviewStatus ? $task->review_status->value : $task->review_status,
            'creator' => $task->creator?->name,
            'created_at' => $task->created_at?->format('d.m.Y'),
        ];

        if ($withDetail) {
            $data['item_number'] = $task->item_number;
            $data['section_title'] = $task->section_title;
            $data['plan_document_number'] = $task->plan?->document_number;
            $data['plan_document_date'] = $task->plan?->document_date?->format('d.m.Y');
            $data['implementation'] = $task->implementation;
            $data['funding_source'] = $task->funding_source;
            $data['link'] = $task->link;
            $data['execution_report'] = $task->execution_report;
            $data['kompleks'] = $task->kompleks?->name_cyr;
            $data['removed_at'] = $task->control_removed_at?->format('d.m.Y H:i');
            $data['removed_by'] = $task->removedBy?->name;
            $data['removal_reason'] = $task->control_removal_reason;
            $data['review_comment'] = $task->review_comment;
            $data['submitted_at'] = $task->submitted_at?->format('d.m.Y H:i');
            $data['reviewed_at'] = $task->reviewed_at?->format('d.m.Y H:i');
            $data['reviewer'] = $task->reviewer?->name;
            $data['responsibles'] = $task->responsibles->map(fn ($r) => [
                'name' => $r->displayName(),
                'type' => $r->assignee_type instanceof AssigneeType ? $r->assignee_type->value : $r->assignee_type,
                'is_primary' => (bool) $r->is_primary,
                'position' => $r->responsible_position,
            ])->all();
            // Faqat topshiriqning asosiy fayl(lar)i — javobga biriktirilgan fayllar timeline'da
            $data['documents'] = $task->documents->whereNull('task_response_id')->map(fn ($d) => [
                'id' => $d->id,
                'original_name' => $d->original_name,
                'uploader' => $d->uploader?->name,
                'created_at' => $d->created_at?->format('d.m.Y H:i'),
            ])->values()->all();
            $data['timeline'] = $this->buildTimeline($task);
        }

        return $data;
    }

    /**
     * Берилган жавоблар тарихи (timeline) — task_responses жадвалидан йиғилади.
     * Ҳар бир жавобга бириктирилган файллар ҳам қайтарилади.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildTimeline(ControlPlanItem $task): array
    {
        $labels = [
            'response' => 'Берилган жавоб', 'approved' => 'Тасдиқланди',
            'returned' => 'Қайтарилди', 'control_removed' => 'Назоратдан ечилди',
            'restored' => 'Қайта назоратга',
        ];
        $colors = [
            'response' => 'blue', 'approved' => 'green', 'returned' => 'orange',
            'control_removed' => 'purple', 'restored' => 'gray',
        ];

        // responses() relation энг янгисини биринчи қайтаради
        return $task->responses->map(fn (TaskResponse $r) => [
            'type' => $r->type,
            'label' => $labels[$r->type] ?? $r->type,
            'color' => $colors[$r->type] ?? 'gray',
            'author' => $r->author_name ?? $r->author?->name ?? '—',
            'org' => $r->author_org,
            'date' => $r->created_at?->format('d.m.Y H:i'),
            'text' => $r->body,
            'documents' => $r->documents->map(fn ($d) => [
                'id' => $d->id,
                'original_name' => $d->original_name,
                'file_size' => $d->file_size,
                'created_at' => $d->created_at?->format('d.m.Y H:i'),
            ])->all(),
        ])->all();
    }

    /** Timeline'га жавоб/тасдиқлаш банди қўшади. */
    private function logResponse(ControlPlanItem $task, HrProfile $user, string $type, ?string $body = null): TaskResponse
    {
        return $task->responses()->create([
            'author_id' => $user->id,
            'author_name' => $user->name,
            'author_org' => $user->organization?->name_cyr ?? $user->department?->name_cyr,
            'type' => $type,
            'body' => $body,
        ]);
    }
}
