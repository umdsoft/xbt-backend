<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasColumn('positions', 'role_name')) {
            return;
        }

        Schema::connection('hr')->table('positions', function (Blueprint $table) {
            $table->string('role_name', 50)->nullable()->after('name_lat')
                ->comment('Ушбу лавозимга мос тизим роли');
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->table('positions', function (Blueprint $table) {
            $table->dropColumn('role_name');
        });
    }
};
