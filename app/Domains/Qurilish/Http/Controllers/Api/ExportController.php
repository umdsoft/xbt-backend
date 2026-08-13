<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Http\Controllers\Api;

use App\Domains\Qurilish\Models\ConstructionObject;
use App\Domains\Qurilish\Services\DashboardService;
use App\Domains\Qurilish\Services\ObjectService;
use App\Domains\Qurilish\Support\QurilishAccess;
use App\Support\SimpleXlsx;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Excel eksport — СВОД kesimlari va obyekt reyestri.
 *
 * `App\Support\SimpleXlsx` (dependency-siz XLSX yozuvchi) — advisor domenidagi
 * naqsh. Eksport ham `QurilishScope` dan o'tadi: buyurtmachi faqat o'z
 * portfelini yuklab oladi.
 */
class ExportController extends QurilishController
{
    public function __construct(
        QurilishAccess $access,
        private readonly DashboardService $dashboard,
        private readonly ObjectService $objects,
    ) {
        parent::__construct($access);
    }

    public function svod(Request $request, string $dimension): Response
    {
        $this->authorizeAction($request->user(), 'qurilish.export');

        $rows = $this->dashboard->svod($request->user(), $dimension);

        $xlsx = SimpleXlsx::build(
            ['Nomi', 'Obyekt', 'Limit (mln)', 'Shartnoma (mln)', 'O‘zlashtirilgan (mln)', '%', 'Topshirilgan', 'Muddat buzilgan'],
            array_map(fn (array $r): array => [
                $r['name'], $r['objects'], $r['limit_total'], $r['contract_total'],
                $r['disbursed_total'], $r['disbursed_pct'], $r['handover_done'], $r['overdue'],
            ], $rows),
            'SVOD',
        );

        return $this->file($xlsx, "qurilish-svod-{$dimension}.xlsx");
    }

    public function objects(Request $request): Response
    {
        $this->authorizeAction($request->user(), 'qurilish.export');

        $collection = $this->objects->forExport($request->user(), $request->only([
            'program_id', 'sector_id', 'district_id', 'customer_org_id',
            'contractor_org_id', 'department_org_id', 'lifecycle',
            'current_stage', 'work_type', 'overdue', 'is_carryover', 'q',
        ]));

        // Chegara oshsa JIM qirqmaymiz — foydalanuvchiga aniq aytamiz.
        $truncated = $collection->count() > ObjectService::MAX_EXPORT;
        if ($truncated) {
            $collection = $collection->take(ObjectService::MAX_EXPORT);
        }

        $rows = [];
        foreach ($collection as $o) {
            /** @var ConstructionObject $o */
            $rows[] = [
                $o->external_id,
                $o->name_lat ?: $o->name,
                $o->program?->name_lat,
                $o->sector?->name_lat,
                $o->customer?->name_lat,
                $o->contractor?->name_lat,
                (float) $o->limit_amount,
                (float) $o->contract_amount,
                (float) $o->disbursed_amount,
                $o->deadline_date?->toDateString(),
                $o->current_stage,
                $o->lifecycle,
                $o->is_overdue ? 'ha' : 'yo‘q',
            ];
        }

        $xlsx = SimpleXlsx::build(
            ['Obyekt ID', 'Nomi', 'Dastur', 'Soha', 'Buyurtmachi', 'Pudratchi',
                'Limit (mln)', 'Shartnoma (mln)', 'O‘zlashtirilgan (mln)',
                'Muddat', 'Joriy bosqich', 'Holat', 'Kechikkan'],
            $rows,
            'Obyektlar',
        );

        return $this->file($xlsx, 'qurilish-obyektlar.xlsx', $truncated ? ObjectService::MAX_EXPORT : null);
    }

    /** @param  int|null  $truncatedAt  chegara oshgan bo'lsa — qator soni */
    private function file(string $binary, string $name, ?int $truncatedAt = null): Response
    {
        $headers = [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ];

        if ($truncatedAt !== null) {
            // Frontend shu header'ni ko'rib «faqat birinchi N qator» deb ogohlantiradi.
            $headers['X-Qurilish-Truncated-At'] = (string) $truncatedAt;
        }

        return response($binary, 200, $headers);
    }
}
