<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Http\Controllers\Api;

use App\Domains\Qurilish\Models\RepairNeed;
use App\Domains\Qurilish\Services\RepairNeedService;
use App\Domains\Qurilish\Support\QurilishAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ta'mirtalab obyektlar reyestri + `ПАСПОРТ` jonli hisoboti.
 *
 * Reyestrni boshqarma yuritadi (`qurilish.repair.manage`); hokimlik va
 * prokuratura faqat ko'radi.
 */
class RepairNeedController extends QurilishController
{
    public function __construct(QurilishAccess $access, private readonly RepairNeedService $needs)
    {
        parent::__construct($access);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.view');

        $page = $this->needs->paginate(
            $request->user(),
            $request->only(['sector_id', 'district_id', 'status', 'target_year', 'funding_source_known', 'q']),
            (int) $request->query('per_page', '25'),
        );

        return response()->json([
            'data' => $page->getCollection()->map(fn (RepairNeed $n) => $this->row($n))->all(),
            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.repair.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:2000'],
            'sector_id' => ['nullable', 'uuid'],
            'district_id' => ['nullable', 'uuid'],
            'mahalla_id' => ['nullable', 'uuid'],
            'department_org_id' => ['nullable', 'uuid'],
            'condition_desc' => ['nullable', 'string', 'max:5000'],
            'estimated_amount' => ['nullable', 'numeric', 'min:0'],
            'target_year' => ['nullable', 'integer', 'min:2020', 'max:2050'],
            'funding_source_known' => ['nullable', 'boolean'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:5'],
        ]);

        return response()->json(
            ['data' => $this->row($this->needs->create($request->user(), $data))],
            201,
        );
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.repair.manage');

        $need = $this->needs->findOrFail($request->user(), $id);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:2000'],
            'sector_id' => ['sometimes', 'nullable', 'uuid'],
            'district_id' => ['sometimes', 'nullable', 'uuid'],
            'mahalla_id' => ['sometimes', 'nullable', 'uuid'],
            'condition_desc' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'estimated_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'target_year' => ['sometimes', 'nullable', 'integer', 'min:2020', 'max:2050'],
            'funding_source_known' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'min:1', 'max:5'],
        ]);

        return response()->json(['data' => $this->row($this->needs->update($request->user(), $need, $data))]);
    }

    /** Ta'mirtalab yozuvni real obyektga (qoralama) aylantiradi. */
    public function promote(Request $request, string $id): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.repair.manage');

        $need = $this->needs->findOrFail($request->user(), $id);
        $object = $this->needs->promote($request->user(), $need);

        return response()->json([
            'data' => $this->row($need->refresh()),
            'object' => ['id' => $object->id, 'name' => $object->name, 'lifecycle' => $object->lifecycle],
        ], 201);
    }

    /** `ПАСПОРТ` — soha kesimidagi jonli hisobot. */
    public function pasport(Request $request): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.view');

        $rows = $this->needs->pasport($request->user());

        return response()->json([
            'data' => $rows,
            'totals' => [
                'needs' => array_sum(array_column($rows, 'needs')),
                'amount_total' => array_sum(array_column($rows, 'amount_total')),
                'year_2026' => array_sum(array_column($rows, 'year_2026')),
                'amount_2026' => array_sum(array_column($rows, 'amount_2026')),
                'year_2027' => array_sum(array_column($rows, 'year_2027')),
                'amount_2027' => array_sum(array_column($rows, 'amount_2027')),
                'funding_unknown' => array_sum(array_column($rows, 'funding_unknown')),
                'amount_unknown' => array_sum(array_column($rows, 'amount_unknown')),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function row(RepairNeed $n): array
    {
        return [
            'id' => $n->id,
            'name' => $n->name,
            'sector' => $n->sector?->name_cyr,
            'sector_id' => $n->sector_id,
            'district_id' => $n->district_id,
            'mahalla_id' => $n->mahalla_id,
            'department' => $n->department?->name_cyr,
            'department_org_id' => $n->department_org_id,
            'condition_desc' => $n->condition_desc,
            'estimated_amount' => $n->estimated_amount === null ? null : (float) $n->estimated_amount,
            'target_year' => $n->target_year,
            'funding_source_known' => $n->funding_source_known,
            'priority' => $n->priority,
            'status' => $n->status,
            'promoted_object_id' => $n->promoted_object_id,
            'created_at' => $n->created_at?->toIso8601String(),
        ];
    }
}
