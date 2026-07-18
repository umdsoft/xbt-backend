<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KUZATUV (observation) = bir honadon zonasiga bir tashrif — N ta RAKURS rasmi bilan.
 * O'zgarish aniqlash BIRLIGI: AI joriy kuzatuvning barcha rakurslarini OLDINGI
 * kuzatuvning rakurslari bilan solishtiradi (rasm-vs-rasm emas).
 *
 * house_photos endi kuzatuvga tegishli (observation_id) — har biri bitta rakurs.
 */
return new class extends Migration
{
    // Ko'p-ulanishli DDL — cross-connection lock deadlock oldini olish uchun.
    public $withinTransaction = false;

    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $conn = Schema::connection('mahalla');

        if (! $conn->hasTable('zone_observations')) {
            $conn->create('zone_observations', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->foreignUuid('house_id')->constrained('houses')->cascadeOnDelete();
                $t->string('zone', 20);
                $t->uuid('user_id')->nullable()->comment('Deputat (auth.users)');
                $t->timestamp('observed_at');

                // Geo (rakurslar o'rtacha/eng uzoq) + on-site
                $t->decimal('lat', 10, 7)->nullable();
                $t->decimal('lng', 10, 7)->nullable();
                $t->decimal('gps_accuracy_m', 8, 2)->nullable();
                $t->decimal('distance_m', 10, 2)->nullable();
                $t->boolean('is_on_site')->nullable();
                $t->unsignedTinyInteger('photo_count')->default(0);

                // O'zgarish aniqlash (kuzatuv-darajasi)
                $t->uuid('prev_observation_id')->nullable()->comment('Oldingi kuzatuv (solishtirish uchun)');
                $t->string('prev_status', 20)->nullable();
                $t->string('status', 20)->nullable()->comment('AI/masul hodim aniqlagan yakuniy holat');
                $t->string('suggested_status', 20)->nullable();
                $t->boolean('is_change')->default(false);

                // AI + review (masul hodim)
                $t->string('decision', 20)->default('pending')->comment('pending|auto_confirmed|flagged|rejected');
                $t->string('decision_reason', 500)->nullable();
                $t->decimal('confidence', 4, 3)->nullable();
                $t->json('ai_result')->nullable();
                $t->uuid('reviewed_by')->nullable();
                $t->timestamp('reviewed_at')->nullable();

                $t->timestamps();

                $t->index(['house_id', 'zone', 'observed_at']);
                $t->index('decision');
                $t->index('is_change');
            });
        }

        // house_photos -> kuzatuvga tegishli rakurs
        if (! $conn->hasColumn('house_photos', 'observation_id')) {
            $conn->table('house_photos', function (Blueprint $t) {
                $t->uuid('observation_id')->nullable()->after('house_id');
                $t->unsignedTinyInteger('angle')->nullable()->after('zone')->comment('Rakurs tartibi (1..N)');
                $t->index('observation_id');
            });
            DB::statement(<<<'SQL'
                DO $$ BEGIN
                    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'house_photos_observation_id_fkey') THEN
                        ALTER TABLE mahalla.house_photos
                            ADD CONSTRAINT house_photos_observation_id_fkey
                            FOREIGN KEY (observation_id) REFERENCES mahalla.zone_observations(id) ON DELETE CASCADE;
                    END IF;
                END $$;
            SQL);
        }

        // Zona holati oxirgi KUZATUVga ishora qiladi
        if (! $conn->hasColumn('house_zone_states', 'last_observation_id')) {
            $conn->table('house_zone_states', function (Blueprint $t) {
                $t->uuid('last_observation_id')->nullable()->after('last_photo_id');
            });
        }
    }

    public function down(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $conn = Schema::connection('mahalla');

        if ($conn->hasColumn('house_zone_states', 'last_observation_id')) {
            $conn->table('house_zone_states', fn (Blueprint $t) => $t->dropColumn('last_observation_id'));
        }

        DB::statement('ALTER TABLE mahalla.house_photos DROP CONSTRAINT IF EXISTS house_photos_observation_id_fkey');
        if ($conn->hasColumn('house_photos', 'observation_id')) {
            $conn->table('house_photos', function (Blueprint $t) {
                $t->dropColumn(['observation_id', 'angle']);
            });
        }

        $conn->dropIfExists('zone_observations');
    }
};
