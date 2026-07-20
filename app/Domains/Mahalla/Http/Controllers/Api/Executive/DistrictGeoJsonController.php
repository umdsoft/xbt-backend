<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Controllers\Api\Executive;

use App\Domains\Mahalla\Models\Master\District;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Xarita uchun mahalla chegaralari (GeoJSON).
 *
 * Asosiy javobdan AJRATILGAN: geometriya ~47 KB va kamdan-kam o'zgaradi,
 * o'zgarish raqamlari esa har daqiqada yangilanadi. Ajratilgani uchun
 * geometriya keshi raqamlar yangilanganda ham amal qiladi.
 *
 * `properties` da FAQAT `id` va `name` bor — raqamlar asosiy javobdan keladi
 * va frontendda `id` bo'yicha bog'lanadi.
 */
class DistrictGeoJsonController extends Controller
{
    /**
     * Soddalashtirish toleransi (daraja). 0.0003 ≈ 33 m.
     * O'lchangan: xom 378 KB -> 46.6 KB. 35 km kenglikdagi tumanni 1500px
     * ekranda ko'rsatganda farqi ko'rinmaydi.
     */
    private const SIMPLIFY_TOLERANCE = 0.0003;

    public function __invoke(string $district): JsonResponse
    {
        $model = District::on('master')->findOrFail($district);

        $rows = DB::connection('master')->table('mahallas')
            ->where('district_id', $model->id)
            // Jadval bilan bir xil filtr — aks holda xaritada jadvalda
            // yo'q poligon paydo bo'lib, raqamlarsiz kulrang turib qolardi.
            ->where('is_active', true)
            ->whereNotNull('boundary')
            ->orderBy('sort_order')->orderBy('name_cyr')
            ->selectRaw('id, name_cyr, ST_AsGeoJSON(ST_SimplifyPreserveTopology(boundary, ?)) as geom',
                [self::SIMPLIFY_TOLERANCE])
            ->get();

        $features = [];
        foreach ($rows as $r) {
            $features[] = [
                'type' => 'Feature',
                'properties' => ['id' => $r->id, 'name' => $r->name_cyr],
                'geometry' => json_decode((string) $r->geom, true),
            ];
        }

        return response()->json(['type' => 'FeatureCollection', 'features' => $features]);
    }
}
