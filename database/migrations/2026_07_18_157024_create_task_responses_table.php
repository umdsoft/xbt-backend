<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('task_responses')) {
            return;
        }

        Schema::connection('hr')->create('task_responses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('control_plan_item_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_name')->nullable();   // ижрочи шахс
            $table->string('author_org')->nullable();     // ташкилот/бўлим номи
            // response | approved | returned | control_removed | restored
            $table->string('type', 30)->default('response');
            $table->text('body')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('control_plan_item_id');
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('task_responses');
    }
};
