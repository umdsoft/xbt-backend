<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('mahalla_councils')) {
            return;
        }

        Schema::connection('hr')->create('mahalla_councils', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('hokimlik_id')->constrained('departments')->restrictOnDelete();
            $table->foreignUuid('mahalla_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('name', 255)->default('Маҳалла еттилиги');
            $table->string('phone', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('hokimlik_id');
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('mahalla_councils');
    }
};
