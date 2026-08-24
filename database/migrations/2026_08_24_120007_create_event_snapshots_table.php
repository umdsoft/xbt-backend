<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pechat snapshot — chop etilgan sxema vektor SVG'si + PDF yo'li (queue job
 * natijasi). Tarixiy nusxa (kim, qachon chop etdi).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('event_snapshots')) {
            return;
        }

        Schema::connection('hr')->create('event_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('uuid')->unique();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->text('svg_content')->nullable();
            $table->string('pdf_path')->nullable();
            $table->string('sheet_format', 32)->nullable();
            $table->foreignUuid('printed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('printed_at')->nullable();
            $table->timestamps();

            $table->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('event_snapshots');
    }
};
