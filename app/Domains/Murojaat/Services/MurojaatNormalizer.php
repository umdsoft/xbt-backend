<?php

declare(strict_types=1);

namespace App\Domains\Murojaat\Services;

use Illuminate\Support\Carbon;

/**
 * Murojaat qatorini normallashtirish — HTMLdagi `normalizeRow` mantiqining server
 * (authoritative) ko'chirmasi. Client faqat 44 ustunni maydonlarga bog'lab xom
 * qiymat yuboradi; barcha biznes-qoidalar SHU YERDA:
 *   - holat 5-standart (hal|kechikkan|jarayon|rad|yonaltirildi)
 *   - kechikkan qoidasi (javob yo'q & 15 kundan oshgan | kechikish>0 | holat=kechikkan)
 *   - sayyor ajratish, manba (pvq/xq), statistika toifasi, sana parse
 */
class MurojaatNormalizer
{
    /** @param  array<string, mixed>  $r  xom mapping qilingan qator */
    public function normalize(array $r): array
    {
        $s = fn (string $k) => trim((string) ($r[$k] ?? ''));
        $i = fn (string $k) => (int) ($r[$k] ?? 0);

        $muddat = $i('muddat_kun') ?: 30;
        $korib = $i('korib_chiqish_kun');
        $kechik30 = $i('kechikish_30dan');

        // Sana parse + kun_otgan (kelgan_sanadan bugungача, absolyut kunlar).
        [$d, $yil, $oy] = $this->parseDate($s('kelgan_sana'));
        $kunOtgan = $d === null ? 0 : (int) abs(Carbon::now($this->tz())->diffInDays($d));

        // Holat normalizatsiyasi (kod).
        $norm = $this->normHolat($s('natija_holat'), $s('natija_toifa'));

        $javobTasdiq = $s('javob_tasdiqlangan');
        if ($javobTasdiq !== '' && $javobTasdiq !== '0' && ! in_array($norm, ['rad', 'yonaltirildi'], true)) {
            $norm = 'hal';
        }
        // Javob kiritilgan, lekin hali jarayon: kechikish bo'lsa kechikkan, aks holda hal.
        if ($s('javob_kiritilgan') !== '' && $norm === 'jarayon') {
            $norm = ($kechik30 > 0 || $korib > $muddat) ? 'kechikkan' : 'hal';
        }
        if ($norm === '' && $korib > $muddat) {
            $norm = 'kechikkan';
        }
        if ($norm === '') {
            $norm = 'jarayon';
        }

        // Kechikkan qoidasi.
        $javobBerilgan = in_array($norm, ['hal', 'rad', 'yonaltirildi'], true);
        $overdueDays = (int) config('murojaat.overdue_days', 15);
        $isKechikkan = (! $javobBerilgan && $kunOtgan > $overdueDays)
            || $kechik30 > 0
            || $norm === 'kechikkan';
        if ($isKechikkan && ! $javobBerilgan) {
            $norm = 'kechikkan';
        }

        // Takroriylik / jamoaviy.
        $takror = $s('takroriylik');
        if ($this->has(mb_strtolower($takror), ['takror', 'такрор'])) {
            $takror = 'Takroriy';
        }
        $jamRaw = mb_strtolower($s('jamoaviy'));
        $jamoaviy = in_array($jamRaw, ['ha', 'ха', 'ҳа', 'yes', '1'], true) ? 'Ha' : "Yo'q";

        $sayyorTashkilot = $s('sayyor_tashkilot');

        return array_merge($r, [
            'muddat_kun' => $muddat,
            'korib_chiqish_kun' => $korib,
            'kechikish_30dan' => $kechik30,
            'kechikib_yopilgan' => $i('kechikib_yopilgan'),
            'kechikib_30dan' => $i('kechikib_30dan'),
            'takroriylik' => $takror,
            'jamoaviy' => $jamoaviy,
            // hisoblangan
            'natija_holat_norm' => $norm,
            'is_kechikkan' => $isKechikkan,
            'is_sayyor' => $sayyorTashkilot !== '',
            'manba_type' => $this->manbaType($s('qaerdan')),
            'kun_otgan' => $kunOtgan,
            'kelgan_sana_d' => $d?->toDateString(),
            'kelgan_yil' => $yil,
            'kelgan_oy' => $oy,
            'stat_holat' => $this->statHolat($s('natija_holat'), $s('natija_toifa'), $norm),
        ]);
    }

    /** Holat -> kod (hal|kechikkan|jarayon|rad|yonaltirildi|''). */
    private function normHolat(string $holat, string $toifa): string
    {
        $c = mb_strtolower($holat.' '.$toifa);
        if ($c === ' ' || trim($c) === '') {
            return '';
        }
        // Tartib muhim: rad/yo'nalt oldin (ular ham "hal" so'zini o'z ichiga olmaydi).
        if ($this->has($c, ['рад', 'rad et', 'асосиз', 'асоссиз'])) {
            return 'rad';
        }
        if ($this->has($c, ['йўнал', "yo'nalt", 'бошқа ташкилот'])) {
            return 'yonaltirildi';
        }
        if ($this->has($c, ['муддатдан ошиб', 'кечик', 'kechik', 'муддати ўтган', 'муддатдан ўт'])) {
            return 'kechikkan';
        }
        if ($this->has($c, ['ижобий', 'ijobiy', 'тасдиқ', 'ҳал эт', 'hal et', 'жавоб берилди', 'муддатда ҳал'])) {
            return 'hal';
        }
        if ($this->has($c, ['ижрода', 'жараён', 'jarayon', 'кўриб чиқил', 'ўрганил', 'кўрилмоқда'])) {
            return 'jarayon';
        }

        return '';
    }

    /** Statistika toifasi (getStatHolat). */
    private function statHolat(string $holat, string $toifa, string $norm): string
    {
        $c = mb_strtolower($holat.' '.$toifa);
        if ($this->has($c, ['ижобий', 'ijobiy', 'жавоб тасдиқ', 'муддатда ҳал', 'муддатда ҳал эт']) || $norm === 'hal') {
            return 'ijobiy';
        }
        if ($this->has($c, ['ҳуқуқий', 'huquqiy', 'маълумот бер'])) {
            return 'huquqiy';
        }
        if ($this->has($c, ['узоқ', 'uzoq', 'назоратга'])) {
            return 'uzoq';
        }
        if ($this->has($c, ['тушунтириш', 'tushuntirish'])) {
            return 'tushuntirish';
        }
        if ($this->has($c, ['рад', 'rad et'])) {
            return 'rad';
        }
        if ($this->has($c, ['кўрмасдан', 'kormasdan', 'қолдирилган'])) {
            return 'kormasdan';
        }
        if ($this->has($c, ['тугатилган', 'tugatilgan'])) {
            return 'tugatilgan';
        }
        if ($this->has($c, ['маълумот учун', 'malumot uchun'])) {
            return 'malumot';
        }

        return 'boshqa';
    }

    private function manbaType(string $qaerdan): string
    {
        $q = mb_strtolower($qaerdan);
        if ($this->has($q, ['виртуал', 'virtual', 'pvq', 'презид', 'prezident'])) {
            return 'pvq';
        }
        if ($this->has($q, ['халқ', 'xalq', 'xq', 'қабулхона', 'qabulxona'])) {
            return 'xq';
        }

        return 'boshqa';
    }

    /** @return array{0: ?Carbon, 1: ?int, 2: ?int} */
    private function parseDate(string $s): array
    {
        $s = trim($s);
        if ($s === '') {
            return [null, null, null];
        }
        $m = [];
        // DD.MM.YYYY | DD/MM/YYYY
        if (preg_match('#^(\d{1,2})[./](\d{1,2})[./](\d{4})#', $s, $m)) {
            return $this->mk((int) $m[3], (int) $m[2], (int) $m[1]);
        }
        // YYYY-MM-DD
        if (preg_match('#^(\d{4})-(\d{1,2})-(\d{1,2})#', $s, $m)) {
            return $this->mk((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        return [null, null, null];
    }

    /** @return array{0: ?Carbon, 1: ?int, 2: ?int} */
    private function mk(int $y, int $mo, int $d): array
    {
        if ($mo < 1 || $mo > 12 || $d < 1 || $d > 31 || $y < 2000 || $y > 2100) {
            return [null, null, null];
        }
        try {
            $c = Carbon::create($y, $mo, $d, 0, 0, 0, $this->tz());

            return [$c, $y, $mo];
        } catch (\Throwable) {
            return [null, null, null];
        }
    }

    /** @param  array<int, string>  $needles */
    private function has(string $haystack, array $needles): bool
    {
        foreach ($needles as $n) {
            if ($n !== '' && str_contains($haystack, $n)) {
                return true;
            }
        }

        return false;
    }

    private function tz(): string
    {
        return (string) config('murojaat.timezone', 'Asia/Tashkent');
    }
}
