<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Controllers\Api\Seating;

use App\Domains\Hr\Http\Controllers\Api\HrController;
use App\Domains\Hr\Models\Venue;
use App\Domains\Hr\Services\Seating\PlanBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Obyektlar (zallar) — GLOBAL ma'lumotnoma. Ko'rish `seating.view`; import va
 * kalibrlash `venues.manage` (route'da gating). Geometriya `plan` — keshlangan.
 *
 * ESLATMA: yangi model DWG'dan aniq (x,y) geometriya oladi — formula/kalibrlash YO'Q.
 * Import artisan `venue:import` buyrug'i orqali bajariladi. Quyidagi HTTP import/calibrate
 * eski formula-modeli qoldig'i bo'lib, route'lar buzilmasligi uchun saqlab qolingan.
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

    /**
     * Obyekt import — endi DWG parser (artisan venue:import) orqali amalga oshiriladi.
     * HTTP wizard-import o'chirildi: aniq CAD geometriya kod bilan generatsiya qilinmaydi.
     */
    public function import(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Обект импорти учун artisan venue:import буйруғини ишлатинг.',
        ], 422);
    }

    /**
     * Kalibrlash — aniq CAD geometriya bilan shart emas (no-op).
     * Route buzilmasligi uchun metod saqlab qolindi.
     */
    public function calibrate(Request $request, string $slug): JsonResponse
    {
        return response()->json([
            'message' => 'Аниқ CAD геометрия — калибрлаш шарт эмас.',
        ]);
    }
}
