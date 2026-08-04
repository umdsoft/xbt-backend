<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADVISOR — «СВОД ЖАДВАЛЛАР» (qaror/farmon ijrosi monitoringi).
 *
 * Har svod (monitoring_sheets) = bitta qaror/farmon; ustunlari (monitoring_metrics)
 * og'irlikли; (svod × tuman) satri (monitoring_entries) tasdiq-oqimи bilan; qiymatlar
 * (monitoring_values) ustun kesimida. UMUMIY TAYYORLIK = Σ(value×weight)/Σ(weight).
 *
 * `advisor` schema. Faqat PostgreSQL; forward-only, idempotent (mavjud jadval -> skip).
 * district_id -> master.districts (cross-schema FK YO'Q; uuid + index).
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

        // Svod jadval (qaror/farmon).
        $this->create($schema, 'monitoring_sheets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('title', 500);
            $t->string('basis', 1000)->nullable();          // asos/subtitle
            $t->string('reference_no', 100)->nullable();     // qaror/farmon raqami
            $t->date('reference_date')->nullable();
            // qaror|farmon|pq|farmoyish|other
            $t->string('category', 24)->default('other')->index();
            $t->date('as_of_date')->nullable();              // «... holatiga»
            $t->unsignedTinyInteger('completion_threshold')->default(100);
            $t->string('status', 16)->default('active')->index(); // active|archived
            $t->uuid('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        // Svod ustunlari (og'irlikли ko'rsatkichlar).
        $this->create($schema, 'monitoring_metrics', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('sheet_id')->index();
            $t->string('name', 255);
            $t->string('unit', 16)->default('%');
            $t->decimal('weight', 6, 2)->default(0);         // % ulush (yig'indi ~100)
            $t->integer('sort_order')->default(0);
            $t->timestamps();

            $t->index(['sheet_id', 'sort_order']);
        });

        // (Svod × tuman) satri — tasdiq oqimi bilan.
        $this->create($schema, 'monitoring_entries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('sheet_id')->index();
            $t->uuid('district_id');                          // master.districts.id
            $t->text('note')->nullable();
            // draft|submitted|confirmed|returned
            $t->string('review_status', 16)->default('draft');
            $t->timestamp('submitted_at')->nullable();
            $t->uuid('submitted_by')->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->uuid('confirmed_by')->nullable();
            $t->text('return_comment')->nullable();
            $t->uuid('updated_by')->nullable();
            $t->timestamps();

            $t->unique(['sheet_id', 'district_id']);
        });

        // (Entry × ustun) foiz qiymati.
        $this->create($schema, 'monitoring_values', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('entry_id')->index();
            $t->uuid('metric_id')->index();
            $t->decimal('value', 6, 2)->default(0);          // 0..100
            $t->timestamps();

            $t->unique(['entry_id', 'metric_id']);
        });
    }

    /** Jadvalni faqat mavjud bo'lmasa yaratadi (forward-only). */
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

        foreach (['monitoring_values', 'monitoring_entries', 'monitoring_metrics', 'monitoring_sheets'] as $table) {
            Schema::connection('advisor')->dropIfExists($table);
        }
    }
};
