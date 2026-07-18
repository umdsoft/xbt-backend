<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('ai_drafts')) {
            return;
        }

        Schema::connection('hr')->create('ai_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('appeal_id')->constrained('citizen_appeals')->cascadeOnDelete();
            $table->text('draft_text');
            $table->string('model_name', 100);
            $table->json('based_on_cases')->nullable()->comment('RAG context appeals');
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->boolean('was_modified')->default(false);
            $table->timestamps();

            $table->index('appeal_id');
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('ai_drafts');
    }
};
