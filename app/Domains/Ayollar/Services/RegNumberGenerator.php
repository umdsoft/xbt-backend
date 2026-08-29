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
            $this->fixedDigits($districtCode, 2),
            $this->fixedDigits($mahallaCode, 4),
            $year,
        );
    }

    /**
     * Kodni QAT'IY uzunlikdagi raqamga keltiradi.
     *
     * OXIRGI $length xonasi olinadi, keyin kerak bo'lsa nol bilan
     * to'ldiriladi.
     *
     * NEGA KESISH KERAK: `master` dagi kodlar SOATO formatida —
     * tuman `1733204` (7 xona), MFY `1733204008` (10 xona). Faqat
     * `str_pad()` ishlatilganda ular O'ZGARISHSIZ o'tib ketardi va
     * ro'yxat raqami `XOR-1733204-1733204008-2026-000005` bo'lardi:
     * format buzilgan, qo'lda kiritish imkonsiz, QR ostidagi matn
     * o'qib bo'lmaydigan uzunlikda. Aynan shunday bo'ldi.
     *
     * MFY uchun oxirgi 4 xona: bir tuman ichidagi SOATO kodlari
     * birinchi 7 xonada bir xil, ya'ni oxirgi xonalar farqlaydi.
     */
    private function fixedDigits(string $code, int $length): string
    {
        $only = preg_replace('/\D+/', '', $code) ?? '';

        if ($only === '') {
            $only = '0';
        }

        return str_pad(substr($only, -$length), $length, '0', STR_PAD_LEFT);
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
