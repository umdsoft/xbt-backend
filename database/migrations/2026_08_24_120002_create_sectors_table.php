<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sektor — obyekt ichidagi o'rindiq bloki. anchor/rotation lokal grid'ni
 * joylashtiradi; o'rindiq koordinatasi runtime'da hisoblanadi (alohida `seats`
 * jadvali YO'Q). polygon_json — ixtiyoriy tayyor hull.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('sectors')) {
            return;
        }

        Schema::connection('hr')->create('sectors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('uuid')->unique();
            $table->foreignUuid('venue_id')->constrained('venues')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('label')->nullable();
            $table->double('anchor_x')->default(0);
            $table->double('anchor_y')->default(0);
            $table->double('rotation')->default(0);
            $table->double('row_pitch')->default(1050);
            $table->double('seat_pitch')->default(550);
            $table->jsonb('polygon_json')->nullable();
            $table->string('tier', 32)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['venue_id', 'code']);
            $table->index('venue_id');
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('sectors');
    }
};
