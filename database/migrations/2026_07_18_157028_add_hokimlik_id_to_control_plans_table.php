<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasColumn('control_plans', 'hokimlik_id')) {
            return;
        }

        Schema::connection('hr')->table('control_plans', function (Blueprint $table) {
            $table->foreignUuid('hokimlik_id')->nullable()->after('created_by')
                ->constrained('departments')->restrictOnDelete()
                ->comment('Tenant — top-level hokimlik ID');
            $table->index('hokimlik_id');
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->table('control_plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hokimlik_id');
        });
    }
};
