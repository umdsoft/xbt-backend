<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qator — sektordagi bitta o'rindiqlar chizig'i. Faqat seat_count saqlanadi;
 * o'rindiq (x,y) runtime formula bilan: x=(i-(n-1)/2)*seat_pitch, y=row_index*row_pitch.
 * `row_index` = TZ'dagi `index` (SQL'da xavfsiz nom). 2379 o'rniga 109 yozuv.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('seat_rows')) {
            return;
        }

        Schema::connection('hr')->create('seat_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('uuid')->unique();
            $table->foreignUuid('sector_id')->constrained('sectors')->cascadeOnDelete();
            $table->unsignedInteger('row_index');
            $table->unsignedInteger('seat_count');
            $table->unsignedInteger('seat_start')->default(1);
            $table->jsonb('seat_labels_json')->nullable();
            $table->timestamps();

            $table->unique(['sector_id', 'row_index']);
            $table->index('sector_id');
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('seat_rows');
    }
};
