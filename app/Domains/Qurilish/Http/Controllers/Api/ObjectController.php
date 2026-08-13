<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Http\Controllers\Api;

use App\Domains\Qurilish\Models\ConstructionObject;
use App\Domains\Qurilish\Services\ObjectService;
use App\Domains\Qurilish\Support\QurilishAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Obyekt reyestri — ro'yxat, kartochka, yaratish, tahrirlash. */
class ObjectController extends QurilishController
{
    public function __construct(QurilishAccess $access, private readonly ObjectService $objects)
    {
        parent::__construct($access);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.view');

        $page = $this->objects->paginate(
            $request->user(),
            $request->only([
                'program_id', 'sector_id', 'district_id', 'customer_org_id',
                'contractor_org_id', 'department_org_id', 'lifecycle',
                'current_stage', 'work_type', 'overdue', 'q',
            ]),
            (int) $request->query('per_page', '25'),
        );

        return response()->json([
            'data' => $page->getCollection()->map(fn (ConstructionObject $o) => $this->row($o))->all(),
            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.view');

        $object = $this->objects->findOrFail($request->user(), $id);
        $object->load(['program', 'sector', 'customer', 'designer', 'contractor', 'department', 'stages']);

        return response()->json(['data' => $this->card($object)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.object.create');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:2000'],
            'program_id' => ['nullable', 'uuid'],
            'sector_id' => ['nullable', 'uuid'],
            'district_id' => ['nullable', 'uuid'],
            'mahalla_id' => ['nullable', 'uuid'],
            'work_type' => ['nullable', 'in:'.implode(',', ConstructionObject::WORK_TYPES)],
            'limit_amount' => ['nullable', 'numeric', 'min:0'],
            'deadline_date' => ['nullable', 'date'],
            'deadline_year' => ['nullable', 'integer', 'min:2020', 'max:2050'],
            'is_carryover' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        $object = $this->objects->create($request->user(), $data);

        return response()->json(['data' => $this->card($object->fresh())], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.object.update');

        $object = $this->objects->findOrFail($request->user(), $id);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:2000'],
            'program_id' => ['sometimes', 'nullable', 'uuid'],
            'sector_id' => ['sometimes', 'nullable', 'uuid'],
            'district_id' => ['sometimes', 'nullable', 'uuid'],
            'mahalla_id' => ['sometimes', 'nullable', 'uuid'],
            'work_type' => ['sometimes', 'nullable', 'in:'.implode(',', ConstructionObject::WORK_TYPES)],
            'customer_org_id' => ['sometimes', 'nullable', 'uuid'],
            'designer_org_id' => ['sometimes', 'nullable', 'uuid'],
            'contractor_org_id' => ['sometimes', 'nullable', 'uuid'],
            'department_org_id' => ['sometimes', 'nullable', 'uuid'],
            'limit_amount' => ['sometimes', 'numeric', 'min:0'],
            'tender_amount' => ['sometimes', 'numeric', 'min:0'],
            'contract_amount' => ['sometimes', 'numeric', 'min:0'],
            'disbursed_amount' => ['sometimes', 'numeric', 'min:0'],
            'financed_amount' => ['sometimes', 'numeric', 'min:0'],
            'deadline_date' => ['sometimes', 'nullable', 'date'],
            'deadline_year' => ['sometimes', 'nullable', 'integer', 'min:2020', 'max:2050'],
            'is_carryover' => ['sometimes', 'boolean'],
            'lifecycle' => ['sometimes', 'in:'.implode(',', ConstructionObject::LIFECYCLES)],
            'handover_planned' => ['sometimes', 'boolean'],
            'note' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        $object = $this->objects->update($request->user(), $object, $data);

        return response()->json(['data' => $this->card($object)]);
    }

    /** @return array<string, mixed> ro'yxat uchun yengil ko'rinish */
    private function row(ConstructionObject $o): array
    {
        return [
            'id' => $o->id,
            'registry_id' => $this->registryId($o),
            'name' => $o->name,
            'program' => $o->program?->name_lat,
            'sector' => $o->sector?->name_lat,
            'district_id' => $o->district_id,
            'customer' => $o->customer?->name_lat,
            'contractor' => $o->contractor?->name_lat,
            'department' => $o->department?->name_lat,
            'limit_amount' => (float) $o->limit_amount,
            'contract_amount' => (float) $o->contract_amount,
            'disbursed_amount' => (float) $o->disbursed_amount,
            'progress_pct' => $this->progress($o),
            'lifecycle' => $o->lifecycle,
            'current_stage' => $o->current_stage,
            'deadline_date' => $o->deadline_date?->toDateString(),
            'is_overdue' => $o->is_overdue,
            'handover_done' => $o->handover_done,
        ];
    }

    /** @return array<string, mixed> to'liq kartochka */
    private function card(ConstructionObject $o): array
    {
        return array_merge($this->row($o), [
            'work_type' => $o->work_type,
            'mahalla_id' => $o->mahalla_id,
            'program_id' => $o->program_id,
            'sector_id' => $o->sector_id,
            'customer_org_id' => $o->customer_org_id,
            'designer_org_id' => $o->designer_org_id,
            'contractor_org_id' => $o->contractor_org_id,
            'department_org_id' => $o->department_org_id,
            'designer' => $o->designer?->name_lat,
            'tender_amount' => (float) $o->tender_amount,
            'financed_amount' => (float) $o->financed_amount,
            'tender_saving' => (float) $o->limit_amount - (float) $o->tender_amount,
            'deadline_raw' => $o->deadline_raw,
            'deadline_year' => $o->deadline_year,
            'is_carryover' => $o->is_carryover,
            'handover_planned' => $o->handover_planned,
            'note' => $o->note,
            'source' => $o->source,
            'stages' => $o->stages->map(fn ($s) => [
                'stage_code' => $s->stage_code,
                'status' => $s->status,
                'started_at' => $s->started_at?->toDateString(),
                'completed_at' => $s->completed_at?->toDateString(),
                'note' => $s->note,
            ])->all(),
        ]);
    }

    /**
     * Reyestr ID: sun'iy kalit (`dxsh:7`, `...#219`) foydalanuvchiga
     * reyestr raqami sifatida ko'rsatilmaydi — u bizning ichki kalitimiz.
     */
    private function registryId(ConstructionObject $o): ?string
    {
        $id = (string) $o->external_id;

        return $id === '' || str_contains($id, ':') || str_contains($id, '#') ? null : $id;
    }

    private function progress(ConstructionObject $o): float
    {
        $base = (float) $o->contract_amount ?: (float) $o->limit_amount;

        return $base > 0 ? round((float) $o->disbursed_amount / $base * 100, 1) : 0.0;
    }
}
