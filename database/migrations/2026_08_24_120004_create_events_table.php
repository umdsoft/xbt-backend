<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tadbir — obyektда bir sanadagi o'tirish rejasi. TENANT: hokimlik_id
 * (BelongsToTenant). Obyekt (venue) global, tadbir esa hokimlikka tegishli.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('events')) {
            return;
        }

        Schema::connection('hr')->create('events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('uuid')->unique();
            $table->foreignUuid('venue_id')->constrained('venues')->restrictOnDelete();
            $table->foreignUuid('hokimlik_id')->constrained('departments')->restrictOnDelete()
                ->comment('Tenant — top-level hokimlik');
            $table->string('title');
            $table->date('event_date');
            $table->time('start_time')->nullable();
            $table->enum('status', ['draft', 'confirmed', 'archived'])->default('draft');
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('hokimlik_id');
            $table->index('venue_id');
            $table->index(['hokimlik_id', 'event_date']);
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('events');
    }
};
