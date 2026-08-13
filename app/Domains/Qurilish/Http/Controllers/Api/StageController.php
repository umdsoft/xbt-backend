<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Http\Controllers\Api;

use App\Domains\Qurilish\Models\ConstructionObject;
use App\Domains\Qurilish\Models\ObjectStage;
use App\Domains\Qurilish\Services\ObjectService;
use App\Domains\Qurilish\Services\StageService;
use App\Domains\Qurilish\Support\QurilishAccess;
use App\Domains\Qurilish\Support\QurilishScope;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Obyekt bosqichlari — XNP TZ v2.0 moderatsiya sikli.
 *
 * Har amal alohida marshrut: `PATCH .../stages/{stage}` faqat qoralama
 * saqlaydi, holatni esa nomlangan amallar o'zgartiradi (`submit`, `review`,
 * `approve`, `reject`). Bitta «status yubor» endpointi bo'lganda mijoz
 * istalgan holatga sakrashga urinardi; nomlangan amal esa o'zi ruxsatni
 * bildiradi va audit jurnalida ham aniq ko'rinadi.
 */
class StageController extends QurilishController
{
    public function __construct(
        QurilishAccess $access,
        private readonly ObjectService $objects,
        private readonly StageService $stages,
        private readonly QurilishScope $scope,
    ) {
        parent::__construct($access);
    }

    public function index(Request $request, string $objectId): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.view');

        $object = $this->objects->findOrFail($request->user(), $objectId);
        $this->stages->ensureStages($object);

        $byCode = ObjectStage::query()->where('object_id', $object->id)->get()->keyBy('stage_code');

        // Tartib MUHIM: SPA voronkani shu ketma-ketlikda chizadi.
        $data = [];
        foreach (ConstructionObject::STAGES as $i => $code) {
            $data[] = $this->present($byCode->get($code), $code, $i, $request->user());
        }

        return response()->json([
            'data' => $data,
            'current_stage' => $object->current_stage,
            'tz_stages' => ObjectStage::TZ_STAGE_NAMES,
        ]);
    }

    /** Qoralama saqlash — holat «qoralama» ga keladi, moderatorga yuborilmaydi. */
    public function update(Request $request, string $objectId, string $stageCode): JsonResponse
    {
        $object = $this->objects->findOrFail($request->user(), $objectId);

        $payload = $request->validate([
            'malumot' => ['nullable', 'array'],
            'started_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $stage = $this->stages->saveDraft($object, $stageCode, $payload, $request->user());

        return $this->respond($object, $stage, $request->user());
    }

    public function submit(Request $request, string $objectId, string $stageCode): JsonResponse
    {
        $object = $this->objects->findOrFail($request->user(), $objectId);
        $stage = $this->stages->submit($object, $stageCode, $request->user());

        return $this->respond($object, $stage, $request->user());
    }

    public function review(Request $request, string $objectId, string $stageCode): JsonResponse
    {
        $object = $this->objects->findOrFail($request->user(), $objectId);
        $stage = $this->stages->startReview($object, $stageCode, $request->user());

        return $this->respond($object, $stage, $request->user());
    }

    public function approve(Request $request, string $objectId, string $stageCode): JsonResponse
    {
        $object = $this->objects->findOrFail($request->user(), $objectId);
        $stage = $this->stages->approve($object, $stageCode, $request->user());

        return $this->respond($object, $stage, $request->user());
    }

    public function reject(Request $request, string $objectId, string $stageCode): JsonResponse
    {
        $object = $this->objects->findOrFail($request->user(), $objectId);

        // Sabab ikki joyda majburiy: bu yerda 422 forma maydoniga yopishsin,
        // servisda esa API'ning boshqa chaqiruvchilaridan himoya bo'lsin.
        $payload = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        $stage = $this->stages->reject($object, $stageCode, $payload['reason'], $request->user());

        return $this->respond($object, $stage, $request->user());
    }

    /** Rad etilgandan keyin tuzatishga qaytarish. */
    public function reopen(Request $request, string $objectId, string $stageCode): JsonResponse
    {
        $object = $this->objects->findOrFail($request->user(), $objectId);
        $stage = $this->stages->reopenDraft($object, $stageCode, $request->user());

        return $this->respond($object, $stage, $request->user());
    }

    public function notRequired(Request $request, string $objectId, string $stageCode): JsonResponse
    {
        $object = $this->objects->findOrFail($request->user(), $objectId);
        $stage = $this->stages->markNotRequired($object, $stageCode, $request->user());

        return $this->respond($object, $stage, $request->user());
    }

    /**
     * Moderator navbati — tasdiq kutayotgan bosqichlar.
     *
     * Scope obyektlar so'roviga qo'llanadi: buyurtmachi o'zi yuborganlarini
     * kuzatadi, prokuratura hammasini ko'radi. Ro'yxat 200 tadan uzun
     * bo'lmaydi — navbat ish quroli, arxiv emas.
     */
    public function queue(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeAction($user, 'qurilish.view');

        $visible = $this->scope->apply(ConstructionObject::query()->select('id'), $user);

        $rows = ObjectStage::query()
            ->whereIn('status', ObjectStage::PENDING_STATUSES)
            ->whereIn('object_id', $visible)
            ->orderBy('submitted_at')
            ->limit(200)
            ->get();

        $objects = ConstructionObject::query()
            ->whereIn('id', $rows->pluck('object_id')->unique()->all())
            ->with('customer:id,name_cyr')
            ->get()
            ->keyBy('id');

        // Tuman nomi `master` sxemasida — cross-schema FK yo'q, shuning uchun
        // uni alohida bitta so'rov bilan olib, xotirada birlashtiramiz.
        $districts = DB::connection('qurilish')->table('master.districts')
            ->whereIn('id', $objects->pluck('district_id')->filter()->unique()->all())
            ->pluck('name_cyr', 'id');

        $data = $rows->map(function (ObjectStage $stage) use ($objects, $districts): array {
            $object = $objects->get($stage->object_id);

            return [
                'object_id' => $stage->object_id,
                'object_name' => $object?->name,
                'district_name' => $object?->district_id ? ($districts[$object->district_id] ?? null) : null,
                'customer_name' => $object?->customer?->name_cyr,
                'stage_code' => $stage->stage_code,
                'tz_stage' => $stage->tz_stage,
                'status' => $stage->status,
                'submitted_at' => $stage->submitted_at?->toIso8601String(),
            ];
        })->all();

        return response()->json([
            'data' => $data,
            'total' => count($data),
            'can_moderate' => $this->access->can($user, 'qurilish.stage.moderate'),
        ]);
    }

    /** @return array<string, mixed> */
    private function present(?ObjectStage $stage, string $code, int $index, User $user): array
    {
        $status = $stage?->status ?? 'kutilmoqda';

        return [
            'stage_code' => $code,
            'order' => $index + 1,
            'tz_stage' => ObjectStage::TZ_STAGE[$code],
            'status' => $status,
            'started_at' => $stage?->started_at?->toDateString(),
            'completed_at' => $stage?->completed_at?->toDateString(),
            'submitted_at' => $stage?->submitted_at?->toIso8601String(),
            'reviewed_at' => $stage?->reviewed_at?->toIso8601String(),
            'rejection_reason' => $stage?->rejection_reason,
            'malumot' => $stage?->malumot,
            'note' => $stage?->note,
            'actions' => $this->actionsFor($status, $code, $user),
        ];
    }

    /**
     * Shu holatda shu foydalanuvchiga ochiq amallar.
     * SPA tugmalarni shundan chizadi — ruxsat mantig'i mijozda takrorlanmasin.
     *
     * @return array<int, string>
     */
    private function actionsFor(string $status, string $code, User $user): array
    {
        $canFill = $this->access->can($user, 'qurilish.stage.update');
        $canModerate = $this->access->can($user, 'qurilish.stage.moderate');
        $actions = [];

        if ($canFill && in_array($status, ObjectStage::EDITABLE_STATUSES, true)) {
            $actions[] = 'draft';
        }
        if ($canFill && $status === 'qoralama') {
            $actions[] = 'submit';
        }
        if ($canModerate && $status === 'tasdiqlash_kutilmoqda') {
            $actions[] = 'review';
        }
        if ($canModerate && $status === 'korib_chiqilmoqda') {
            $actions[] = 'approve';
            $actions[] = 'reject';
        }
        if ($canModerate && $code === 'complex_expertise'
            && in_array($status, ['kutilmoqda', 'ochilgan', 'qoralama'], true)) {
            $actions[] = 'not_required';
        }

        return $actions;
    }

    private function respond(ConstructionObject $object, ObjectStage $stage, User $user): JsonResponse
    {
        $fresh = $object->fresh();
        $index = (int) array_search($stage->stage_code, ConstructionObject::STAGES, true);

        return response()->json([
            'data' => $this->present($stage, $stage->stage_code, $index, $user),
            'current_stage' => $fresh->current_stage,
            'lifecycle' => $fresh->lifecycle,
        ]);
    }
}
