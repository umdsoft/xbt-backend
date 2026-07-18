<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('youth_meetings')) {
            return;
        }

        Schema::connection('hr')->create('youth_meetings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('uuid')->unique();
            $table->foreignUuid('hokimlik_id')->constrained('departments')->restrictOnDelete()
                ->comment('Tenant — top-level hokimlik');
            $table->foreignUuid('mahalla_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('chairman_id')->constrained('users')
                ->comment('Hokim/orinbosar — yig\'ilishni o\'tkazgan shaxs');
            $table->date('meeting_date');
            $table->time('meeting_time')->nullable();
            $table->string('location', 500)->nullable();
            $table->unsignedInteger('participants_count')->default(0);
            $table->text('agenda')->nullable();
            $table->text('notes')->nullable();
            $table->text('ai_summary')->nullable()->comment('AI tayyorlagan resume');
            $table->enum('status', ['planned', 'completed', 'cancelled'])->default('planned');
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('hokimlik_id');
            $table->index('mahalla_id');
            $table->index('meeting_date');
            $table->index(['hokimlik_id', 'meeting_date']);
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('youth_meetings');
    }
};
