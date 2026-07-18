<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kadastr geo yadrosi (PostGIS): tuman/mahalla chegaralari (MultiPolygon) + SOATO/kadastr
 * kodlari, va yangi `master.buildings` (butun viloyat binolari — nuqta geometriya).
 *   - district <- mahalla: SOATO kod (Tuman_soato_kodi == districts.soato_code)
 *   - building <- mahalla:  ST_Contains(mahalla.boundary, building.geom) (point-in-polygon)
 * PostGIS extension oldindan yoqilgan bo'lishi shart (deploy: CREATE EXTENSION postgis).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        // --- districts: SOATO/kadastr kod + chegara ---
        DB::statement("ALTER TABLE master.districts
            ADD COLUMN IF NOT EXISTS soato_code varchar(20),
            ADD COLUMN IF NOT EXISTS cad_code   varchar(20),
            ADD COLUMN IF NOT EXISTS boundary   geometry(MultiPolygon, 4326)");
        DB::statement("CREATE INDEX IF NOT EXISTS districts_soato_idx ON master.districts (soato_code)");
        DB::statement("CREATE INDEX IF NOT EXISTS districts_boundary_gix ON master.districts USING GIST (boundary)");

        // --- mahallas: SOATO + chegara ---
        DB::statement("ALTER TABLE master.mahallas
            ADD COLUMN IF NOT EXISTS soato_code varchar(20),
            ADD COLUMN IF NOT EXISTS boundary   geometry(MultiPolygon, 4326)");
        DB::statement("CREATE INDEX IF NOT EXISTS mahallas_soato_idx ON master.mahallas (soato_code)");
        DB::statement("CREATE INDEX IF NOT EXISTS mahallas_boundary_gix ON master.mahallas USING GIST (boundary)");

        // --- master.buildings (butun viloyat kadastri) ---
        DB::statement("CREATE TABLE IF NOT EXISTS master.buildings (
            id            uuid PRIMARY KEY,
            kadastr       varchar(40),                    -- manba kadastr raqami (kamdan-kam bo'sh -> NULL)
            type          varchar(20) NOT NULL,           -- residential | non_residential
            geom          geometry(Point, 4326) NOT NULL,
            lat           double precision,
            lng           double precision,
            district_id   uuid REFERENCES master.districts(id) ON DELETE SET NULL,
            mahalla_id    uuid REFERENCES master.mahallas(id)  ON DELETE SET NULL,
            tuman_name    varchar(120),                   -- manba (Kirill)
            mahalla_name  varchar(160),                   -- manba (Kirill)
            street        varchar(200),
            house_number  varchar(60),
            address       varchar(500),
            purpose       varchar(500),                   -- MAQSAD
            category      varchar(300),                   -- TOIFA
            area          numeric(12,2),                  -- MAYDON
            total_area    numeric(12,2),                  -- UMUMIY_FOY
            living_area   numeric(12,2),                  -- YASHASH_MA
            created_at    timestamptz,
            updated_at    timestamptz
        )");
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS buildings_kadastr_uidx ON master.buildings (kadastr)");
        DB::statement("CREATE INDEX IF NOT EXISTS buildings_geom_gix   ON master.buildings USING GIST (geom)");
        DB::statement("CREATE INDEX IF NOT EXISTS buildings_mahalla_idx ON master.buildings (mahalla_id)");
        DB::statement("CREATE INDEX IF NOT EXISTS buildings_district_idx ON master.buildings (district_id)");
        DB::statement("CREATE INDEX IF NOT EXISTS buildings_type_idx    ON master.buildings (type)");
    }

    public function down(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        DB::statement("DROP TABLE IF EXISTS master.buildings");
        DB::statement("ALTER TABLE master.mahallas DROP COLUMN IF EXISTS soato_code, DROP COLUMN IF EXISTS boundary");
        DB::statement("ALTER TABLE master.districts DROP COLUMN IF EXISTS soato_code, DROP COLUMN IF EXISTS cad_code, DROP COLUMN IF EXISTS boundary");
    }
};
