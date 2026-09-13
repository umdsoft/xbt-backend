<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Controllers\Api\Executive;

use App\Domains\Mahalla\Services\ExecutiveStats;
use App\Domains\Mahalla\Support\ExecutiveScope;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Ижтимоий объектлар рўйхати — панелдаги "113" рақами очилганда.
 *
 * `?mahalla=` bilan bitta mahalla doirasida qisqartiriladi (jadvaldagi
 * katakcha bosilganda).
 */
class SocialObjectsController extends Controller
{
    public function __construct(
        private readonly ExecutiveStats $stats,
        private readonly ExecutiveScope $scope,
    ) {}

    public function __invoke(Request $request, string $district): JsonResponse
    {
        $model = $this->scope->district($request->user(), $district);

        $mahallaId = $request->query('mahalla');
        if ($mahallaId !== null) {
            $mahallaId = (string) $mahallaId;

            // Boshqa tumanning mahallasi so'ralsa bo'sh ro'yxat qaytishi
            // kerak, aralashib ketgan ma'lumot emas.
            $belongs = DB::connection('master')
                ->table('mahallas')
                ->where('id', $mahallaId)
                ->where('district_id', $model->id)
                ->exists();

            if (! $belongs) {
                return response()->json(['total' => 0, 'types' => [], 'objects' => []]);
            }
        }

        return response()->json(
            $this->stats->socialObjects((string) $model->id, $mahallaId)
        );
    }
}
