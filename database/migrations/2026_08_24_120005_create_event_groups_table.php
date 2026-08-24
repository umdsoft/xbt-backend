<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tadbir guruhi — rang bilan belgilangan mehmonlar toifasi (masalan tuman/
 * tashkilot). org_id/district_id — HR tashkiloti yoki tuman bilan yumshoq
 * bog'lanish (avtoto'ldirish uchun).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('event_groups')) {
            return;
        }

        Schema::connection('hr')->create('event_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('uuid')->unique();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 16)->default('#2563eb');
            $table->unsignedInteger('expected_count')->nullable();
            $table->uuid('org_id')->nullable()->comment('HR tashkiloti (yumshoq bog\'lanish)');
            $table->foreignUuid('district_id')->nullable()->constrained('districts')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('event_id');
            $table->index('org_id');
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('event_groups');
    }
};
