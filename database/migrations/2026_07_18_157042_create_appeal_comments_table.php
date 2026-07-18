<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('appeal_comments')) {
            return;
        }

        Schema::connection('hr')->create('appeal_comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('appeal_id')->constrained('citizen_appeals')->cascadeOnDelete();
            $table->foreignUuid('author_id')->constrained('users');
            $table->text('body');
            $table->boolean('is_internal')->default(true)
                ->comment('false = fuqaroga ko\'rinadi');
            $table->timestamps();

            $table->index('appeal_id');
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('appeal_comments');
    }
};
