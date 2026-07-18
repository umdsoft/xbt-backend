<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HONADON (operatsion) — honadon. Kadastr + koordinata = anti-cheating "haqiqat manbai".
 * Geo (mahalla/ko'cha) MASTER schema'dan FK orqali (cross-schema, search_path bilan).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('mahalla')->hasTable('houses')) {
            return;
        }

        Schema::connection('mahalla')->create('houses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Cross-schema FK -> master.* (search_path: honadon,master,public)
            $table->foreignUuid('district_id')->constrained('districts')->restrictOnDelete()->comment('Scoping (deputat tumani)');
            $table->foreignUuid('mahalla_id')->constrained('mahallas')->restrictOnDelete();
            $table->foreignUuid('street_id')->constrained('streets')->restrictOnDelete();

            $table->string('cadastral_number', 60)->nullable()->unique();
            $table->decimal('lat', 10, 7)->nullable()->comment('Ground truth kenglik');
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('owner_name', 255)->nullable();

            $table->enum('status', ['not_started', 'in_progress', 'completed'])->default('not_started');
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->date('last_photo_date')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('district_id');
            $table->index('mahalla_id');
            $table->index('street_id');
            $table->index(['district_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection('mahalla')->dropIfExists('houses');
    }
};
