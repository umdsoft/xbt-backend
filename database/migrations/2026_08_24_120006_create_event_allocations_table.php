<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Belgilash — sektor yoki qatorni guruhga biriktirish. seat_row_id = NULL →
 * BUTUN sektor guruhga tegishli (holatlarning 90%i). Qat'iy qoida: bitta qator
 * ikki guruhga tegishli bo'lmasin — qisman UNIQUE indekslar bilan kafolatlanadi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('event_allocations')) {
            return;
        }

        Schema::connection('hr')->create('event_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('uuid')->unique();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignUuid('sector_id')->constrained('sectors')->restrictOnDelete();
            $table->foreignUuid('seat_row_id')->nullable()->constrained('seat_rows')->cascadeOnDelete()
                ->comment('NULL = butun sektor');
            $table->foreignUuid('event_group_id')->constrained('event_groups')->cascadeOnDelete();
            $table->timestamps();

            $table->index('event_id');
            $table->index('event_group_id');
        });

        // Bitta QATOR bir tadbirda faqat bitta guruhga (seat_row_id to'ldirilган holat).
        DB::connection('hr')->statement(
            'CREATE UNIQUE INDEX event_allocations_row_unique ON event_allocations (event_id, seat_row_id) WHERE seat_row_id IS NOT NULL'
        );
        // Butun-sektor belgilashi ham tadbir+sektor bo'yicha bitta bo'lsin.
        DB::connection('hr')->statement(
            'CREATE UNIQUE INDEX event_allocations_sector_unique ON event_allocations (event_id, sector_id) WHERE seat_row_id IS NULL'
        );
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('event_allocations');
    }
};
