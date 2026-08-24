<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nomlangan mehmonlar — guruhga biriktirilган o'rindiqlarga ism taqsimlanadi
 * (badge + davomat uchun). Ism ixtiyoriy; present = tadbirга keldi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('event_attendees')) {
            return;
        }

        Schema::connection('hr')->create('event_attendees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('uuid')->unique();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignUuid('event_group_id')->constrained('event_groups')->cascadeOnDelete();
            $table->foreignUuid('seat_row_id')->nullable()->constrained('seat_rows')->nullOnDelete();
            $table->unsignedInteger('seat_number')->nullable();
            $table->string('full_name');
            $table->string('org')->nullable();
            $table->boolean('present')->default(false);
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamps();

            $table->index('event_id');
            $table->index('event_group_id');
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('event_attendees');
    }
};
