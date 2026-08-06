<?php

declare(strict_types=1);

namespace App\Domains\Murojaat\Http\Controllers\Api;

use App\Domains\Murojaat\Services\AnalyticsService;
use App\Domains\Murojaat\Support\MurojaatAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DynamicsController extends Controller
{
    private const PERIODS = ['kun', 'hafta', 'oy', 'chorak', 'yil'];

    public function __invoke(Request $request, MurojaatAccess $access, AnalyticsService $analytics): JsonResponse
    {
        abort_unless($access->can($request->user(), 'murojaat.view'), 403);

        $period = (string) $request->query('period', 'oy');
        if (! in_array($period, self::PERIODS, true)) {
            $period = 'oy';
        }

        return response()->json($analytics->dynamics($access->scopeFor($request->user()), $period));
    }
}
