<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mahalla operatsion foydalanuvchi profiliga LAVOZIM (mahalla-5ligi) ustuni.
 * Barcha operatsion user bir xil huquqli (deputat) qoladi — lavozim tavsifiy yorliq.
 * Qiymatlar: MahallaAccess::POSITIONS kodlari (rais, kotib, ...).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE mahalla.users ADD COLUMN IF NOT EXISTS position varchar(40)");
    }

    public function down(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE mahalla.users DROP COLUMN IF EXISTS position");
    }
};
