<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('nationalities')) {
            return;
        }

        Schema::connection('hr')->create('nationalities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name_cyr', 50)->unique()->comment('Миллат номи (Кирилл)');
            $table->string('name_lat', 50)->unique()->comment('Millat nomi (Lotin)');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('nationalities');
    }
};
