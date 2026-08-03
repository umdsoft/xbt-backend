<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADVISOR — CHORA-TADBIRLAR (yillik reja) moduli.
 *
 * Manba: xbt/Hr `ControlPlanItem` naqshi (section_title/item_number/title/
 * implementation/deadline/execution_status/execution_report), advisor uchun
 * moslashtirilgan: yillik reja (action_plans) -> bandlar (action_plan_items) ->
 * bajarilishi band×tuman kesimida (action_plan_progress) — KPI matritsa naqshi.
 *
 * `advisor` schema. Naqsh — advisor 3-bosqich (loyihalar) migratsiyasi:
 *   - Faqat PostgreSQL; boshqa drayver -> skip.
 *   - Forward-only, idempotent: mavjud jadval -> o'tkazib yuboriladi.
 *
 * district_id -> master.districts (advisor search_path'ida master bor).
 * created_by/updated_by -> auth.users (cross-schema FK YO'Q; uuid + index).
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

        // Yillik chora-tadbirlar rejasi (yiliga bitta faol reja).
        $this->create($schema, 'action_plans', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->smallInteger('year')->index();
            $t->string('title');
            // draft|active|closed
            $t->string('status', 16)->default('active');
            $t->uuid('created_by')->nullable();           // auth.users.id
            $t->timestamps();
            $t->softDeletes();
        });

        // Reja bandlari (tadbirlar). Bo'lim (I..IV) section_title bilan guruhlanadi.
        $this->create($schema, 'action_plan_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('plan_id')->index();
            $t->string('section_title');                   // "I. Kadrlar malakasini oshirish"
            $t->string('item_number', 16);                // "1", "4.1"
            $t->text('title');                            // Tadbir nomi
            $t->text('mechanism')->nullable();            // Amalga oshirish mexanizmi
            $t->string('deadline_text')->nullable();      // "2026-yil dekabr" (asl matn)
            $t->date('deadline')->nullable();             // asosiy muddat (overdue uchun)
            $t->text('responsible_text')->nullable();     // Mas'ul ijrochilar
            // all_districts (har tuman bajaradi) | viloyat (faqat viloyat darajasi)
            $t->string('scope', 16)->default('all_districts');
            $t->integer('sort_order')->default(0);
            $t->timestamps();
            $t->softDeletes();

            $t->index(['plan_id', 'sort_order']);
        });

        // Bajarilishi — band × tuman kesimi (district_id null = viloyat darajasi).
        $this->create($schema, 'action_plan_progress', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('item_id')->index();
            $t->uuid('district_id')->nullable();          // master.districts (null=viloyat)
            // not_started|in_progress|completed
            $t->string('status', 16)->default('not_started');
            $t->text('report')->nullable();               // Bajarilishi izohi
            $t->unsignedTinyInteger('progress_percent')->default(0);
            $t->uuid('updated_by')->nullable();           // auth.users.id
            $t->timestamps();

            // Bir band × bir tuman uchun bitta yozuv (null district app-logikada
            // updateOrCreate/whereNull orqali boshqariladi — kpi_entries naqshi).
            $t->unique(['item_id', 'district_id']);
        });
    }

    /**
     * Jadvalni faqat mavjud bo'lmasa yaratadi (forward-only, qayta ishга chidamli).
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

        foreach (['action_plan_progress', 'action_plan_items', 'action_plans'] as $table) {
            Schema::connection('advisor')->dropIfExists($table);
        }
    }
};
