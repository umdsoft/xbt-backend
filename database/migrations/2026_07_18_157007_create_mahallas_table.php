<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('mahallas')) {
            return;
        }

        Schema::connection('hr')->create('mahallas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('district_id')->constrained('districts')->restrictOnDelete();
            $table->string('name_cyr', 150)->comment('Маҳалла номи (Кирилл)');
            $table->string('name_lat', 150)->comment('Mahalla nomi (Lotin)');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('name_cyr');
            $table->index('district_id');
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('mahallas');
    }
};
