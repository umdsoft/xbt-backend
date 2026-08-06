<?php

declare(strict_types=1);

namespace App\Domains\Murojaat\Http\Controllers\Api;

use App\Domains\Murojaat\Services\AnalyticsService;
use App\Domains\Murojaat\Support\MurojaatAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppealController extends Controller
{
    public function index(Request $request, MurojaatAccess $access, AnalyticsService $analytics): JsonResponse
    {
        abort_unless($access->can($request->user(), 'murojaat.view'), 403);

        $f = $request->only(['holat', 'manba', 'mahalla', 'tashkilot', 'masala_raqami', 'muddat', 'q', 'page', 'per_page']);

        return response()->json($analytics->appeals($access->scopeFor($request->user()), $f));
    }
}
