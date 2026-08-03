<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Http\Controllers\Api;

use App\Domains\Advisor\Services\DashboardService;
use App\Domains\Advisor\Support\AdvisorAccess;
use App\Domains\Advisor\Support\Period;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * DASHBOARD (spec §10) — rolga qarab agregat kartalar/ogohlantirish/reyting/
 * so'nggi faoliyat. Ko'rish: hamma advisor (dashboard.view). Tarkib rol bilan
 * hal qilinadi (DashboardService). Davr berilmasa joriy chorak.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly AdvisorAccess $access,
        private readonly DashboardService $dashboard,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'dashboard.view'), 403, 'Дашбордни кўришга рухсат йўқ.');

        $v = $request->validate([
            'period' => ['nullable', 'string', 'max:12'],
        ]);

        $scope = $this->access->scopeFor($user);
        $period = $v['period'] ?? Period::current();

        return response()->json($this->dashboard->forUser($scope, $period));
    }
}
