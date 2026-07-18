<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasColumn('users', 'position_id')) {
            return;
        }

        Schema::connection('hr')->table('users', function (Blueprint $table) {
            $table->foreignUuid('position_id')->nullable()->after('department_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('position_id');
        });
    }
};
