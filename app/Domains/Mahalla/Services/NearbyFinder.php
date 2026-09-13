<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Services;

use Illuminate\Support\Facades\DB;

/**
 * «Атроф» — jonli GPS nuqtasi atrofidagi binolar/tashkilotlar (PostGIS).
 *
 * Tezlik: `b.geom && ST_Expand(...)` bbox predikati `buildings_geom_gix`
 * (GIST) indeksini ishlatadi, `ST_DWithin(geom::geography, ...)` esa aniq
 * METR radiusini hisoblaydi. O'lchangan: 3 km, limit 600 → ~24 ms.
 */
class NearbyFinder
{
    /** Bino turlari bo'yicha qatlam kodlari. */
    public const KIND_MONITORING = 'monitoring';

    public const KIND_HOME = 'home';

    public const KIND_ORG = 'org';

    /**
     * Radius ichidagi binolar, masofa bo'yicha saralangan.
     *
     * @param  array<int, string>  $kinds  monitoring|home|org (bo'sh bo'lsa — bo'sh natija)
     * @return array<int, array<string, mixed>>
     */
    public function points(
        float $lat,
        float $lng,
        int $radiusM,
        array $kinds,
        int $limit,
        ?string $districtId,
    ): array {
        if ($districtId === null || $kinds === []) {
            return [];
        }

        $conds = [];
        if (in_array(self::KIND_ORG, $kinds, true)) {
            $conds[] = "b.type = 'non_residential'";
        }
        if (in_array(self::KIND_MONITORING, $kinds, true)) {
            $conds[] = "(b.type = 'residential' AND h.id IS NOT NULL)";
        }
        if (in_array(self::KIND_HOME, $kinds, true)) {
            $conds[] = "(b.type = 'residential' AND h.id IS NULL)";
        }
        if ($conds === []) {
            return [];
        }
        $kindSql = '('.implode(' OR ', $conds).')';

        // bbox kengayishi GRADUSDA. Uzunlik gradusi kenglikka bog'liq — eng
        // katta (uzunlik) qiymatni olamiz, shunda kenglik bo'yicha ham qoplaydi.
        $deg = $radiusM / (111320.0 * max(cos(deg2rad($lat)), 0.01));

        $sql = <<<SQL
        WITH pt AS (
            SELECT ST_SetSRID(ST_MakePoint(:lng1, :lat1), 4326) AS gp,
                   ST_SetSRID(ST_MakePoint(:lng2, :lat2), 4326)::geography AS g
        )
        SELECT b.id,
               b.lat,
               b.lng,
               b.type,
               b.address,
               b.kadastr,
               b.house_number,
               b.street,
               b.street_id,
               b.mahalla_name,
               ot.code     AS category,
               ot.name_cyr AS category_label,
               COALESCE(ot.is_social, false) AS is_social,
               h.id        AS house_id,
               h.status    AS overall_status,
               ROUND(ST_Distance(b.geom::geography, pt.g)::numeric)::int AS distance_m
        FROM pt, master.buildings b
        LEFT JOIN master.object_types ot ON ot.id = b.object_type_id
        LEFT JOIN mahalla.houses h ON h.building_id = b.id AND h.deleted_at IS NULL
        WHERE b.district_id = :district_id
          AND b.geom && ST_Expand(pt.gp, :deg)
          AND ST_DWithin(b.geom::geography, pt.g, :radius)
          AND {$kindSql}
        ORDER BY distance_m
        LIMIT {$limit}
        SQL;

        $rows = DB::connection('mahalla')->select($sql, [
            'lng1' => $lng,
            'lat1' => $lat,
            'lng2' => $lng,
            'lat2' => $lat,
            'district_id' => $districtId,
            'deg' => $deg,
            'radius' => $radiusM,
        ]);

        return array_map(static fn ($r) => (array) $r, $rows);
    }

    /** Nuqta qaysi tumanda (chegara poligoni bo'yicha). */
    public function districtIdForPoint(float $lat, float $lng): ?string
    {
        $row = DB::connection('master')->selectOne(
            'SELECT id FROM master.districts
             WHERE boundary IS NOT NULL
               AND ST_Contains(boundary, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326))
             LIMIT 1',
            ['lng' => $lng, 'lat' => $lat],
        );

        return $row?->id;
    }
}
