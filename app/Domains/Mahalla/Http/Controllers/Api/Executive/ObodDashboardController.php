<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Controllers\Api\Executive;

use App\Domains\Mahalla\Models\Master\Mahalla;
use App\Domains\Mahalla\Services\ObodStats;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Rahbariyat: mahalla ichidagi OBODONLASHTIRISH kesimi (masъul × ko'cha × iш turi).
 *
 * ExecutiveMahalla sahifasidagi "Ободонлаштириш" tabi ochilganda lazy yuklanadi
 * (asosiy sahifani sekinlashtirmaslik uchun alohida endpoint).
 */
class ObodDashboardController extends Controller
{
    public function __construct(private readonly ObodStats $stats)
    {
    }

    public function __invoke(string $mahalla): JsonResponse
    {
        $model = Mahalla::on('master')->with('district')->findOrFail($mahalla);

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
