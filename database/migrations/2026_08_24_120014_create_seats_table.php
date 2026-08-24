<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `seats` — har o'rindiq DWG'dan aniq (x,y) bilan. Formula/generatsiya YO'Q.
 * sector_id/row_label/seat_label keyin (3-bosqich moslashtirish) to'ldiriladi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('seats')) {
            return;
        }

        Schema::connection('hr')->create('seats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('uuid')->unique();
            $table->foreignUuid('venue_id')->constrained('venues')->cascadeOnDelete();
            $table->string('code', 40);                     // K1, K1m, ...
            $table->double('x');
            $table->double('y');
            $table->double('rotation')->default(0);
            $table->string('seat_group', 8)->nullable();    // K / O / Y
            $table->foreignUuid('row_cluster_id')->nullable()->constrained('row_clusters')->nullOnDelete();
            $table->foreignUuid('sector_id')->nullable()->constrained('sectors')->nullOnDelete();
            $table->string('row_label', 32)->nullable();    // "7-SEKTOR 9-QATOR" moslashtirilgach
            $table->string('seat_label', 32)->nullable();
            $table->boolean('is_mirrored')->default(false);
            $table->timestamps();

            $table->index(['venue_id', 'sector_id']);
            $table->index(['venue_id', 'row_cluster_id']);
            $table->unique(['venue_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('seats');
    }
};
