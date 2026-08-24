<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `row_clusters` — DWG'dan connected-components (600mm) bilan chiqarilgan qatorlar.
 * angle faqat 45/90/135; markaz koordinatasi label uchun.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('row_clusters')) {
            return;
        }

        Schema::connection('hr')->create('row_clusters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('uuid')->unique();
            $table->foreignUuid('venue_id')->constrained('venues')->cascadeOnDelete();
            $table->string('code', 32);              // ETL id (RC1..)
            $table->double('angle')->default(90);
            $table->unsignedInteger('seat_count')->default(0);
            $table->double('centroid_x')->default(0);
            $table->double('centroid_y')->default(0);
            $table->timestamps();

            $table->unique(['venue_id', 'code']);
            $table->index('venue_id');
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('row_clusters');
    }
};
