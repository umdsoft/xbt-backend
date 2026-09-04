<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F2 — protokol va topshiriq ijrosi.
 *
 * Zanjir: ijrochi (tuman sektor bo'limi) -> sektor boshqarma -> yoshlar
 * boshqarmasi. Har «yuborish» bitta `task_updates` yozuvi bo'lib, u
 * `review_stage` orqali zanjirdan o'tadi — shunda kim/qachon/qanday izoh
 * bilan qaytargani tabiiy saqlanadi.
 *
 * Muddat holati (yashil/sariq/qizil) SAQLANMAYDI — `deadline` dan hisoblanadi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $schema = Schema::connection('yoshlar');

        // Hokim chora-tadbiri / protokoli — topshiriqlarning manbai.
        $this->create($schema, 'protocols', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('number', 60);
            $t->date('protocol_date')->index();
            $t->string('topic', 500);
            $t->text('description')->nullable();
            $t->string('file_path', 500)->nullable();
            $t->string('issued_by', 300)->nullable();
            $t->uuid('created_by')->nullable();
            $t->timestamps();
            $t->unique(['number', 'protocol_date']);
        });

        // Topshiriq — protokol bandi yoki mustaqil ish.
        $this->create($schema, 'tasks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('protocol_id')->nullable()->index();
            $t->string('title', 500);
            $t->text('description')->nullable();
            // Mas'ul tashkilot — ijro va ko'rish doirasi shundan kelib chiqadi.
            $t->uuid('assigned_org_id')->index();
            $t->uuid('district_id')->nullable()->index();
            $t->date('deadline')->index();
            $t->string('priority', 10)->default('orta');
            $t->string('status', 25)->default('belgilandi')->index();
            $t->smallInteger('progress')->default(0);
            $t->uuid('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['assigned_org_id', 'status']);
        });

        // Ijro hisoboti + tasdiqlash zanjiri.
        $this->create($schema, 'task_updates', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('task_id')->index();
            $t->smallInteger('progress');
            $t->text('comment')->nullable();
            $t->string('file_path', 500)->nullable();
            $t->uuid('submitted_by');
            $t->uuid('submitted_org_id')->nullable()->index();
            $t->timestamp('submitted_at');
            // Zanjir bosqichi: sector_review -> youth_review -> approved | returned.
            $t->string('review_stage', 20)->default('sector_review')->index();
            $t->uuid('sector_reviewed_by')->nullable();
            $t->timestamp('sector_reviewed_at')->nullable();
            $t->uuid('youth_reviewed_by')->nullable();
            $t->timestamp('youth_reviewed_at')->nullable();
            $t->text('review_comment')->nullable();
            $t->timestamps();
            $t->index(['task_id', 'review_stage']);
        });

        $this->afterTables();
    }

    /**
     * Bitta topshiriqda bir vaqtda faqat BITTA ochiq yuborish bo'lishi mumkin.
     *
     * Aks holda ijrochi ketma-ket uch marta yuborsa, tasdiqlovchida uch xil
     * foizli navbat paydo bo'lardi va qaysi biri haqiqiy holat ekani
     * noaniq qolardi.
     */
    private function afterTables(): void
    {
        DB::connection('yoshlar')->statement(
            "CREATE UNIQUE INDEX IF NOT EXISTS task_updates_one_open
             ON yoshlar.task_updates (task_id)
             WHERE review_stage IN ('sector_review', 'youth_review')"
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
