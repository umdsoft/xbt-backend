<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Http\Controllers\Api;

use App\Domains\Advisor\Services\DashboardService;
use App\Domains\Advisor\Support\AdvisorAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Boshqaruv paneli TAHLILI (grafik) — butun yil kesimi (chorak tanlashsiz).
 * Rolga qarab qamrov: tuman FAQAT o'z tumani; viloyat/bo'linma — butun viloyat.
 *
 * SHARTNOMA (frontend tayanadi):
 *   { year, kpi_trend:[{period,label,value}], task_status:[{key,label,value,tone}],
 *     project_status:[{key,label,value,tone}], ranking?:[{district,rank,score}] }
 */
class AnalyticsController extends Controller
{
    public function __construct(
        private readonly AdvisorAccess $access,
        private readonly DashboardService $dashboard,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $v = $request->validate([
            'year' => ['nullable', 'integer', 'between:2020,2100'],
        ]);

        $scope = $this->access->scopeFor($request->user());
        $year = (int) ($v['year'] ?? now()->year);

        return response()->json($this->dashboard->analytics($scope, $year));
    }
}
