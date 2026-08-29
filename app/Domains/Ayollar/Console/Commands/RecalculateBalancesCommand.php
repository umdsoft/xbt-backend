<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Console\Commands;

use App\Domains\Ayollar\Services\BalanceRefresher;
use Illuminate\Console\Command;

/**
 * TO'LIQ qayta hisoblash — kechasi.
 *
 * Inkremental yangilash anketa saqlanganda ishlaydi va kunlik ish uchun
 * yetarli. Bu buyruq esa XAVFSIZLIK TO'RI: agar biror joyda yangilash
 * o'tkazib yuborilgan bo'lsa (xato, uzilish, to'g'ridan-to'g'ri SQL),
 * kechasi hammasi tiklanadi.
 *
 * Yopilgan va tasdiqlangan balanslar TEGILMAYDI — `BalanceCalculator`
 * ularni o'zi chetlab o'tadi.
 */
class RecalculateBalancesCommand extends Command
{
    protected $signature = 'ayollar:recalculate
        {--period= : YYYY-MM (sukut: joriy oy)}';

    protected $description = 'Ayollar Balansi: barcha MFY/tuman/viloyat balanslarini qayta hisoblaydi.';

    public function handle(BalanceRefresher $refresher): int
    {
        [$year, $month] = $this->period();

        $this->info("Davr: {$year}-{$month}");

        $total = count($refresher->districtIds());
        $bar = $this->output->createProgressBar(max(1, $total));
        $bar->start();

        $result = $refresher->full($year, $month);

        $bar->finish();
        $this->newLine(2);

        $this->info("Yangilandi: {$result['mahallas']} MFY, {$result['districts']} tuman, 1 viloyat.");

        return self::SUCCESS;
    }

    /** @return array{0: int, 1: int} */
    private function period(): array
    {
        $raw = (string) $this->option('period');

        if (preg_match('/^(\d{4})-(\d{1,2})$/', $raw, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }

        return [(int) now()->year, (int) now()->month];
    }
}
