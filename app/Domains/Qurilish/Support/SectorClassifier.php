<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Support;

/**
 * Obyektni sohaga (`sectors.code`) tasniflaydi.
 *
 * Manbada soha ikki xil joyda: `ПҚ-393` da guruh-sarlavhada, qolgan
 * varaqlarda `C` ustunida — va ikkalasi ham normallashtirilmagan
 * (`Ички йўл` / `ички йўл` / `Ички йўллар`).
 *
 * Alohida muammo: 150 obyektda soha `Бошқа` deb yozilgan, lekin nom bo'yicha
 * tahlil ularning 92 tasi ichki yo'l, 29 tasi ichimlik suv, 15 tasi ko'cha
 * ekanini ko'rsatadi. Shuning uchun `Бошқа`/bo'sh holatda nom bo'yicha
 * kalit so'zli tasniflagich ishlaydi — aks holda dashboard'ning soha kesimi
 * 150 ta ma'nosiz «Boshqa» ko'rsatardi.
 */
class SectorClassifier
{
    /**
     * Normallashtirilgan yorliq -> soha kodi (aniq moslik).
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'umumtalimmaktablari' => 'umumtalim_maktab',
        'umumtalimmaktabi' => 'umumtalim_maktab',
        'maktab' => 'umumtalim_maktab',
        'maktablar' => 'umumtalim_maktab',
        'mtt' => 'mtt',
        'maktabgachatalimtashkilotlari' => 'mtt',
        'maktabgachatalimtashkiloti' => 'mtt',
        'ijodvaixtisoslashtirilganmaktablar' => 'ijod_maktab',
        'sogliqnisaqlashvatibbiyijtimoiymuassasalar' => 'sogliqni_saqlash',
        'tibbiyot' => 'sogliqni_saqlash',
        'sportnirivojlantirishobyektlari' => 'sport',
        'jismoniytarbiya' => 'sport',
        'madaniyatvasanat' => 'madaniyat',
        'turizminfratuzilmasiobyektlari' => 'turizm',
        'madaniymeros' => 'madaniy_meros',
        'oliytalimmuassasalari' => 'oliy_talim',
        'oliytalim' => 'oliy_talim',
        'suvtaminotivakanalizatsiya' => 'suv_kanalizatsiya',
        'ichimliksuv' => 'suv_kanalizatsiya',
        'issiqliktaminoti' => 'issiqlik',
        'avtomobilyollarivakopriklar' => 'avtoyol',
        'ichkiyol' => 'ichki_yol',
        'ichkiyollar' => 'ichki_yol',
        'irrigatsiyatarmoqlarivainshootlari' => 'irrigatsiya',
        'irrigatsiya' => 'irrigatsiya',
        'melioratsiyatarmoqlarivainshootlari' => 'melioratsiya',
        'ormonxojaligiobyektlari' => 'ormon',
        'mudofaavahuquqnimuhofazaqiluvchiorganlar' => 'mudofaa_huquq',
        'elektrtaminotiobyektlari' => 'elektr',
        'elektrtaminoti' => 'elektr',
        'elektr' => 'elektr',
        'maxsusiqtisodiyzonalar' => 'maxsus_zona',
    ];

    /**
     * Obyekt nomidagi kalit so'z -> soha kodi. TARTIB MUHIM:
     * `мактабгача` `мактаб` dan OLDIN turishi shart, aks holda barcha
     * bog'chalar umumta'lim maktabiga tushib qolardi.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const KEYWORDS = [
        ['мактабгача', 'mtt'],
        ['боғча', 'mtt'],
        ['мтт', 'mtt'],
        ['мактаб', 'umumtalim_maktab'],
        ['шифохона', 'sogliqni_saqlash'],
        ['поликлин', 'sogliqni_saqlash'],
        ['фап', 'sogliqni_saqlash'],
        ['касалхона', 'sogliqni_saqlash'],
        ['тиббиёт', 'sogliqni_saqlash'],
        ['ичимлик сув', 'suv_kanalizatsiya'],
        ['канализ', 'suv_kanalizatsiya'],
        ['сув таъминот', 'suv_kanalizatsiya'],
        ['сув қувур', 'suv_kanalizatsiya'],
        ['йўл', 'ichki_yol'],
        ['кўча', 'ichki_yol'],
        ['куча', 'ichki_yol'],
        ['кўприк', 'avtoyol'],
        ['спорт', 'sport'],
        ['стадион', 'sport'],
        ['ирригац', 'irrigatsiya'],
        ['суғориш', 'irrigatsiya'],
        ['коллектор', 'melioratsiya'],
        ['мелиорац', 'melioratsiya'],
        ['электр', 'elektr'],
        ['иссиқлик', 'issiqlik'],
        ['маданият', 'madaniyat'],
        ['музей', 'madaniyat'],
        ['ўрмон', 'ormon'],
    ];

    /** «Aniqlanmagan» ma'nosini bildiruvchi yorliqlar. */
    private const VAGUE = ['boshqa', 'boshqaobyektlar', ''];

    public function classify(?string $cValue, ?string $groupLabel, string $name): string
    {
        foreach ([$cValue, $groupLabel] as $label) {
            $key = $this->normalize($label);
            if (in_array($key, self::VAGUE, true)) {
                continue;
            }
            if (isset(self::LABELS[$key])) {
                return self::LABELS[$key];
            }
            // Uzun yorliqlar («"Янги Ўзбекистон" ва уй-жой массивларида электр
            // таъминоти тизимлари») — qisman moslik bo'yicha.
            foreach (self::LABELS as $known => $code) {
                if (strlen($known) >= 8 && str_contains($key, $known)) {
                    return $code;
                }
            }
        }

        return $this->byKeywords($name);
    }

    /** Obyekt nomidan soha topadi; topilmasa `boshqa`. */
    public function byKeywords(string $name): string
    {
        $n = mb_strtolower($name);

        foreach (self::KEYWORDS as [$needle, $code]) {
            if (str_contains($n, $needle)) {
                return $code;
            }
        }

        return 'boshqa';
    }

    private function normalize(?string $label): string
    {
        if ($label === null) {
            return '';
        }

        $s = (string) Translit::toLatin(trim($label));
        $s = mb_strtolower($s);

        return preg_replace('/[^a-z0-9]/', '', $s) ?? '';
    }
}
