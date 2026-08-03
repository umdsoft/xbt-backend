<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Database\Seeders;

use App\Domains\Advisor\Support\KpiCatalog;
use Illuminate\Database\Seeder;

/**
 * KPI KATALOGI seeder (spec §7, yo'riqnoma IX bo'limi) — 13 viloyat + 11 tuman
 * ko'rsatkich. Yagona manba KpiCatalog::ITEMS; migratsiya ham shu manbani seed
 * qiladi. `code` bo'yicha idempotent upsert (db:seed --force takrorlansa dubl
 * yaratmaydi). Faqat PostgreSQL.
 */
class KpiCatalogSeeder extends Seeder
{
    public function run(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $count = KpiCatalog::seed();

        $this->command?->info("KPI katalogi seed qilindi: {$count} ko'rsatkich (13 viloyat + 11 tuman).");
    }
}
