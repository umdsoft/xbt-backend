<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MASTER — tumanlar/shaharlar. Pilot shu daraja bo'yicha ishlaydi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('master')->hasTable('districts')) {
            return;
        }

        Schema::connection('master')->create('districts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('region_id')->constrained('regions')->restrictOnDelete();
            $table->string('name_cyr', 150);
            $table->string('name_lat', 150);
            $table->string('code', 20)->nullable()->unique();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('region_id');
        });
    }

    public function down(): void
    {
        Schema::connection('master')->dropIfExists('districts');
    }
};
