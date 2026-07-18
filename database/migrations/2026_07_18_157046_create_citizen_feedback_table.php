<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('citizen_feedback')) {
            return;
        }

        Schema::connection('hr')->create('citizen_feedback', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('appeal_id')->constrained('citizen_appeals')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating')->comment('1-5');
            $table->text('body')->nullable();
            $table->decimal('sentiment_score', 4, 3)->nullable()->comment('-1 to 1');
            $table->timestamp('submitted_at')->useCurrent();

            $table->index(['appeal_id', 'rating']);
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('citizen_feedback');
    }
};
