<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Controllers\Api\Executive;

use App\Domains\Mahalla\Services\ObodStats;
use App\Domains\Mahalla\Support\ExecutiveScope;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rahbariyat: mahalla ichidagi OBODONLASHTIRISH kesimi (masъul × ko'cha × iш turi).
 *
 * ExecutiveMahalla sahifasidagi "Ободонлаштириш" tabi ochilganda lazy yuklanadi
 * (asosiy sahifani sekinlashtirmaslik uchun alohida endpoint).
 */
class ObodDashboardController extends Controller
{
    public function __construct(
        private readonly ObodStats $stats,
        private readonly ExecutiveScope $scope,
    ) {}

    public function __invoke(Request $request, string $mahalla): JsonResponse
    {
        $model = $this->scope->mahalla($request->user(), $mahalla);

        return response()->json([
            'mahalla' => [
                'id' => $model->id,
                'name' => $model->name_cyr,
                'district' => [
                    'id' => $model->district?->id,
                    'name' => $model->district?->name_cyr,
                ],
            ],
            ...$this->stats->forMahalla((string) $model->id),
        ]);
    }
}
