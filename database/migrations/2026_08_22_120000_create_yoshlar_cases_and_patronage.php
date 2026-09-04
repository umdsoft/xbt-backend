<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F4 — yoshlar muammolari (case management) va otaliq.
 *
 * MUAMMO: `royxatda -> biriktirildi -> jarayonda -> hal_etildi | eskalatsiya`.
 * SLA muddati (`sla_deadline`) topshiriqdagi kabi SAQLANMAGAN holatda
 * hisoblanadi — svetofor `deadline` dan chiqadi.
 *
 * OTALIQ: mentor (can_patronage huquqi bor xodim) <-> yosh. Har uchrashuv
 * `patronage_logs` ga tushadi: KPI «faollik» shu jurnal asosida oʻlchanadi,
 * biriktirish fakti bilan emas — aks holda qogʻozda otaliq boʻlib, amalda
 * hech narsa qilinmasligi mumkin edi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $schema = Schema::connection('yoshlar');

        $this->create($schema, 'youth_cases', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('youth_id')->index();
            $t->uuid('district_id')->index();
            $t->string('category', 30)->index();
            $t->string('source', 20)->default('yosh');
            // Murojaat moduli bilan bogʻlanish — cross-schema FK YOʻQ, faqat havola.
            $t->string('source_ref', 200)->nullable();
            $t->string('title', 500);
            $t->text('description')->nullable();
            $t->string('status', 20)->default('royxatda')->index();
            $t->uuid('assigned_org_id')->nullable()->index();
            $t->uuid('assigned_staff_id')->nullable()->index();
            $t->date('sla_deadline')->nullable()->index();
            $t->timestamp('resolved_at')->nullable();
            $t->text('resolution_note')->nullable();
            $t->uuid('created_by')->nullable();
            $t->uuid('created_by_org_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['district_id', 'status']);
        });

        $this->create($schema, 'patronage', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('youth_id')->index();
            $t->uuid('mentor_staff_id')->index();
            $t->uuid('district_id')->index();
            $t->date('started_at');
            $t->date('ended_at')->nullable();
            $t->boolean('is_active')->default(true)->index();
            $t->text('note')->nullable();
            $t->uuid('created_by')->nullable();
            $t->timestamps();
        });

        $this->create($schema, 'patronage_logs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('patronage_id')->index();
            $t->date('log_date')->index();
            $t->string('kind', 20)->default('uchrashuv');
            $t->text('note');
            $t->uuid('case_id')->nullable()->index();
            $t->uuid('created_by');
            $t->timestamps();
        });

        // Bitta yosh bir vaqtda faqat BITTA faol otaliqda boʻladi: ikki mentor
        // biriktirilsa, javobgarlik yuvilib ketardi («men u qiladi deb oʻyladim»).
        DB::connection('yoshlar')->statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS patronage_one_active_per_youth
             ON yoshlar.patronage (youth_id) WHERE is_active'
        );
    }

    private function create(Builder $schema, string $table, callable $definition): void
    {
        if ($schema->hasTable($table)) {
            return;
        }

        $schema->create($table, $definition);
    }
};
