<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Support;

use Illuminate\Support\Carbon;

/**
 * KPI/reyting davri yordamchisi (spec §7 — chorak bo'yicha baholanadi).
 *
 * Davr formati `YYYY-Qn` (masalan `2026-Q3`) — KPI entry/target va reyting shu
 * kalitni ishlatadi. Dashboard/oversight/svod davr berilmasa joriy chorakni oladi.
 */
final class Period
{
    /** Joriy chorak (masalan `2026-Q3`). */
    public static function current(): string
    {
        $now = Carbon::now();

        return $now->year.'-Q'.(int) ceil($now->month / 3);
    }

    /**
     * Davr (`YYYY-Qn`) -> chorak boshlanish va tugash sanalari [start, end].
     * Loyiha/entry davrini sana oralig'i bilan bog'lash uchun (masalan derive,
     * hisobot filtri). Noto'g'ri format -> InvalidArgumentException.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function range(string $period): array
    {
        if (! preg_match('/^(\d{4})-Q([1-4])$/', $period, $m)) {
            throw new \InvalidArgumentException("Noto'g'ri davr formati: {$period} (kutilgan: YYYY-Qn).");
        }

        $year = (int) $m[1];
        $startMonth = ((int) $m[2] - 1) * 3 + 1;

        $start = Carbon::create($year, $startMonth, 1)->startOfDay();
        $end = $start->copy()->addMonthsNoOverflow(3)->subDay()->endOfDay();

        return [$start, $end];
    }
}
