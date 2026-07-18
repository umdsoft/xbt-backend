<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ташкилотлар — котибият мудири яратадиган ва топшириқ оладиган ташкилотлар.
 * Ички бошқарма-шажарадан (departments) ажратилган: динамик, тенант ичида.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasTable('organizations')) {
            return;
        }

        Schema::connection('hr')->create('organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Tenant — top-level hokimlik
            $table->foreignUuid('hokimlik_id')->constrained('departments')->restrictOnDelete();
            // Эгаси бўлган комплекс (department, type='kompleks')
            $table->foreignUuid('kompleks_id')->nullable()->constrained('departments')->nullOnDelete();

            $table->string('name_cyr', 255);
            $table->string('name_lat', 255)->nullable();
            $table->string('inn', 14)->nullable()->comment('СТИР/ИНН');
            $table->string('phone', 30)->nullable();
            $table->string('address', 500)->nullable();

            // Қайси котибият мудири яратган
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index('hokimlik_id');
            $table->index('kompleks_id');
            $table->index('created_by');
            $table->index(['hokimlik_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('organizations');
    }
};
