<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Http\Controllers\Api;

use App\Domains\Qurilish\Services\DashboardService;
use App\Domains\Qurilish\Services\ExecutiveDashboardService;
use App\Domains\Qurilish\Support\QurilishAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Hokimlik/prokuratura boshqaruv paneli — barcha panel bitta so'rovda.
 *
 * Alohida endpointlar ham bor (`/svod/{dim}`, `/funnel`), lekin SPA birinchi
 * yuklanishda `/dashboard` ni chaqiradi: 6 ta parallel so'rov o'rniga bitta.
 */
class DashboardController extends QurilishController
{
    public function __construct(
        QurilishAccess $access,
        private readonly DashboardService $dashboard,
        private readonly ExecutiveDashboardService $executive,
    ) {
        parent::__construct($access);
    }

    /**
     * Rahbariyat paneli — bitta so'rovda TO'LIQ jamlanma.
     * Panelda 10 ga yaqin blok bor; ular uchun alohida so'rov yuborilsa
     * birinchi bo'yoq sezilarli kechikardi.
     */
    public function executive(Request $request): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.view');

        return response()->json($this->executive->build(
            $request->user(),
            (int) $request->query('year', (string) now()->year),
        ));
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.view');

        $user = $request->user();
        $year = (int) $request->query('year', (string) now()->year);

        return response()->json([
            'summary' => $this->dashboard->summary($user),
            'svod' => [
                'dastur' => $this->dashboard->svod($user, 'dastur'),
                'soha' => $this->dashboard->svod($user, 'soha'),
                'tuman' => $this->dashboard->svod($user, 'tuman'),
                'buyurtmachi' => $this->dashboard->svod($user, 'buyurtmachi'),
            ],
            'funnel' => $this->dashboard->funnel($user),
            'execution_buckets' => $this->dashboard->executionBuckets($user),
            'monthly' => $this->dashboard->monthly($user, $year),
            'year' => $year,
        ]);
    }

    public function svod(Request $request, string $dimension): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.view');

        return response()->json([
            'dimension' => $dimension,
            'data' => $this->dashboard->svod($request->user(), $dimension),
        ]);
    }

    public function funnel(Request $request): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.view');

        return response()->json(['data' => $this->dashboard->funnel($request->user())]);
    }

    public function map(Request $request): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.view');

        return response()->json(['data' => $this->dashboard->map($request->user())]);
    }
}
