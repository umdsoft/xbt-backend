<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Http\Controllers\Api;

use App\Domains\Yoshlar\Models\Organization;
use App\Domains\Yoshlar\Models\Protocol;
use App\Domains\Yoshlar\Models\Task;
use App\Domains\Yoshlar\Models\TaskUpdate;
use App\Domains\Yoshlar\Services\AuditLogger;
use App\Domains\Yoshlar\Services\TaskService;
use App\Domains\Yoshlar\Support\YoshlarAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    public function __construct(
        private readonly TaskService $service,
        private readonly YoshlarAccess $access,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAction($request, 'yoshlar.task.view');

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
        $this->authorizeAction($request, 'yoshlar.task.view');

        return response()->json($this->service->stats($request->user()));
    }

    /** Tasdiqlash navbati — foydalanuvchi qaysi bosqichda ishlasa, o'sha. */
    public function queue(Request $request): JsonResponse
    {
        $this->authorizeAction($request, 'yoshlar.task.view');

        return response()->json(['data' => $this->service->reviewQueue($request->user())]);
    }

    public function show(Request $request, string $task): JsonResponse
    {
        $this->authorizeAction($request, 'yoshlar.task.view');

        $model = $this->service->find($request->user(), $task);
        abort_if($model === null, 404, 'Topshiriq topilmadi.');

        return response()->json(['data' => $model]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAction($request, 'yoshlar.task.manage');

        $data = $request->validate([
            'protocol_id' => ['nullable', 'uuid'],
            'title' => ['required', 'string', 'max:500'],
            'description' => ['nullable', 'string'],
            'assigned_org_id' => ['required', 'uuid'],
            'district_id' => ['nullable', 'uuid'],
            'deadline' => ['required', 'date'],
            'priority' => ['required', Rule::in(Task::PRIORITIES)],
        ]);

        $org = Organization::query()->find($data['assigned_org_id']);

        abort_if($org === null, 422, 'Tashkilot topilmadi.');
        abort_unless(
            in_array($org->type, [Organization::TYPE_TUMAN_SEKTOR, Organization::TYPE_VILOYAT_SEKTOR], true),
            422,
            'Topshiriq faqat sektoral tashkilotga biriktiriladi.',
        );

        $data['district_id'] = $org->district_id;

        return response()->json(['data' => $this->service->create($request->user(), $data)], 201);
    }

    public function update(Request $request, string $task): JsonResponse
    {
        $this->authorizeAction($request, 'yoshlar.task.manage');

        $model = $this->service->find($request->user(), $task);
        abort_if($model === null, 404, 'Topshiriq topilmadi.');

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:500'],
            'description' => ['nullable', 'string'],
            'assigned_org_id' => ['sometimes', 'uuid'],
            'district_id' => ['nullable', 'uuid'],
            'deadline' => ['sometimes', 'date'],
            'priority' => ['sometimes', Rule::in(Task::PRIORITIES)],
        ]);

        // Mas'ul tashkilot ALMASHTIRILSA, u haqiqatan mavjud va ijrochi
        // tur boʻlishi kerak: `uuid` validatsiyasi mavjudlikni tekshirmaydi
        // va topshiriq hech kim koʻrmaydigan tashkilotga tushib qolardi.
        if (isset($data['assigned_org_id'])) {
            $org = Organization::query()->find($data['assigned_org_id']);

            abort_if($org === null, 422, 'Tashkilot topilmadi.');
            abort_unless(
                in_array($org->type, [Organization::TYPE_TUMAN_SEKTOR, Organization::TYPE_VILOYAT_SEKTOR], true),
                422,
                'Topshiriq faqat sektoral tashkilotga biriktiriladi.',
            );

            // Tuman ham tashkilotdan olinadi — qoʻlda kiritilgan qiymat
            // tashkilot bilan mos kelmasligi mumkin.
            $data['district_id'] = $org->district_id;
        }

        $model->update($data);

        $this->audit->log($request->user(), 'task.update', 'task', $model->id, $data);

        return response()->json(['data' => $model->refresh()]);
    }

    /** Ijro hisobotini yuborish (ijrochi tashkilot). */
    public function submit(Request $request, string $task): JsonResponse
    {
        $this->authorizeAction($request, 'yoshlar.task.execute');

        $model = $this->service->find($request->user(), $task);
        abort_if($model === null, 404, 'Topshiriq topilmadi.');

        $data = $request->validate([
            'progress' => ['required', 'integer', 'min:0', 'max:100'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'file_path' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json(['data' => $this->service->submitUpdate($request->user(), $model, $data)], 201);
    }

    /** Zanjir bosqichi: sektor boshqarmasi yoki yoshlar boshqarmasi. */
    public function review(Request $request, string $update): JsonResponse
    {
        $data = $request->validate([
            'approve' => ['required', 'boolean'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $model = TaskUpdate::query()->with('task')->findOrFail($update);

        // Foydalanuvchi shu topshiriqni umuman ko'radimi (IDOR himoyasi).
        abort_if($this->service->find($request->user(), $model->task_id) === null, 404, 'Hisobot topilmadi.');

        $stage = match ($model->review_stage) {
            'sector_review' => 'sector',
            'youth_review' => 'youth',
            default => abort(422, 'Hisobot tasdiqlash bosqichida emas.'),
        };

        $this->authorizeAction($request, "yoshlar.task.review.{$stage}");

        // Qaytarish uchun sabab MAJBURIY: sababsiz qaytarish ijrochini
        // nima tuzatish kerakligini bilmay qoldiradi.
        if ($data['approve'] === false && trim((string) ($data['comment'] ?? '')) === '') {
            abort(422, 'Qaytarish sababini yozing.');
        }

        return response()->json([
            'data' => $this->service->review($request->user(), $model, $stage, (bool) $data['approve'], $data['comment'] ?? null),
        ]);
    }

    // ---------- Protokollar ----------

    public function protocols(Request $request): JsonResponse
    {
        $this->authorizeAction($request, 'yoshlar.task.view');

        return response()->json([
            'data' => Protocol::query()->withCount('tasks')->orderByDesc('protocol_date')->get(),
        ]);
    }

    public function storeProtocol(Request $request): JsonResponse
    {
        $this->authorizeAction($request, 'yoshlar.task.manage');

        $data = $request->validate([
            'number' => ['required', 'string', 'max:60'],
            'protocol_date' => ['required', 'date'],
            'topic' => ['required', 'string', 'max:500'],
            'description' => ['nullable', 'string'],
            'issued_by' => ['nullable', 'string', 'max:300'],
        ]);

        $data['created_by'] = $request->user()->id;

        return response()->json(['data' => Protocol::query()->create($data)], 201);
    }

    private function authorizeAction(Request $request, string $permission): void
    {
        abort_unless($this->access->can($request->user(), $permission), 403, 'Ruxsat yoʻq.');
    }
}
