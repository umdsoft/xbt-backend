<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('yoshlar_yetakchilari')) {
            return;
        }

        Schema::connection('hr')->create('yoshlar_yetakchilari', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('hokimlik_id')->constrained('departments')->restrictOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('mahalla_id')->nullable()->constrained()->nullOnDelete();
            $table->string('full_name_cyr', 255);
            $table->string('phone', 20)->nullable();
            $table->date('birth_date')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('hokimlik_id');
            $table->index(['hokimlik_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('yoshlar_yetakchilari');
    }
};
