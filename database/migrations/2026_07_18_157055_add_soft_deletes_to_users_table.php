<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Users'ga soft delete — audit-огир тизимда фойдаланувчи ҳеч қачон ҳақиқий
 * ўчирилмайди. Аввал `$user->delete()` HARD delete эди ва фойдаланувчи назорат
 * режа/ҳужжат/изоҳ яратган бўлса FK restrict → 500. Soft delete UPDATE бўлгани
 * учун FK бузилмайди; ўчирилган (trashed) фойдаланувчи логин қила олмайди
 * (SoftDeletes global scope auth queries'ни ҳам фильтрлайди).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasColumn('users', 'deleted_at')) {
            return;
        }

        Schema::connection('hr')->table('users', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
