<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TT бўлим 5А.2 — employee_work_history жадвали (3-блок: Меҳнат фаолияти).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('employee_work_history')) {
            return;
        }

        Schema::connection('hr')->create('employee_work_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('start_year')->comment('Бошланиш йили');
            $table->smallInteger('end_year')->nullable()->comment('Тугаш йили (NULL = ҳозирги вақт)');
            $table->text('organization_full')->comment('Ташкилот тўлиқ номи — қисқартиришсиз');
            $table->text('position_full')->comment('Лавозим тўлиқ номи');
            $table->string('order_number', 50)->nullable()->comment('Буйруқ рақами');
            $table->date('order_date')->nullable()->comment('Буйруқ санаси');
            $table->integer('sort_order')->default(0)->comment('Хронологик тартиб');
            $table->timestamps();

            $table->index(['employee_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('employee_work_history');
    }
};
