<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Controllers\Api\Executive;

use App\Domains\Mahalla\Services\ExecutiveMahallaStats;
use App\Domains\Mahalla\Services\ExecutiveStats;
use App\Domains\Mahalla\Services\MicroProjectService;
use App\Domains\Mahalla\Support\ExecutiveScope;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rahbariyat: mahalla kesimi (zonalar jadvali — qo'lyozma shakli).
 */
class MahallaDashboardController extends Controller
{
    public function __construct(
        private readonly ExecutiveStats $stats,
        private readonly ExecutiveMahallaStats $mahallaStats,
        private readonly MicroProjectService $microProjects,
        private readonly ExecutiveScope $scope,
    ) {}

    public function __invoke(Request $request, string $mahalla): JsonResponse
    {
        $model = $this->scope->mahalla($request->user(), $mahalla);

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
            // Kadastr turar-joy binolari soni (`master.buildings`,
            // `type = 'residential'`) — sparse `mahalla.houses` jadvalidan
            // EMAS (qarang: ExecutiveStats::mahalla(), 'households_total'
            // nomli dublikat maydon shu sabab OLIB TASHLANGAN edi).
            'households' => $data['households'],
            'indicators' => $data['indicators'],
            'social_objects' => $data['social_objects'],
            'rows' => $data['rows'],
            'dynamics' => $this->mahallaStats->dynamics((string) $model->id),
            'zone_status' => $this->mahallaStats->zoneStatus((string) $model->id, $data['households']),
            'recent_changes' => $this->mahallaStats->recentChanges((string) $model->id),
            'staff' => $this->mahallaStats->staff((string) $model->id),
            // Mikrolойiҳa holatlari (kartada ko'rsatish uchun) — {total, planned, in_progress, done, cancelled}
            'micro_projects' => $this->microProjects->statusCounts((string) $model->id),
            // Passport uchun qo'shimcha maydonlar (2026-09-14). Qo'shimcha
            // MAYDONLAR — yuqoridagi hech biri o'zgarmaydi.
            'streets_count' => $this->mahallaStats->streetsCount((string) $model->id),
            'mfy_building' => $this->mahallaStats->mfyBuilding((string) $model->id),
        ]);
    }
}
