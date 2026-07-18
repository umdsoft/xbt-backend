<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Honadon monitoringini ZONA-aware qilish (4 zona: fasad/oshxona/hojatxona/tomorqa).
 *  - houses.building_id  -> master.buildings (kadastr bilan bog'lash)
 *  - house_photos.zone   -> rasm qaysi zonaniki (+ zona/kun/tur bo'yicha unikal)
 *  - house_zone_states   -> har (honadon, zona) uchun JORIY holat (hisobot uchun tez)
 *  - house_photo_analyses: zona-aware o'zgarish tahlili (prev/suggested status, is_change)
 */
return new class extends Migration
{
    // Bir nechta ulanish (default + mahalla) DDL'lari — tranzaksiya-wrapper
    // ikki ulanish o'rtasida lock deadlock beradi. Har statement avto-commit bo'lsin.
    public $withinTransaction = false;

    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $conn = Schema::connection('mahalla');

        // 1) houses.building_id (kadastr binosi)
        if (! $conn->hasColumn('houses', 'building_id')) {
            $conn->table('houses', function (Blueprint $t) {
                $t->uuid('building_id')->nullable()->after('id');
                $t->index('building_id');
            });
            DB::statement(<<<'SQL'
                DO $$ BEGIN
                    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'houses_building_id_fkey') THEN
                        ALTER TABLE mahalla.houses
                            ADD CONSTRAINT houses_building_id_fkey
                            FOREIGN KEY (building_id) REFERENCES master.buildings(id) ON DELETE SET NULL;
                    END IF;
                END $$;
            SQL);
        }

        // 2) house_photos.zone + zona/kun bo'yicha unikal
        if (! $conn->hasColumn('house_photos', 'zone')) {
            $conn->table('house_photos', function (Blueprint $t) {
                $t->string('zone', 20)->nullable()->after('house_id');
            });
        }
        DB::statement('ALTER TABLE mahalla.house_photos DROP CONSTRAINT IF EXISTS house_photos_house_id_taken_date_type_unique');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS house_photos_zone_day_uidx ON mahalla.house_photos (house_id, zone, taken_date, type)');
        DB::statement('CREATE INDEX IF NOT EXISTS house_photos_house_zone_idx ON mahalla.house_photos (house_id, zone)');

        // 3) house_zone_states — joriy holat (honadon x zona)
        if (! $conn->hasTable('house_zone_states')) {
            $conn->create('house_zone_states', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->foreignUuid('house_id')->constrained('houses')->cascadeOnDelete();
                $t->string('zone', 20);
                $t->string('status', 20)->default('needs_work');
                $t->unsignedTinyInteger('progress_percent')->default(0);
                $t->uuid('last_photo_id')->nullable()->comment('Oxirgi kuzatuv rasmi');
                $t->timestamp('last_observed_at')->nullable()->comment('Oxirgi rasm/kuzatuv');
                $t->timestamp('last_changed_at')->nullable()->comment('Holat oxirgi marta ozgargan vaqt');
                $t->timestamps();
                $t->unique(['house_id', 'zone']);
                $t->index('status');
            });
        }

        // 4) house_photo_analyses — zona-aware o'zgarish tahlili
        foreach ([
            'zone' => "ALTER TABLE mahalla.house_photo_analyses ADD COLUMN IF NOT EXISTS zone varchar(20)",
            'prev_status' => "ALTER TABLE mahalla.house_photo_analyses ADD COLUMN IF NOT EXISTS prev_status varchar(20)",
            'suggested_status' => "ALTER TABLE mahalla.house_photo_analyses ADD COLUMN IF NOT EXISTS suggested_status varchar(20)",
            'is_change' => "ALTER TABLE mahalla.house_photo_analyses ADD COLUMN IF NOT EXISTS is_change boolean NOT NULL DEFAULT false",
        ] as $sql) {
            DB::statement($sql);
        }
    }

    public function down(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $conn = Schema::connection('mahalla');
        $conn->dropIfExists('house_zone_states');

        DB::statement('ALTER TABLE mahalla.house_photo_analyses DROP COLUMN IF EXISTS zone, DROP COLUMN IF EXISTS prev_status, DROP COLUMN IF EXISTS suggested_status, DROP COLUMN IF EXISTS is_change');

        DB::statement('DROP INDEX IF EXISTS mahalla.house_photos_zone_day_uidx');
        DB::statement('DROP INDEX IF EXISTS mahalla.house_photos_house_zone_idx');
        DB::statement('ALTER TABLE mahalla.house_photos DROP COLUMN IF EXISTS zone');

        DB::statement('ALTER TABLE mahalla.houses DROP CONSTRAINT IF EXISTS houses_building_id_fkey');
        if ($conn->hasColumn('houses', 'building_id')) {
            $conn->table('houses', fn (Blueprint $t) => $t->dropColumn('building_id'));
        }
    }
};
