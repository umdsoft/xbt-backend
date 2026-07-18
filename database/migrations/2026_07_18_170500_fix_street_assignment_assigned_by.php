<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * street_assignments.assigned_by — biriktirgan shaxs (odatda ADMIN). Admin markaziy
 * auth.users'da, lekin mahalla.users profiliga EGA EMAS -> mahalla.users FK buziladi.
 * assigned_by faqat audit maydoni: FK'ni olib tashlaymiz (nullable uuid bo'lib qoladi).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE mahalla.street_assignments DROP CONSTRAINT IF EXISTS street_assignments_assigned_by_foreign');
    }

    public function down(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE mahalla.street_assignments
            ADD CONSTRAINT street_assignments_assigned_by_foreign
            FOREIGN KEY (assigned_by) REFERENCES mahalla.users(id) ON DELETE SET NULL');
    }
};
