<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Controllers\Api\Executive;

use App\Domains\Mahalla\Models\Master\District;
use App\Domains\Mahalla\Services\MahallaScoring;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Rahbariyat: «Raqamli mahalla» skoring — mahallalar reytingi + kvadrant.
 *
 * `{district}` ixtiyoriy — berilmasa standart tuman (Shovot). Ma'lumot
 * mahalla_indicators dan (bandlik/kambag'allik/ixtisos); skoring native.
 */
class ScoringController extends Controller
{
    public function __construct(private readonly MahallaScoring $scoring) {}

    public function __invoke(?string $district = null): JsonResponse
    {
        $model = $district !== null
            ? District::on('master')->findOrFail($district)
            : District::on('master')
                ->where('soato_code', (string) config('mahalla.executive.default_district_soato'))
                ->firstOrFail();

        $data = $this->scoring->district((string) $model->id);

        return response()->json([
            'district' => [
                'id' => $model->id,
                'name' => $model->name_cyr,
                'soato' => $model->soato_code,
            ],
            'rows' => $data['rows'],
            'kvadrantlar' => $data['kvadrantlar'],
            'toliqlik' => $data['toliqlik'],
        ]);
    }
}
