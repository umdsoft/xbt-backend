<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Services;

use App\Domains\Mahalla\Support\ExecutiveCache;
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
     * Radius ichidagi binolar (masofa bo'yicha saralangan) + "yana bormi"
     * haqiqiy belgisi.
     *
     * TEXNIKA: SQL'dan `$limit + 1` qator so'raladi. Agar aynan shuncha
     * (yoki ko'proq) qaytsa, demak `$limit`dan tashqarida yana kamida bitta
     * mos qator bor edi — natija chinakam KESILGAN. Bu `count($points) >=
     * $limit` kabi taxminga qaraganda to'g'ri: aynan `$limit`ta mos qator
     * mavjud bo'lib, HECH NARSA kesilmagan holatda ham taxmin `true` deb
     * yolg'on xabar berardi.
     *
     * @param  array<int, string>  $kinds  monitoring|home|org (bo'sh bo'lsa — bo'sh natija)
     * @return array{points: array<int, array<string, mixed>>, has_more: bool}
     */
    public function pointsWithOverflow(
        float $lat,
        float $lng,
        int $radiusM,
        array $kinds,
        int $limit,
        ?string $districtId,
    ): array {
        if ($districtId === null || $kinds === []) {
            return ['points' => [], 'has_more' => false];
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
            return ['points' => [], 'has_more' => false];
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
               ST_Y(b.geom) AS lat,
               ST_X(b.geom) AS lng,
               b.type,
               b.address,
               b.kadastr,
               b.house_number,
               b.street,
               b.street_id,
               b.mahalla_name,
               b.purpose,
               ot.code     AS category,
               ot.name_cyr AS category_label,
               COALESCE(ot.is_social, false) AS is_social,
               h.id        AS house_id,
               h.status    AS overall_status,
               ROUND(ST_Distance(b.geom::geography, pt.g)::numeric)::int AS distance_m
        FROM pt, master.buildings b
        LEFT JOIN master.object_types ot ON ot.id = b.object_type_id
        LEFT JOIN LATERAL (
            SELECT h.id, h.status FROM mahalla.houses h
            WHERE h.building_id = b.id AND h.deleted_at IS NULL
            ORDER BY h.created_at LIMIT 1
        ) h ON true
        WHERE b.district_id = :district_id
          AND b.geom && ST_Expand(pt.gp, :deg)
          AND ST_DWithin(b.geom::geography, pt.g, :radius)
          AND {$kindSql}
        ORDER BY distance_m, b.id
        LIMIT :limit
        SQL;

        $rows = DB::connection('mahalla')->select($sql, [
            'lng1' => $lng,
            'lat1' => $lat,
            'lng2' => $lng,
            'lat2' => $lat,
            'district_id' => $districtId,
            'deg' => $deg,
            'radius' => $radiusM,
            'limit' => $limit + 1,
        ]);

        $all = array_map(static fn ($r) => (array) $r, $rows);
        $hasMore = count($all) > $limit;

        return [
            'points' => array_slice($all, 0, $limit),
            'has_more' => $hasMore,
        ];
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

    /**
     * Nuqta qaysi mahallada (chegara poligoni bo'yicha).
     *
     * `districtId === null` — xuddi yuqoridagi `pointsWithOverflow()`dagi
     * kabi — "qamrov ANIQLANMAGAN" degani, "cheklovsiz qidir" degani EMAS:
     * bunday holatda darhol `null` qaytaramiz, aks holda profili to'liq
     * bo'lmagan (`canSeeAll=false`, `districtId=null`) user uchun `points`
     * bo'sh qaytgan taqdirda ham `current_mahalla` ISTALGAN koordinatada
     * to'ldirilib qolar edi — Task 1'da yopilgan qamrov-kengayish xatosi
     * kichikroq shaklda qaytib kelardi.
     *
     * `is_active = true` — `DistrictGeoJsonController` bilan bir xil filtr:
     * deaktivatsiya qilingan mahalla poligoni "joriy mahalla" sifatida
     * ko'rsatilmasin (bugun hammasi faol, lekin birinchisi o'chirilganda
     * ikkala endpoint kelishmovchiligiga yo'l qo'ymaslik uchun).
     *
     * @return array{id: string, name: string}|null
     */
    public function mahallaForPoint(float $lat, float $lng, ?string $districtId): ?array
    {
        if ($districtId === null) {
            return null;
        }

        $row = DB::connection('master')->selectOne(
            'SELECT id, name_cyr
             FROM master.mahallas
             WHERE boundary IS NOT NULL
               AND is_active = true
               AND ST_Contains(boundary, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326))
               AND district_id = :district_id
             LIMIT 1',
            ['lng' => $lng, 'lat' => $lat, 'district_id' => $districtId],
        );

        return $row === null ? null : ['id' => (string) $row->id, 'name' => (string) $row->name_cyr];
    }

    /**
     * Mahalla chegarasi — GeoJSON Feature. Geometriya kam o'zgaradi, shuning
     * uchun keshlanadi va ST_SimplifyPreserveTopology bilan yengillashtiriladi
     * (tolerance ~0.0003° ≈ 33 m — DistrictGeoJsonController bilan bir xil).
     *
     * `$districtId`: `pointsWithOverflow()`/`mahallaForPoint()` bilan bir xil
     * deny-by-default invariant — `null` bo'lsa cheklovsiz emas, balki
     * "chaqiruvchi allaqachon canSeeAll" degani (controller shunday
     * chaqiradi). Har qanday scoped (canSeeAll=false) user uchun chaqiruvchi
     * haqiqiy `districtId` beradi va bu yerda `AND district_id = :district_id`
     * qo'shiladi — aks holda istalgan foydalanuvchi istalgan (~509) mahalla
     * chegarasini so'rab olardi.
     *
     * KESH KALITI tuman qamrovini o'z ichiga oladi — aks holda bitta
     * (masalan canSeeAll uchun keshlangan) natija boshqa tumanga scoped
     * userga ham noto'g'ri berilib qolishi mumkin edi.
     *
     * `is_active = true` — `DistrictGeoJsonController`/`mahallaForPoint()`
     * bilan bir xil filtr (qarang: yuqoridagi izoh).
     *
     * @return array<string, mixed>|null
     */
    public function boundaryGeoJson(string $mahallaId, ?string $districtId): ?array
    {
        $cacheKey = "nearby:boundary:{$mahallaId}:".($districtId ?? 'all');

        return ExecutiveCache::remember($cacheKey, function () use ($mahallaId, $districtId) {
            $sql = 'SELECT id, name_cyr,
                        ST_AsGeoJSON(ST_SimplifyPreserveTopology(boundary, 0.0003)) AS geojson
                 FROM master.mahallas
                 WHERE id = :id AND boundary IS NOT NULL AND is_active = true';
            $params = ['id' => $mahallaId];

            if ($districtId !== null) {
                $sql .= ' AND district_id = :district_id';
                $params['district_id'] = $districtId;
            }

            $sql .= ' LIMIT 1';

            $row = DB::connection('master')->selectOne($sql, $params);

            if ($row === null) {
                return null;
            }

            return [
                'type' => 'Feature',
                'properties' => ['id' => (string) $row->id, 'name' => (string) $row->name_cyr],
                'geometry' => json_decode((string) $row->geojson, true),
            ];
        });
    }
}
