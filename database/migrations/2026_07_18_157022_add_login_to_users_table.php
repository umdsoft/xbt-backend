<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasColumn('users', 'login')) {
            return;
        }

        Schema::connection('hr')->table('users', function (Blueprint $table) {
            $table->string('login', 100)->unique()->after('name')->comment('Логин (телефон ёки ID)');
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->table('users', function (Blueprint $table) {
            $table->dropColumn('login');
            $table->string('email')->nullable(false)->change();
        });
    }
};
