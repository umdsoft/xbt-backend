<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Http\Controllers\Api;

use App\Domains\Qurilish\Models\ObjectStage;
use App\Domains\Qurilish\Services\ObjectService;
use App\Domains\Qurilish\Services\StageService;
use App\Domains\Qurilish\Support\QurilishAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Obyekt bosqichlari — ko'rish va holat o'zgartirish. */
class StageController extends QurilishController
{
    public function __construct(
        QurilishAccess $access,
        private readonly ObjectService $objects,
        private readonly StageService $stages,
    ) {
        parent::__construct($access);
    }

    public function index(Request $request, string $objectId): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.view');

        $object = $this->objects->findOrFail($request->user(), $objectId);
        $this->stages->ensureStages($object);

        $rows = ObjectStage::query()->where('object_id', $object->id)->get();
        $byCode = $rows->keyBy('stage_code');

        // Tartib MUHIM: SPA voronkani shu ketma-ketlikda chizadi.
        $data = [];
        foreach (\App\Domains\Qurilish\Models\ConstructionObject::STAGES as $i => $code) {
            $s = $byCode->get($code);
            $data[] = [
                'stage_code' => $code,
                'order' => $i + 1,
                'status' => $s?->status ?? 'boshlanmagan',
                'started_at' => $s?->started_at?->toDateString(),
                'completed_at' => $s?->completed_at?->toDateString(),
                'note' => $s?->note,
            ];
        }

        return response()->json(['data' => $data, 'current_stage' => $object->current_stage]);
    }

    public function update(Request $request, string $objectId, string $stageCode): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.stage.update');

        $object = $this->objects->findOrFail($request->user(), $objectId);

        $payload = $request->validate([
            'status' => ['required', 'string'],
            'started_at' => ['nullable', 'date'],
            'completed_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $stage = $this->stages->update($object, $stageCode, $payload, $request->user());

        return response()->json([
            'data' => [
                'stage_code' => $stage->stage_code,
                'status' => $stage->status,
                'started_at' => $stage->started_at?->toDateString(),
                'completed_at' => $stage->completed_at?->toDateString(),
                'note' => $stage->note,
            ],
            'current_stage' => $object->fresh()->current_stage,
            'lifecycle' => $object->fresh()->lifecycle,
        ]);
    }
}
