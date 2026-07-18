<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MASTER — viloyatlar. Yagona geo manba (barcha tizimlar shundan o'qiydi).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('master')->hasTable('regions')) {
            return;
        }

        Schema::connection('master')->create('regions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name_cyr', 150);
            $table->string('name_lat', 150);
            $table->string('code', 20)->nullable()->unique();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('master')->dropIfExists('regions');
    }
};
