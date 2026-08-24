<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Controllers\Api\Seating;

use App\Domains\Hr\Http\Controllers\Api\HrController;
use App\Domains\Hr\Models\Venue;
use App\Domains\Hr\Services\Seating\PlanBuilder;
use Illuminate\Http\JsonResponse;

/**
 * Obyektlar (zallar) — GLOBAL ma'lumotnoma. Ko'rish `seating.view` bilan
 * (route'да gating). Geometriya `plan` — frontend SVG uchun (keshlangan).
 */
class VenueController extends HrController
{
    public function index(): JsonResponse
    {
        $venues = Venue::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'uuid', 'name', 'slug', 'unit', 'capacity_cached', 'is_active']);

        return response()->json(['venues' => $venues]);
    }

    public function plan(string $slug, PlanBuilder $builder): JsonResponse
    {
        $venue = Venue::where('slug', $slug)->firstOrFail();

        return response()->json($builder->build($venue));
    }
}
