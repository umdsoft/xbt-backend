<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('appeal_assignments')) {
            return;
        }

        Schema::connection('hr')->create('appeal_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('appeal_id')->constrained('citizen_appeals')->cascadeOnDelete();
            $table->enum('assignee_type', ['council', 'department', 'user']);
            $table->uuid('assignee_id')->comment('Council/Department/User ID');
            $table->foreignUuid('assigned_by')->constrained('users');
            $table->text('reason')->nullable();
            $table->enum('status', ['active', 'completed', 'transferred', 'cancelled'])->default('active');
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['appeal_id', 'status']);
            $table->index(['assignee_type', 'assignee_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('appeal_assignments');
    }
};
