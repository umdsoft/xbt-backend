<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasColumn('departments', 'type')) {
            return;
        }

        Schema::connection('hr')->table('departments', function (Blueprint $table) {
            $table->string('type', 20)->nullable()->after('code')
                ->comment('viloyat, shahar, tuman, bolim');
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->table('departments', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
