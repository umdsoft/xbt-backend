<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * master.buildings -> master.streets bog'lash (worklist: deputat ko'chalaridagi binolar).
 * Ma'lumot (street_id qiymatlari) import_cadastre.sql da to'ldiriladi (schema bu yerda).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE master.buildings ADD COLUMN IF NOT EXISTS street_id uuid');
        DB::statement(<<<'SQL'
            DO $$ BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'buildings_street_id_fkey') THEN
                    ALTER TABLE master.buildings
                        ADD CONSTRAINT buildings_street_id_fkey
                        FOREIGN KEY (street_id) REFERENCES master.streets(id) ON DELETE SET NULL;
                END IF;
            END $$;
        SQL);
        DB::statement('CREATE INDEX IF NOT EXISTS buildings_street_idx ON master.buildings (street_id)');
    }

    public function down(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE master.buildings DROP CONSTRAINT IF EXISTS buildings_street_id_fkey');
        DB::statement('ALTER TABLE master.buildings DROP COLUMN IF EXISTS street_id');
    }
};
