<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kadastr yadrosi ID'larini "mukammal" qilish: barcha tizimlar uchun umumiy, barqaror,
 * har bir bazada (local/prod/kelgusi loyihalar) AYNAN BIR XIL identifikatorlar.
 *
 * Muammo: gen_random_uuid() har bazada har xil ID beradi -> tizimlar bir-biriga bog'lanolmaydi.
 * Yechim: ID = official SOATO kodidan deterministik olinadi (RFC-4122 UUIDv3, md5 asosida).
 *   - tuman.id    = stable_uuid('soato:district:' || soato_code)
 *   - mahalla.id  = stable_uuid('soato:mahalla:'  || soato_code)
 *   - bino.id     = stable_uuid('kadastr:' || kadastr)
 * SOATO/kadastr rasmiy, o'zgarmas kodlar -> ID abadiy barqaror va qayta yaratiladigan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        // 1) Deterministik ID generatori (RFC-4122 UUIDv3, md5 asosida — extension talab qilmaydi)
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION master.stable_uuid(seed text) RETURNS uuid
            LANGUAGE sql IMMUTABLE STRICT AS $func$
                SELECT (
                    substr(h,1,8) || '-' || substr(h,9,4) || '-3' || substr(h,14,3) || '-' ||
                    to_hex( (('x'||substr(h,17,2))::bit(8)::int & 63) | 128 ) || substr(h,19,2) || '-' ||
                    substr(h,21,12)
                )::uuid
                FROM (SELECT md5(seed) AS h) s;
            $func$;
        SQL);
        DB::statement("COMMENT ON FUNCTION master.stable_uuid(text) IS 'Barqaror, deterministik UUIDv3 (barcha tizimlar umumiy yadro ID uchun). Masalan: stable_uuid(''soato:mahalla:1733217039'')'");

        // 2) SOATO tabiiy kalitini kafolatlash: NOT NULL + UNIQUE (deterministik ID shundan olinadi)
        DB::statement("ALTER TABLE master.districts ALTER COLUMN soato_code SET NOT NULL");
        DB::statement("ALTER TABLE master.mahallas  ALTER COLUMN soato_code SET NOT NULL");

        // Eski oddiy indekslar o'rniga UNIQUE (bir SOATO -> bir tuman/mahalla)
        DB::statement("DROP INDEX IF EXISTS master.districts_soato_idx");
        DB::statement("DROP INDEX IF EXISTS master.mahallas_soato_idx");
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS districts_soato_uidx ON master.districts (soato_code)");
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS mahallas_soato_uidx  ON master.mahallas  (soato_code)");

        // 3) Hujjatlashtirish (yadro ekanligini belgilash)
        DB::statement("COMMENT ON COLUMN master.districts.id IS 'Barqaror UUIDv3 = stable_uuid(''soato:district:''||soato_code). Barcha tizimlar shu ID ga bog''lanadi.'");
        DB::statement("COMMENT ON COLUMN master.mahallas.id  IS 'Barqaror UUIDv3 = stable_uuid(''soato:mahalla:''||soato_code). Barcha tizimlar shu ID ga bog''lanadi.'");
        DB::statement("COMMENT ON COLUMN master.buildings.id IS 'Barqaror UUIDv3 = stable_uuid(''kadastr:''||kadastr).'");
    }

    public function down(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        DB::statement("DROP INDEX IF EXISTS master.districts_soato_uidx");
        DB::statement("DROP INDEX IF EXISTS master.mahallas_soato_uidx");
        DB::statement("CREATE INDEX IF NOT EXISTS districts_soato_idx ON master.districts (soato_code)");
        DB::statement("CREATE INDEX IF NOT EXISTS mahallas_soato_idx  ON master.mahallas  (soato_code)");
        DB::statement("DROP FUNCTION IF EXISTS master.stable_uuid(text)");
    }
};
