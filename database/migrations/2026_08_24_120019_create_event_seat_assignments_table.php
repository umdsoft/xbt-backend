<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `event_seat_assignments` — HAR bir o'rindiq uchun aniq biriktirish (per-SEAT).
 * "Bo'sh" = QATOR YO'Q. Bir tadbir + bir o'rindiq = bitta yozuv (double-assign bloklanadi).
 * hr_person_id — ixtiyoriy tashqi HR havolasi (FK YO'Q).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('event_seat_assignments')) {
            return;
        }

        Schema::connection('hr')->create('event_seat_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('uuid')->unique();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignUuid('seat_id')->constrained('seats')->cascadeOnDelete();
            $table->string('status')->default('occupied');   // reserved | occupied | blocked
            $table->foreignUuid('event_group_id')->nullable()->constrained('event_groups')->nullOnDelete();
            $table->string('guest_name')->nullable();
            $table->string('guest_position')->nullable();
            $table->string('guest_org')->nullable();
            $table->uuid('hr_person_id')->nullable();          // tashqi HR havolasi — FK YO'Q
            $table->string('phone')->nullable();
            $table->text('note')->nullable();
            $table->uuid('assigned_by')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'seat_id']);           // double-assign bloklanadi
            $table->index(['event_id', 'status']);
            $table->index(['event_id', 'event_group_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('event_seat_assignments');
    }
};
