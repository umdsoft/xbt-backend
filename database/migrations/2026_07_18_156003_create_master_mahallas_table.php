<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MASTER — mahallalar. Markaz koordinatasi (xaritada ko'rsatish uchun) ham saqlanadi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('master')->hasTable('mahallas')) {
            return;
        }

        Schema::connection('master')->create('mahallas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('district_id')->constrained('districts')->restrictOnDelete();
            $table->string('name_cyr', 150);
            $table->string('name_lat', 150);
            $table->decimal('center_lat', 10, 7)->nullable();
            $table->decimal('center_lng', 10, 7)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('district_id');
            $table->index('name_cyr');
        });
    }

    public function down(): void
    {
        Schema::connection('master')->dropIfExists('mahallas');
    }
};
