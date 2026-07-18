<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * house_photos.uploaded_by — yuklagan shaxs (markaziy auth.users). Admin (super-admin)
 * mahalla.users profiliga ega emas -> mahalla.users FK buziladi. Audit maydoni:
 * FK'ni olib tashlaymiz (nullable uuid qoladi). [[street_assignments.assigned_by bilan bir xil]]
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE mahalla.house_photos DROP CONSTRAINT IF EXISTS house_photos_uploaded_by_foreign');
    }

    public function down(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE mahalla.house_photos
            ADD CONSTRAINT house_photos_uploaded_by_foreign
            FOREIGN KEY (uploaded_by) REFERENCES mahalla.users(id) ON DELETE SET NULL');
    }
};
