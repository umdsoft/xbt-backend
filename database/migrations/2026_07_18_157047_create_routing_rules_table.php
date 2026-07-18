<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('routing_rules')) {
            return;
        }

        Schema::connection('hr')->create('routing_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('hokimlik_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignUuid('category_id')->constrained('appeal_categories')->cascadeOnDelete();
            $table->json('conditions')->nullable()->comment('extra match conditions');
            $table->enum('assignee_type', ['council', 'department', 'user']);
            $table->uuid('assignee_id')->nullable()->comment('null = applicant_mahalla council');
            $table->unsignedInteger('sla_hours_override')->nullable();
            $table->unsignedInteger('escalation_after_hours')->nullable();
            $table->json('escalation_to')->nullable();
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['hokimlik_id', 'category_id']);
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('routing_rules');
    }
};
