<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Вафот этган қариндош учун former_position (аслий, форматланмаган) сақланади.
 * Илгари фақат форматланган workplace_and_position сақланарди — таҳрирлашда
 * аслий қиймат йўқолиб қоларди (audit M5).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasColumn('employee_relatives', 'former_position')) {
            return;
        }

        Schema::connection('hr')->table('employee_relatives', function (Blueprint $table) {
            $table->text('former_position')->nullable()->after('workplace_and_position')
                ->comment('Вафот этган қариндошнинг аслий лавозими (форматлашдан олдин)');
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->table('employee_relatives', function (Blueprint $table) {
            $table->dropColumn('former_position');
        });
    }
};
