<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' || Schema::connection('hr')->hasColumn('control_plan_items', 'link')) {
            return;
        }

        Schema::connection('hr')->table('control_plan_items', function (Blueprint $table) {
            // Topshiriq bo'yicha tashqi havola (ixtiyoriy)
            $table->string('link', 1000)->nullable()->after('funding_source');
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->table('control_plan_items', function (Blueprint $table) {
            $table->dropColumn('link');
        });
    }
};
