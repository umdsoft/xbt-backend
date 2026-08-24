<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * seat_rows eskirdi — geometriya endi `seats` (aniq x,y) + `row_clusters` da.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }
        Schema::connection('hr')->dropIfExists('seat_rows');
    }

    public function down(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('seat_rows')) {
            return;
        }
        Schema::connection('hr')->create('seat_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('uuid')->unique();
            $table->foreignUuid('sector_id')->constrained('sectors')->cascadeOnDelete();
            $table->unsignedInteger('row_index');
            $table->unsignedInteger('seat_count');
            $table->unsignedInteger('seat_start')->default(1);
            $table->jsonb('seat_labels_json')->nullable();
            $table->jsonb('points_json')->nullable();
            $table->timestamps();
            $table->unique(['sector_id', 'row_index']);
        });
    }
};
