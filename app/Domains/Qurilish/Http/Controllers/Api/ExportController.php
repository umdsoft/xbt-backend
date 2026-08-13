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
            ['Номи', 'Объект', 'Лимит (млн)', 'Шартнома (млн)', 'Ўзлаштирилган (млн)', '%', 'Топширилган', 'Муддат бузилган'],
            array_map(fn (array $r): array => [
                $r['name'], $r['objects'], $r['limit_total'], $r['contract_total'],
                $r['disbursed_total'], $r['disbursed_pct'], $r['handover_done'], $r['overdue'],
            ], $rows),
            'СВОД',
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
                $o->name,
                $o->program?->name_cyr,
                $o->sector?->name_cyr,
                $o->customer?->name_cyr,
                $o->contractor?->name_cyr,
                (float) $o->limit_amount,
                (float) $o->contract_amount,
                (float) $o->disbursed_amount,
                $o->deadline_date?->toDateString(),
                $o->current_stage,
                $o->lifecycle,
                $o->is_overdue ? 'ҳа' : 'йўқ',
            ];
        }

        $xlsx = SimpleXlsx::build(
            ['Объект ID', 'Номи', 'Дастур', 'Соҳа', 'Буюртмачи', 'Пудратчи',
                'Лимит (млн)', 'Шартнома (млн)', 'Ўзлаштирилган (млн)',
                'Муддат', 'Жорий босқич', 'Ҳолат', 'Кечиккан'],
            $rows,
            'Объектлар',
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
