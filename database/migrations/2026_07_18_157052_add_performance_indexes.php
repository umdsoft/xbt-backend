<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ҳақиқий query'лар учун индекслар (audit M4):
 *  - employees.nationality / education_level — exact-match фильтрлар
 *  - employees (hokimlik_id, last_name_cyr) — tenant-scoped рўйхат + ORDER BY
 *  - activity_log.created_at — Activity::latest() (append-heavy)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        try {
            Schema::connection('hr')->table('employees', function (Blueprint $table) {
                $table->index('nationality');
                $table->index('education_level');
                $table->index(['hokimlik_id', 'last_name_cyr']);
            });

            Schema::connection('hr')->table('activity_log', function (Blueprint $table) {
                $table->index('created_at');
            });
        } catch (\Throwable $e) {
            // Индекслар аллақачон мавжуд — хавфсиз no-op.
        }
    }

    public function down(): void
    {
        Schema::connection('hr')->table('employees', function (Blueprint $table) {
            $table->dropIndex(['nationality']);
            $table->dropIndex(['education_level']);
            $table->dropIndex(['hokimlik_id', 'last_name_cyr']);
        });

        Schema::connection('hr')->table('activity_log', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
