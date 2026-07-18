<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('control_plans')) {
            return;
        }

        Schema::connection('hr')->create('control_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('uuid')->unique();
            $table->text('title')->comment('Тўлиқ расмий сарлавҳа');
            $table->string('document_number', 100)->nullable()->comment('Ҳужжат рақами (ПҚ-89, Ф-123)');
            $table->date('document_date')->nullable()->comment('Ҳужжат санаси');
            $table->enum('status', ['active', 'completed', 'archived'])->default('active');
            $table->string('status_date', 100)->nullable()->comment('Ҳолат санаси (2026 йил 1 апрел ҳолатига)');
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('control_plans');
    }
};
