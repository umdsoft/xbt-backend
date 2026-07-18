<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HONADON — rasmlar. Baseline yoki kunlik. Jonli suratga olinadi (galereya emas),
 * GPS + server vaqt muhri bilan. Anti-cheating 1-2 qatlami shu yerda.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('mahalla')->hasTable('house_photos')) {
            return;
        }

        Schema::connection('mahalla')->create('house_photos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('house_id')->constrained('houses')->cascadeOnDelete();

            $table->enum('type', ['baseline', 'daily']);
            $table->string('image_path', 500)->comment('Private disk');

            $table->decimal('captured_lat', 10, 7)->nullable();
            $table->decimal('captured_lng', 10, 7)->nullable();
            $table->decimal('gps_accuracy_m', 8, 2)->nullable();
            $table->decimal('distance_m', 10, 2)->nullable()->comment('Uy koordinatasiga masofa');
            $table->boolean('geofence_ok')->nullable();

            $table->date('taken_date');
            $table->timestamp('captured_at')->comment('Server vaqti (backdating himoyasi)');
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('device_info')->nullable();

            $table->timestamps();

            $table->unique(['house_id', 'taken_date', 'type']);
            $table->index(['house_id', 'type']);
            $table->index('taken_date');
        });
    }

    public function down(): void
    {
        Schema::connection('mahalla')->dropIfExists('house_photos');
    }
};
