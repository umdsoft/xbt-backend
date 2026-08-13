<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Support;

/**
 * «Амалга ошириш муддати» ustunini sanaga aylantiradi.
 *
 * Manbada 4 format aralash (611 obyekt):
 *   `15.08.26 й`     -> 2026-08-15   (~380 ta — eng ko'p)
 *   `2026 йил`       -> 2026-12-31   (92 ta)
 *   `2026 й`         -> 2026-12-31   (48 ta)
 *   `2025-2026 йй`   -> 2026-12-31   (oxirgi yil; 10 ta)
 *   `2026-2027 йй`   -> 2027-12-31   (4 ta)
 *
 * Xom qiymat `objects.deadline_raw` da saqlanadi — parser noto'g'ri ishlasa
 * ham asl ma'lumot yo'qolmaydi.
 */
class DeadlineParser
{
    /** @return array{date: ?string, year: ?int} */
    public function parse(?string $raw): array
    {
        $none = ['date' => null, 'year' => null];

        if ($raw === null) {
            return $none;
        }

        $s = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');
        if ($s === '') {
            return $none;
        }

        // 1) DD.MM.YY (yoki DD.MM.YYYY)
        if (preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{2,4})/', $s, $m)) {
            $day = (int) $m[1];
            $month = (int) $m[2];
            $year = (int) $m[3];
            $year = $year < 100 ? 2000 + $year : $year;

            if (checkdate($month, $day, $year)) {
                return [
                    'date' => sprintf('%04d-%02d-%02d', $year, $month, $day),
                    'year' => $year,
                ];
            }

            return $none;
        }

        // 2) YYYY-YYYY (oraliq) — oxirgi yil hal qiluvchi.
        if (preg_match('/(\d{4})\s*[-–—]\s*(\d{4})/u', $s, $m)) {
            $year = (int) $m[2];

            return ['date' => $year.'-12-31', 'year' => $year];
        }

        // 3) Yagona yil.
        if (preg_match('/(20\d{2})/', $s, $m)) {
            $year = (int) $m[1];

            return ['date' => $year.'-12-31', 'year' => $year];
        }

        return $none;
    }
}
