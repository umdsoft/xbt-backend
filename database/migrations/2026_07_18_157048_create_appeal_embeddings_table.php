<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Appeal embeddings ma'lumotlari Qdrant'da saqlanadi (vector DB).
 * Bu jadval — Qdrant point ID va metadata orasidagi bog'lanish.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('appeal_embeddings')) {
            return;
        }

        Schema::connection('hr')->create('appeal_embeddings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('appeal_id')->constrained('citizen_appeals')->cascadeOnDelete();
            $table->string('model_name', 100)->default('multilingual-e5-large');
            $table->string('qdrant_point_id', 100)->nullable()->comment('UUID in Qdrant');
            $table->string('collection_name', 100)->default('citizen_appeals');
            $table->unsignedSmallInteger('dimensions')->default(1024);
            $table->timestamp('created_at')->useCurrent();

            $table->index('appeal_id');
            $table->unique(['appeal_id', 'model_name']);
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('appeal_embeddings');
    }
};
