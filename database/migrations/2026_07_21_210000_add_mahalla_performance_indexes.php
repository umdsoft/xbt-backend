<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rahbariyat paneli issiq so'rovlari uchun indekslar (performance auditi P5).
 *
 * - zone_observations: "shu hafta o'zgargan" = WHERE is_change AND observed_at >= :hafta.
 *   Mavjud indeks (house_id, zone, observed_at) vaqt-oynasi skaniga yaramaydi.
 *   Partial indeks (faqat is_change=true qatorlar) kichik va aynan mos.
 * - buildings: agregatlar (mahalla_id/district_id, type='residential') bo'yicha guruhlanadi.
 *
 * CONCURRENTLY — prod'da (405k bino) yozuvlarni bloklamaslik uchun; shu sabab
 * tranzaksiyasiz (Laravel: $withinTransaction=false). IF NOT EXISTS — idempotent.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_zone_obs_change_time ON mahalla.zone_observations (observed_at) WHERE is_change');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_buildings_mahalla_type ON master.buildings (mahalla_id, type)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_buildings_district_type ON master.buildings (district_id, type)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS mahalla.idx_zone_obs_change_time');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS master.idx_buildings_mahalla_type');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS master.idx_buildings_district_type');
    }
};
