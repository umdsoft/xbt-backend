<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('control_plan_items')) {
            return;
        }

        Schema::connection('hr')->create('control_plan_items', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Умумий топшириқ: режа ичида (control_plan_id) ёки мустақил (NULL).
            $table->foreignUuid('control_plan_id')->nullable()->constrained()->cascadeOnDelete();
            $table->enum('source', ['control_plan', 'standalone'])
                ->default('control_plan')->comment('Топшириқ манбаси');

            // Тенант ва эгалик (мустақил топшириқлар учун ҳам зарур)
            $table->foreignUuid('hokimlik_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->foreignUuid('kompleks_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title', 500)->nullable()->comment('Мустақил топшириқ сарлавҳаси');
            $table->string('item_number', 50)->nullable()->comment('Банд рақами (1 а)-банд, 2)');
            $table->string('section_title', 500)->nullable()->comment('Бўлим сарлавҳаси');
            $table->text('task_description')->comment('Топшириқ мазмуни');
            $table->text('implementation')->nullable()->comment('Амалга ошириш механизми');
            $table->string('funding_source', 500)->nullable()->comment('Молиялаштириш манбаси');
            $table->date('deadline')->nullable()->comment('Ижро муддати');
            $table->enum('execution_status', ['not_started', 'in_progress', 'completed', 'overdue'])
                ->default('not_started')->comment('Бажарилиш ҳолати');
            $table->text('execution_report')->nullable()->comment('Бажарилиши ҳақида маълумот');

            // Тасдиқлаш оқими (EDO): ташкилот ижрони юклагач — котибият мудири тасдиқлайди
            $table->string('review_status', 20)->nullable()
                ->comment('submitted / approved / returned (null = юборилмаган)');
            $table->timestamp('submitted_at')->nullable()->comment('Ташкилот тасдиққа юборган сана');
            $table->timestamp('reviewed_at')->nullable()->comment('Котибият мудири кўриб чиққан сана');
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_comment')->nullable()->comment('Тасдиқлаш/қайтариш изоҳи');

            // Назоратдан ечиш (faqat яратган котибият мудири ёки масъул ходим)
            $table->timestamp('control_removed_at')->nullable()->comment('Назоратдан ечилган сана');
            $table->foreignUuid('control_removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('control_removal_reason')->nullable()->comment('Назоратдан ечиш изоҳи');

            $table->index('review_status');

            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['control_plan_id', 'sort_order']);
            $table->index('hokimlik_id');
            $table->index('source');
            $table->index('execution_status');
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('control_plan_items');
    }
};
