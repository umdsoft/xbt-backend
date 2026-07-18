<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('hy_assignments')) {
            return;
        }

        Schema::connection('hr')->create('hy_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('hokim_yordamchisi_id')->constrained('hokim_yordamchilari')->cascadeOnDelete();
            $table->string('title', 500);
            $table->text('description')->nullable();
            $table->date('due_date')->nullable();
            $table->enum('status', ['planned', 'in_progress', 'done', 'cancelled'])->default('planned');
            $table->text('result_notes')->nullable()->comment('Bajarilish natijasi');
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['hokim_yordamchisi_id', 'status']);
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('hy_assignments');
    }
};
