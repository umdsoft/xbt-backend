<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Http\Controllers\Api;

use App\Domains\Qurilish\Models\ObjectMonthlyPlan;
use App\Domains\Qurilish\Services\AuditLogger;
use App\Domains\Qurilish\Services\ObjectService;
use App\Domains\Qurilish\Support\QurilishAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Oylik ijro grafigi (Yanvar–Dekabr): reja va amaldagi qiymat.
 *
 * Manbada bu grafik faqat ПҚ-393 va Drayver varaqlarida bor edi; tizimda
 * barcha obyektlar uchun ochiq — buyurtmachi to'ldiradi.
 */
class MonthlyController extends QurilishController
{
    public function __construct(
        QurilishAccess $access,
        private readonly ObjectService $objects,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct($access);
    }

    public function index(Request $request, string $objectId): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.view');

        $object = $this->objects->findOrFail($request->user(), $objectId);
        $year = (int) $request->query('year', (string) now()->year);

        $rows = ObjectMonthlyPlan::query()
            ->where('object_id', $object->id)->where('year', $year)
            ->pluck('planned_amount', 'month');
        $actual = ObjectMonthlyPlan::query()
            ->where('object_id', $object->id)->where('year', $year)
            ->pluck('actual_amount', 'month');

        // 12 oy HAR DOIM to'liq qaytadi — SPA bo'sh oylarni o'zi to'ldirmasin.
        $data = [];
        for ($m = 1; $m <= 12; $m++) {
            $data[] = [
                'month' => $m,
                'planned_amount' => (float) ($rows[$m] ?? 0),
                'actual_amount' => (float) ($actual[$m] ?? 0),
            ];
        }

        return response()->json(['year' => $year, 'data' => $data]);
    }

    public function upsert(Request $request, string $objectId): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.object.update');

        $object = $this->objects->findOrFail($request->user(), $objectId);

        $payload = $request->validate([
            'year' => ['required', 'integer', 'min:2020', 'max:2050'],
            'months' => ['required', 'array', 'min:1', 'max:12'],
            'months.*.month' => ['required', 'integer', 'min:1', 'max:12'],
            'months.*.planned_amount' => ['nullable', 'numeric', 'min:0'],
            'months.*.actual_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        foreach ($payload['months'] as $row) {
            ObjectMonthlyPlan::query()->updateOrCreate(
                ['object_id' => $object->id, 'year' => $payload['year'], 'month' => $row['month']],
                [
                    'planned_amount' => $row['planned_amount'] ?? 0,
                    'actual_amount' => $row['actual_amount'] ?? 0,
                ],
            );
        }

        $this->audit->log($object, $request->user(), 'update', 'monthly_plan', null, (string) $payload['year']);

        return $this->index($request, $objectId);
    }
}
