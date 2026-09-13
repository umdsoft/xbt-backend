<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Controllers\Api\Executive;

use App\Domains\Mahalla\Services\ExecutiveStats;
use App\Domains\Mahalla\Support\ExecutiveScope;
use App\Domains\Mahalla\Support\MahallaZones;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rahbariyat: tuman kesimi (mahallalar jadvali).
 *
 * `{district}` ixtiyoriy — berilmasa sozlamadagi standart tuman (Shovot).
 * Shu tufayli frontend `/executive` ni parametrsiz ocha oladi va tuman kodi
 * faqat konfiguratsiyada turadi.
 */
class DistrictDashboardController extends Controller
{
    public function __construct(
        private readonly ExecutiveStats $stats,
        private readonly ExecutiveScope $scope,
    ) {}

    public function __invoke(Request $request, ?string $district = null): JsonResponse
    {
        $model = $this->scope->district($request->user(), $district);

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
