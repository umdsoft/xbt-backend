<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Har upload DB'ga yozib boriladi (progressiv kuzatuv tarixi) — shuning uchun bir
 * kunda bir zona bo'yicha bir nechta rasm/kuzatuvga ruxsat beramiz. AI har safar
 * DB'dagi OLDINGI kuzatuv bilan solishtiradi.
 * Eski (house_id, zone, taken_date, type) UNIKAL cheklovini oddiy indeksга almashtiramiz.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS mahalla.house_photos_zone_day_uidx');
        // "Oldingi kuzatuv" so'rovi uchun tez indeks (house, zona, vaqt).
        DB::statement('CREATE INDEX IF NOT EXISTS house_photos_zone_captured_idx ON mahalla.house_photos (house_id, zone, captured_at)');
    }

    public function down(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS mahalla.house_photos_zone_captured_idx');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS house_photos_zone_day_uidx ON mahalla.house_photos (house_id, zone, taken_date, type)');
    }
};
