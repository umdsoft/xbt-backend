<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Controllers\Api;

use App\Domains\Mahalla\Models\House;
use App\Domains\Mahalla\Support\MahallaAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Rol/geo ko'lamga qarab honadon statistikasi.
     */
    public function __invoke(Request $request, MahallaAccess $access): JsonResponse
    {
        $scope = $access->scopeFor($request->user());
        $query = House::query()->visibleTo($scope);

        $total = (clone $query)->count();
        $byStatus = (clone $query)
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return response()->json([
            'stats' => [
                'total' => $total,
                'not_started' => (int) ($byStatus['not_started'] ?? 0),
                'in_progress' => (int) ($byStatus['in_progress'] ?? 0),
                'completed' => (int) ($byStatus['completed'] ?? 0),
            ],
        ]);
    }
}
