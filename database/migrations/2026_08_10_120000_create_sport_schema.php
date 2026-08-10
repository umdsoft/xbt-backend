<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SPORT domeni poydevori — `sport` schema + trainers/trainer_mahallas.
 *
 * «Sport va sog'lomlashtirish» platformasi. Birinchi real modul: mahallalarga
 * biriktirilган seleksioner trenerlar (Excel import → master.mahallas'ga bog'lash).
 * Advisor/Murojaat naqshi: schema jadvallardan OLDIN; faqat PostgreSQL; mavjud
 * jadval -> skip (idempotent, forward-only).
 *
 * PII saqlanmaydi: trener passporti/ЖШИР import'да tashlanadi; bu yerda
 * ustunlari ham yo'q. district_id/mahalla_id -> master (cross-schema FK YO'Q —
 * monitoring naqshi, bulk import xavfsiz).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        DB::connection('sport')->statement('CREATE SCHEMA IF NOT EXISTS sport');
        $schema = Schema::connection('sport');

        // Seleksioner trenerlar (PII YO'Q — ism/telefon operatsion, rais/deputat kabi).
        $this->create($schema, 'trainers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('district_id')->nullable()->index();   // master.districts
            $t->string('full_name');
            $t->string('phone', 64)->nullable();            // operatsion — public sahifada KO'RSATILMAYDI
            $t->date('birth_date')->nullable();
            $t->smallInteger('age')->nullable();
            $t->text('workplace')->nullable();              // ish joyi (sport maktab...)
            $t->string('sport_type')->nullable()->index();  // Кураш, Гандбол...
            $t->text('specialization_raw')->nullable();     // asl "иш жойи + мутахассислик" matni
            $t->decimal('staff_unit', 5, 2)->nullable();    // штат бирлиги
            $t->string('uniform_size', 16)->nullable();
            $t->text('other_workplace')->nullable();
            $t->string('source')->nullable();
            $t->timestamps();
        });

        // Trener → mahalla biriktirish + mahalla konteksti (yoshlar, maktab, sport obj).
        $this->create($schema, 'trainer_mahallas', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('trainer_id')->index();
            $t->uuid('mahalla_id')->nullable()->unique();   // master.mahallas (bir mahalla — bir trener)
            $t->string('mahalla_name_raw');                 // xom nom (moslanmasa audit uchun)
            $t->uuid('district_id')->nullable()->index();
            $t->text('schools')->nullable();                // "10-мактаб"
            $t->smallInteger('sport_objects_count')->nullable();
            $t->decimal('youth_7_17', 10, 2)->nullable();
            $t->decimal('youth_7_30', 10, 2)->nullable();
            $t->decimal('youth_14_30', 10, 2)->nullable();
            $t->decimal('youth_16_30', 10, 2)->nullable();
            $t->decimal('youth_30_50', 10, 2)->nullable();
            $t->decimal('pop_30plus', 10, 2)->nullable();
            $t->integer('pop_total')->nullable();
            $t->decimal('plan_2026', 12, 2)->nullable();
            $t->decimal('plan_percent', 6, 3)->nullable();
            $t->timestamps();
        });
    }

    private function create($schema, string $table, callable $definition): void
    {
        if ($schema->hasTable($table)) {
            return;
        }

        $schema->create($table, $definition);
    }

    public function down(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        foreach (['trainer_mahallas', 'trainers'] as $table) {
            Schema::connection('sport')->dropIfExists($table);
        }
    }
};
