<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tadbir obyektlari (zallar). GLOBAL ma'lumotnoma — tenant'ga bog'lanmaydi
 * (Avesto zali butun viloyat uchun umumiy). Geometriya viewbox/stage JSON'da.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('venues')) {
            return;
        }

        Schema::connection('hr')->create('venues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('unit', 8)->default('mm');
            $table->jsonb('viewbox_json')->nullable();
            $table->jsonb('stage_json')->nullable();
            $table->unsignedInteger('capacity_cached')->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('venues');
    }
};
