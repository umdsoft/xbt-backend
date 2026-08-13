<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Support;

use Illuminate\Support\Facades\DB;

/**
 * Obyektni `master.districts` bilan bog'laydi.
 *
 * ASOSIY KASHFIYOT: manbadagi «Объект ID рақами» ichida SOATO kodi bor.
 *
 *   2601334060102001
 *     ^^ ^^^^^
 *     ││ └── [4:9] = '33406' -> '17' + '33406' = 1733406 (Xiva shahri)
 *     └───── ro'yxatga olish yili
 *
 * 611 obyektdan 566 tasi shu yo'l bilan 100% aniqlanadi. Shu bois fuzzy
 * matcher (MahallaMatcher kabi) KERAK EMAS: ID hokim manba, nom — zaxira.
 * Manbada 12 ta obyektda hudud ustuni xato yozilgan, ID esa to'g'ri.
 */
class SoatoResolver
{
    /** @var array<string, string>|null soato_code -> district uuid */
    private ?array $bySoato = null;

    /** @var array<string, string>|null normallashtirilgan nom -> district uuid */
    private ?array $byName = null;

    public function districtIdFor(?string $externalId, ?string $fallbackName): ?string
    {
        $this->load();

        $digits = preg_replace('/\D/', '', (string) $externalId);
        if (strlen((string) $digits) >= 10) {
            $soato = '17'.substr((string) $digits, 4, 5);
            if (isset($this->bySoato[$soato])) {
                return $this->bySoato[$soato];
            }
            // Maxsus kodlar (992000 = tumanlararo) — nom bo'yicha ham qidirmaymiz,
            // chunki bunday obyekt haqiqatan bitta tumanga tegishli emas.
            if (str_starts_with((string) $digits, '2601992') || substr((string) $digits, 4, 5) === '99200') {
                return null;
            }
        }

        return $this->matchByName($fallbackName);
    }

    /** Nom bo'yicha moslash — ID yo'q (33 DXSh) yoki maxsus kod bo'lganda. */
    public function matchByName(?string $name): ?string
    {
        $this->load();

        $key = $this->normalizeName($name);
        if ($key === '') {
            return null;
        }

        if (isset($this->byName[$key])) {
            return $this->byName[$key];
        }

        // Prefiks bo'yicha (masalan 'xiva' -> 'xivat'/'xivash' ikkilanadi, shuning
        // uchun faqat yagona moslik bo'lsa qabul qilamiz).
        $hits = [];
        foreach ($this->byName as $candidate => $id) {
            if (str_starts_with($candidate, $key) || str_starts_with($key, $candidate)) {
                $hits[$id] = true;
            }
        }

        return count($hits) === 1 ? (string) array_key_first($hits) : null;
    }

    /**
     * Nomni solishtirish uchun kalitga aylantiradi.
     *
     * «Хива.ш» / «Хива шаҳар» / «Xiva shahri» -> 'xivash'
     * «Хива.т» / «Хива тумани»                -> 'xivat'
     * «Тупроққалъа» / «Тупроққальа»            -> 'tuproqqala'
     */
    private function normalizeName(?string $name): string
    {
        if ($name === null || trim($name) === '') {
            return '';
        }

        $s = mb_strtolower(trim($name));

        // Tuman/shahar belgisini bitta harfga siqamiz — u ajratuvchi sifatida saqlanadi.
        $suffix = '';
        if (preg_match('/(\.ш|\sш|шаҳ|шаh|shah|шахар)/u', $s)) {
            $suffix = 'sh';
        } elseif (preg_match('/(\.т|\sт|туман|tuman)/u', $s)) {
            $suffix = 't';
        }

        $s = preg_replace('/(тумани|туман|шаҳри|шаҳар|шахар|tumani|tuman|shahri|shahar|\.ш|\.т)/u', '', $s);
        $s = (string) Translit::toLatin((string) $s);
        $s = mb_strtolower($s);
        // Diakritik/apostrof/`ъ`/probel — hammasi tashlanadi (Тупроққалъа = Тупроққальа).
        $s = preg_replace('/[^a-z0-9]/', '', $s);

        return $s.$suffix;
    }

    private function load(): void
    {
        if ($this->bySoato !== null) {
            return;
        }

        $this->bySoato = [];
        $this->byName = [];

        $rows = DB::connection('master')->table('districts')
            ->get(['id', 'soato_code', 'name_cyr', 'name_lat']);

        foreach ($rows as $row) {
            $id = (string) $row->id;
            $this->bySoato[(string) $row->soato_code] = $id;
            foreach ([$row->name_cyr, $row->name_lat] as $name) {
                $key = $this->normalizeName((string) $name);
                if ($key !== '') {
                    $this->byName[$key] = $id;
                }
            }
        }
    }
}
