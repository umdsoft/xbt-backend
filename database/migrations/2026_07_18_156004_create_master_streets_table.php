<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MASTER — ko'chalar. Ko'cha NOMLARI yagona statik ma'lumot (master),
 * ularga mas'ul xodim biriktirish esa operatsion (honadon schema).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('master')->hasTable('streets')) {
            return;
        }

        Schema::connection('master')->create('streets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('mahalla_id')->constrained('mahallas')->restrictOnDelete();
            $table->string('name', 255);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('mahalla_id');
        });
    }

    public function down(): void
    {
        Schema::connection('master')->dropIfExists('streets');
    }
};
