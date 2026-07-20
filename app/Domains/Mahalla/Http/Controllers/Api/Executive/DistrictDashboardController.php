<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Controllers\Api\Executive;

use App\Domains\Mahalla\Models\Master\District;
use App\Domains\Mahalla\Services\ExecutiveStats;
use App\Domains\Mahalla\Support\MahallaZones;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Rahbariyat: tuman kesimi (mahallalar jadvali).
 *
 * `{district}` ixtiyoriy — berilmasa sozlamadagi standart tuman (Shovot).
 * Shu tufayli frontend `/executive` ni parametrsiz ocha oladi va tuman kodi
 * faqat konfiguratsiyada turadi.
 */
class DistrictDashboardController extends Controller
{
    public function __construct(private readonly ExecutiveStats $stats)
    {
    }

    public function __invoke(?string $district = null): JsonResponse
    {
        $model = $district !== null
            ? District::on('master')->findOrFail($district)
            : District::on('master')
                ->where('soato_code', (string) config('mahalla.executive.default_district_soato'))
                ->firstOrFail();

        $data = $this->stats->district((string) $model->id);
        $period = $this->stats->period();

        return response()->json([
            'district' => [
                'id' => $model->id,
                'name' => $model->name_cyr,
                'soato' => $model->soato_code,
            ],
            'period' => [
                'today' => $period['today'],
                'week_start' => $period['week_start'],
                'timezone' => $period['timezone'],
            ],
            'zones' => MahallaZones::zoneOptions(),
            'rows' => $data['rows'],
            'totals' => $data['totals'],
            'unassigned_households' => $data['unassigned_households'],
            'summary' => $data['summary'],
            'ranking' => $data['ranking'],
        ]);
    }
}
