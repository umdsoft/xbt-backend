<?php

declare(strict_types=1);

use App\Domains\Advisor\Support\KpiCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADVISOR — 4-bosqich: KPI + REYTING (spec §4, §7, §8).
 *
 * `advisor` schema (poydevor migratsiyasi yaratgan). Naqsh — advisor 2/3-bosqich:
 *   - Faqat PostgreSQL; boshqa drayver -> skip.
 *   - Forward-only, idempotent: mavjud jadval -> o'sha jadval o'tkazib yuboriladi.
 *
 * KPI katalogi (13 viloyat + 11 tuman) migratsiyada ham SEED qilinadi (KpiCatalog
 * yagona manba) — task_categories naqshi: `migrate --force` dan keyin katalog
 * bazada mavjud bo'ladi (KpiCatalogSeeder db:seed uchun ham shu manbani ishlatadi).
 *
 * district_id -> master.districts (null = viloyat darajasi). kpi_id -> advisor.kpis.
 * entered_by/approved_by -> auth.users (cross-schema FK YO'Q; uuid + index).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        DB::connection('advisor')->statement('CREATE SCHEMA IF NOT EXISTS advisor');

        $schema = Schema::connection('advisor');

        // KPI katalogi (yo'riqnoma IX bo'limi). code — barqaror kalit.
        $this->create($schema, 'kpis', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code', 60)->unique();
            $t->string('name');
            $t->string('unit', 40)->nullable();
            $t->string('scope', 10);                 // viloyat | tuman
            $t->unsignedSmallInteger('sort')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();

            $t->index('scope');
        });

        // Maqsadli qiymatlar (reja). district_id null = viloyat darajasi.
        $this->create($schema, 'kpi_targets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('kpi_id')->index();
            $t->uuid('district_id')->nullable()->index(); // master.districts (null = viloyat)
            $t->string('period', 12);                     // masalan "2026-Q2" yoki "2026"
            $t->decimal('target', 16, 2)->default(0);
            $t->timestamps();

            $t->index('period');
        });

        // Kiritilgan qiymatlar (ijro). Bir (kpi, district, period) -> bitta entry.
        $this->create($schema, 'kpi_entries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('kpi_id')->index();
            $t->uuid('district_id')->nullable()->index(); // master.districts (null = viloyat)
            $t->string('period', 12);
            $t->decimal('value', 16, 2)->default(0);
            $t->text('note')->nullable();
            $t->string('source', 10)->default('manual');  // manual | auto
            $t->string('status', 12)->default('submitted'); // draft | submitted | approved
            $t->uuid('entered_by')->nullable();           // auth.users.id
            $t->uuid('approved_by')->nullable();          // auth.users.id
            $t->timestamps();

            $t->index('period');
            $t->index('status');
        });

        // Choraklik/oylik tuman reytingi (KPI ijro + topshiriq ijro + loyiha).
        $this->create($schema, 'rankings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('period', 12)->index();
            $t->uuid('district_id')->index();             // master.districts
            $t->decimal('score', 8, 2)->default(0);
            $t->unsignedSmallInteger('rank')->default(0);
            $t->timestampTz('computed_at')->nullable();
            $t->timestamps();

            // Bir davr uchun bir tumanga bitta reyting yozuvi.
            $t->unique(['period', 'district_id']);
        });

        // Uniqueness — nullable district_id bilan (PG: NULL'lar distinct, shuning
        // uchun partial unique indeks: viloyat (null) va tuman (not null) alohida).
        $this->uniquePair('kpi_targets');
        $this->uniquePair('kpi_entries');

        // KPI katalogini SEED qilish (yagona manba: KpiCatalog). Idempotent.
        KpiCatalog::seed();
    }

    /**
     * (kpi_id, period, district_id) bo'yicha uniqueness — nullable district_id:
     * tuman (district not null) va viloyat (district null) uchun 2 partial indeks.
     */
    private function uniquePair(string $table): void
    {
        $conn = DB::connection('advisor');

        $conn->statement(
            "CREATE UNIQUE INDEX IF NOT EXISTS {$table}_kpi_district_period_uq ".
            "ON advisor.{$table} (kpi_id, district_id, period) WHERE district_id IS NOT NULL"
        );
        $conn->statement(
            "CREATE UNIQUE INDEX IF NOT EXISTS {$table}_kpi_period_viloyat_uq ".
            "ON advisor.{$table} (kpi_id, period) WHERE district_id IS NULL"
        );
    }

    /**
     * Jadvalni faqat mavjud bo'lmasa yaratadi (forward-only, qayta ishga tushishga chidamli).
     *
     * @param  Builder  $schema
     */
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

        foreach (['rankings', 'kpi_entries', 'kpi_targets', 'kpis'] as $table) {
            Schema::connection('advisor')->dropIfExists($table);
        }
    }
};
