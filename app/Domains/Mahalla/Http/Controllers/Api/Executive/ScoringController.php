<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Controllers\Api\Executive;

use App\Domains\Mahalla\Services\MahallaScoring;
use App\Domains\Mahalla\Support\ExecutiveScope;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rahbariyat: «Raqamli mahalla» skoring — mahallalar reytingi + kvadrant.
 *
 * `{district}` ixtiyoriy — berilmasa standart tuman (Shovot). Ma'lumot
 * mahalla_indicators dan (bandlik/kambag'allik/ixtisos); skoring native.
 */
class ScoringController extends Controller
{
    public function __construct(
        private readonly MahallaScoring $scoring,
        private readonly ExecutiveScope $scope,
    ) {}

    public function __invoke(Request $request, ?string $district = null): JsonResponse
    {
        $model = $this->scope->district($request->user(), $district);

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
