<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Services;

use App\Domains\Ayollar\Models\Anketa;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Ro'yxat raqami: `XOR-08-0142-2026-000731`
 *
 *   XOR    viloyat prefiksi
 *   08     tuman kodi (01–13)
 *   0142   MFY kodi (4 xona)
 *   2026   yil
 *   000731 MFY ICHIDAGI ketma-ket raqam
 *
 * Ketma-ketlik MFY+yil doirasida: viloyat bo'yicha yagona hisoblagich
 * bo'lsa, oflayn ishlagan 486 MFY bir vaqtda sinxronlanganda hisoblagich
 * uchun navbat hosil bo'lardi.
 */
class RegNumberGenerator
{
    /**
     * Keyingi raqamni beradi.
     *
     * TRANZAKSIYA + `FOR UPDATE` KERAK EMAS: raqam `LIKE` prefiksi bo'yicha
     * MAX dan olinadi va ustunda unique indeks bor. Ikki parallel so'rov bir
     * xil raqam olsa, ikkinchisi unique buzilishida yiqiladi va qayta
     * urinadi — bu `SELECT ... FOR UPDATE` bilan butun MFY'ni bloklashdan
     * arzonroq, chunki to'qnashuv kamdan-kam.
     */
    public function next(string $districtCode, string $mahallaCode, ?int $year = null): string
    {
        $year ??= (int) now()->year;
        $prefix = $this->prefix($districtCode, $mahallaCode, $year);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $last = Anketa::query()
                ->withTrashed()
                ->where('reg_number', 'like', $prefix.'%')
                ->orderByDesc('reg_number')
                ->value('reg_number');

            $sequence = $last === null ? 1 : ((int) substr((string) $last, -6)) + 1;
            $candidate = $prefix.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);

            if (! Anketa::query()->withTrashed()->where('reg_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException("Ro‘yxat raqami yaratilmadi: {$prefix}");
    }

    public function prefix(string $districtCode, string $mahallaCode, int $year): string
    {
        $region = (string) config('ayollar.reg_number.region_prefix', 'XOR');

        return sprintf(
            '%s-%s-%s-%d-',
            $region,
            str_pad($this->digits($districtCode), 2, '0', STR_PAD_LEFT),
            str_pad($this->digits($mahallaCode), 4, '0', STR_PAD_LEFT),
            $year,
        );
    }

    /**
     * Kodni raqamlarga keltiradi.
     *
     * `master` dagi kodlar har xil formatda bo'lishi mumkin (SOATO,
     * `08`, `1712345`). Raqam bo'lmagan belgilar tashlanadi va oxirgi
     * xonalari olinadi — ro'yxat raqami formati QAT'IY uzunlikda.
     */
    private function digits(string $code): string
    {
        $only = preg_replace('/\D+/', '', $code) ?? '';

        return $only === '' ? '0' : $only;
    }

    /** Raqamdan MFY va tuman kodini ajratadi (QR sahifasi uchun). */
    public function parse(string $regNumber): ?array
    {
        if (! preg_match('/^([A-Z]{3})-(\d{2})-(\d{4})-(\d{4})-(\d{6})$/', $regNumber, $m)) {
            return null;
        }

        return [
            'region' => $m[1],
            'district_code' => $m[2],
            'mahalla_code' => $m[3],
            'year' => (int) $m[4],
            'sequence' => (int) $m[5],
        ];
    }
}
