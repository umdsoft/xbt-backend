<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Controllers\Api\Executive;

use App\Domains\Mahalla\Models\Master\Mahalla;
use App\Domains\Mahalla\Services\ExecutiveMahallaStats;
use App\Domains\Mahalla\Services\ExecutiveStats;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Rahbariyat: mahalla kesimi (zonalar jadvali — qo'lyozma shakli).
 */
class MahallaDashboardController extends Controller
{
    public function __construct(
        private readonly ExecutiveStats $stats,
        private readonly ExecutiveMahallaStats $mahallaStats,
    ) {
    }

    public function __invoke(string $mahalla): JsonResponse
    {
        $model = Mahalla::on('master')->with('district')->findOrFail($mahalla);

        $data = $this->stats->mahalla((string) $model->id);
        $period = $this->stats->period();

        return response()->json([
            'mahalla' => [
                'id' => $model->id,
                'name' => $model->name_cyr,
                'district' => [
                    'id' => $model->district?->id,
                    'name' => $model->district?->name_cyr,
                ],
            ],
            'period' => [
                'today' => $period['today'],
                'week_start' => $period['week_start'],
                'timezone' => $period['timezone'],
            ],
            'households' => $data['households'],
            'rows' => $data['rows'],
            'dynamics' => $this->mahallaStats->dynamics((string) $model->id),
            'zone_status' => $this->mahallaStats->zoneStatus((string) $model->id, $data['households']),
            'recent_changes' => $this->mahallaStats->recentChanges((string) $model->id),
            'staff' => $this->mahallaStats->staff((string) $model->id),
        ]);
    }
}
