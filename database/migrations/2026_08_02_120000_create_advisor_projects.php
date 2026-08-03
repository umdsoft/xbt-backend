<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADVISOR — 3-bosqich: LOYIHALAR (spec §4, §6).
 *
 * Mahalla `micro_projects` naqshini takrorlaydi (loyiha + progress tarixi + fayl),
 * lekin advisor uchun: tuman kesimida SI/raqamli loyihalar portfeli.
 *
 * `advisor` schema (poydevor migratsiyasi yaratgan). Naqsh — advisor 2-bosqich:
 *   - Faqat PostgreSQL; boshqa drayver -> skip.
 *   - Forward-only, idempotent: mavjud jadval -> o'sha jadval o'tkazib yuboriladi.
 *
 * district_id -> master.districts (advisor ulanishi search_path'ida master bor).
 * category_id -> advisor.task_categories (arxiv/qidiruv katalogi bilan bir xil).
 * created_by/user_id/uploaded_by -> auth.users (cross-schema FK YO'Q; uuid + index).
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

        // Loyiha (SI/raqamli). Viloyat istalgan tuman uchun, tuman FAQAT o'z tumani.
        $this->create($schema, 'projects', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('district_id')->index();            // master.districts
            $t->uuid('category_id')->nullable()->index(); // advisor.task_categories
            $t->string('title');
            $t->text('description')->nullable();
            $t->date('planned_start')->nullable();
            $t->date('planned_end')->nullable();
            $t->date('actual_end')->nullable();
            // planned|in_progress|done|paused
            $t->string('status', 16)->default('planned');
            $t->unsignedTinyInteger('progress_percent')->default(0);
            $t->uuid('created_by')->nullable();           // auth.users.id
            $t->timestamps();
            $t->softDeletes();

            $t->index('status');
            $t->index('created_at');
        });

        // Progress tarixi (yangilanish yozuvi). Har yozuv ixtiyoriy progress bilan.
        $this->create($schema, 'project_updates', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('project_id')->index();
            $t->uuid('user_id')->nullable();              // auth.users.id
            $t->text('body')->nullable();
            $t->unsignedTinyInteger('progress_percent')->nullable();
            $t->timestampTz('occurred_at')->nullable();
            $t->timestamps();
        });

        // Loyiha fayllari (maxfiy disk — faqat vakolatli route orqali ochiladi).
        $this->create($schema, 'project_files', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('project_id')->index();
            $t->string('path');
            $t->string('original_name')->nullable();
            $t->string('mime', 120)->nullable();
            $t->unsignedBigInteger('size_bytes')->nullable();
            $t->uuid('uploaded_by')->nullable();          // auth.users.id
            $t->timestamps();
        });
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

        foreach (['project_files', 'project_updates', 'projects'] as $table) {
            Schema::connection('advisor')->dropIfExists($table);
        }
    }
};
