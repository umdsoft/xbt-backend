<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Controllers\Api;

use App\Domains\Mahalla\Services\NearbyFinder;
use App\Domains\Mahalla\Support\MahallaAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Турган ТУМАННИНГ маҳалла марказлари — харитадаги «Маҳаллалар» қатлами
 * ва улар орасида маршрут қуриш учун.
 *
 * НЕГА `executive` ичида ЭМАС: харитани депутат ҳам, раҳбар ҳам очади;
 * `executive` префикси `mahalla.viewer` билан ҳимояланган ва депутатга
 * 403 берарди. Қамров инварианти `NearbyController::index` дан
 * СЎЗМА-СЎЗ такрорланади (пастдаги изоҳга қаранг).
 *
 * МАҲАЛЛА СОНИ ҚОТИРИЛМАЙДИ: Шовотда 52, бошқа туманда бошқача,
 * бутун Хоразмда 509. Сон ҳар доим базадан келади.
 */
class MahallaPointsController extends Controller
{
    public function __construct(
        private readonly MahallaAccess $access,
        private readonly NearbyFinder $finder,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $scope = $this->access->scopeFor($request->user());

        /*
         * `??` БИЛАН БИРЛАШТИРИЛМАЙДИ — `MahallaAccess` `districtId = null` ни
         * ИККИ ХИЛ ҳолатда қайтаради: (a) admin/вилоят (`canSeeAll`) ва
         * (b) профили тўлиқ бўлмаган оператив user. `??` уларни ажратмас
         * ва (b) га исталган координата орқали БОШҚА туманни очиб қўярди.
         * Худди шу мантиқ: `NearbyController::index`.
         */
        $districtId = $scope->canSeeAll
            ? $this->finder->districtIdForPoint((float) $data['lat'], (float) $data['lng'])
            : $scope->districtId;

        if ($districtId === null) {
            // Хато ЭМАС: фойдаланувчи туман чегарасидан ташқарида туриши
            // мумкин — харита шунда ҳам бузилмаслиги керак.
            return response()->json(['district' => null, 'points' => [], 'count' => 0]);
        }

        $district = DB::connection('master')->table('districts')
            ->where('id', $districtId)
            ->first(['id', 'name_cyr']);

        if ($district === null) {
            return response()->json(['district' => null, 'points' => [], 'count' => 0]);
        }

        $rows = DB::connection('master')->table('mahallas')
            ->where('district_id', $districtId)
            ->where('is_active', true)
            // Маркази йўқ маҳалла харитада (0,0) — Гвинея кўрфазида —
            // пайдо бўларди. Бундайлар БУТУНЛАЙ чиқарилади.
            ->whereNotNull('center_lat')
            ->whereNotNull('center_lng')
            ->orderBy('sort_order')->orderBy('name_cyr')
            ->get(['id', 'name_cyr', 'center_lat', 'center_lng']);

        $points = $rows->map(fn ($r) => [
            'id' => (string) $r->id,
            'name' => (string) $r->name_cyr,
            'lat' => (float) $r->center_lat,
            'lng' => (float) $r->center_lng,
        ])->all();

        return response()->json([
            'district' => ['id' => (string) $district->id, 'name' => (string) $district->name_cyr],
            'points' => $points,
            'count' => count($points),
        ]);
    }
}
