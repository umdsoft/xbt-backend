<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('positions')) {
            return;
        }

        Schema::connection('hr')->create('positions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->string('name_cyr', 255)->comment('Лавозим номи (Кирилл)');
            $table->string('name_lat', 255)->comment('Lavozim nomi (Lotin)');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('name_cyr');
            $table->index('department_id');
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('positions');
    }
};
