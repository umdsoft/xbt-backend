<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Services;

use Illuminate\Support\Facades\DB;

/**
 * «Raqamli mahalla — daromadli oila» skoring dvigateli (native, PHP).
 *
 * Mahallalarni kompozit indekslar bo'yicha skorlab, resursni manzilli
 * yo'naltirish uchun REYTING va USTUVORLIK KVADRANTINI beradi. Manba —
 * `master.mahalla_indicators` (bandlik, kambag'allik, ijtimoiy reyestr,
 * ixtisos). Metodologiya: min-max normalizatsiya (tuman ichida), yetishmagan
 * ko'rsatkich `null` → vazn qayta normallanadi + har mahalla uchun
 * «ma'lumot to'liqligi (%)».
 *
 * Indekslar: KOI (kambag'allik og'irligi, yuqori=og'ir), IPI (iqtisodiy
 * imkoniyat), ITI (infratuzilma). US = 0.50·KOI + 0.30·IPI + 0.20·ITI.
 * Kvadrant KOI×IPI medianasi bo'yicha (A/B/C/D).
 */
final class MahallaScoring
{
    /**
     * Ko'rsatkich registri: column — mahalla_indicators ustuni;
     * index — KOI/IPI/ITI; dir — bad(og'irlashadi)|good|opp; weight — indeks ichida.
     * Ustun bo'sh (null) bo'lsa hisobga olinmaydi (vazn qayta normallanadi).
     *
     * @var array<int, array{col: string, index: string, dir: string, weight: float}>
     */
    private const INDICATORS = [
        // KOI — kambag'allik og'irligi
        ['col' => 'poverty_rate',         'index' => 'KOI', 'dir' => 'bad',  'weight' => 0.35],
        ['col' => 'unemployment_rate',    'index' => 'KOI', 'dir' => 'bad',  'weight' => 0.25],
        ['col' => 'employment_rate',      'index' => 'KOI', 'dir' => 'good', 'weight' => 0.20],
        ['col' => 'social_registry_rate', 'index' => 'KOI', 'dir' => 'bad',  'weight' => 0.20],
        // IPI — iqtisodiy imkoniyat (bo'sh resurs)
        ['col' => 'tomorqa_per_hh',         'index' => 'IPI', 'dir' => 'opp',  'weight' => 0.60],
        ['col' => 'specialization_defined', 'index' => 'IPI', 'dir' => 'good', 'weight' => 0.40],
        // ITI — hozircha mahalla kesimida manba yo'q (kelganda qo'shiladi)
    ];

    private const PRIORITY_WEIGHTS = ['KOI' => 0.50, 'IPI' => 0.30, 'ITI' => 0.20];

    /** Indeks yuqori bo'lishiga MOS keladigan (invert QILINMAYDIGAN) yo'nalishlar. */
    private const RAISES = ['KOI' => ['bad'], 'IPI' => ['good', 'opp'], 'ITI' => ['good', 'opp']];

    /**
     * Tuman bo'yicha mahalla reytingi + kvadrant.
     *
     * @return array{rows: array<int, array<string, mixed>>,
     *               kvadrantlar: array<string, int>, toliqlik: float}
     */
    public function district(string $districtId): array
    {
        $rows = $this->indicators($districtId);
        if ($rows === []) {
            return ['rows' => [], 'kvadrantlar' => [], 'toliqlik' => 0.0];
        }

        // 1) Har ko'rsatkichni tuman bo'yicha 0..100 ga normallash.
        $norm = [];
        foreach (self::INDICATORS as $ind) {
            $vals = array_map(fn ($r) => $this->num($r[$ind['col']] ?? null), $rows);
            $invert = ! in_array($ind['dir'], self::RAISES[$ind['index']], true);
            $norm[$ind['col']] = $this->minmax($vals, $invert);
        }

        // 2) Kompozit indekslar + to'liqlik.
        foreach ($rows as $i => &$r) {
            foreach (['KOI', 'IPI', 'ITI'] as $idx) {
                [$sum, $w, $have, $total] = [0.0, 0.0, 0, 0];
                foreach (self::INDICATORS as $ind) {
                    if ($ind['index'] !== $idx) {
                        continue;
                    }
                    $total++;
                    $v = $norm[$ind['col']][$i];
                    if ($v !== null) {
                        $sum += $v * $ind['weight'];
                        $w += $ind['weight'];
                        $have++;
                    }
                }
                $r[$idx] = $w > 0 ? round($sum / $w, 1) : null;
                $r["_{$idx}_have"] = $have;
                $r["_{$idx}_total"] = $total;
            }
            $haveAll = $r['_KOI_have'] + $r['_IPI_have'] + $r['_ITI_have'];
            $totAll = count(self::INDICATORS);
            $r['malumot_toliqligi'] = $totAll > 0 ? round($haveAll / $totAll * 100) : 0;
        }
        unset($r);

        // 3) Ustuvorlik skori.
        foreach ($rows as &$r) {
            [$sum, $w] = [0.0, 0.0];
            foreach (self::PRIORITY_WEIGHTS as $idx => $pw) {
                if ($r[$idx] !== null) {
                    $sum += $r[$idx] * $pw;
                    $w += $pw;
                }
            }
            $r['ustuvorlik'] = $w > 0 ? round($sum / $w, 1) : null;
        }
        unset($r);

        // 4) Kvadrant (KOI×IPI medianasi).
        $koiMed = $this->median(array_filter(array_column($rows, 'KOI'), fn ($v) => $v !== null)) ?? 50;
        $ipiMed = $this->median(array_filter(array_column($rows, 'IPI'), fn ($v) => $v !== null)) ?? 50;
        foreach ($rows as &$r) {
            $r['kvadrant'] = $this->quadrant($r['KOI'], $r['IPI'], $koiMed, $ipiMed);
        }
        unset($r);

        // 5) Reyting (ustuvorlik kamayishi).
        usort($rows, fn ($a, $b) => [$b['ustuvorlik'] ?? -1] <=> [$a['ustuvorlik'] ?? -1]);
        $kv = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0];
        $sumToliq = 0.0;
        foreach ($rows as $i => &$r) {
            $r['reyting'] = $i + 1;
            $letter = substr((string) $r['kvadrant'], 0, 1);
            if (isset($kv[$letter])) {
                $kv[$letter]++;
            }
            $sumToliq += (float) $r['malumot_toliqligi'];
            // Daromad manbai tavsiyalari (ixtisos + bandlik asosida) + intervensiya.
            $r['tavsiyalar'] = $this->recommendations($r);
            // Ichki yordamchi kalitlarni tozalash.
            unset($r['_KOI_have'], $r['_KOI_total'], $r['_IPI_have'], $r['_IPI_total'], $r['_ITI_have'], $r['_ITI_total']);
        }
        unset($r);

        return [
            'rows' => $rows,
            'kvadrantlar' => $kv,
            'toliqlik' => round($sumToliq / count($rows), 1),
        ];
    }

    /**
     * Tuman mahallalari + har ustun uchun eng so'nggi to'ldirilgan qiymat.
     *
     * @return array<int, array<string, mixed>>
     */
    private function indicators(string $districtId): array
    {
        $cols = ['poverty_rate', 'employment_rate', 'social_registry_rate', 'specialization_defined',
            'specialization', 'population', 'households', 'families', 'employed_population', 'poor_families',
            'unemployment_rate', 'unemployed', 'tomorqa_area_sotix', 'tomorqa_households'];
        $agg = [];
        foreach ($cols as $c) {
            $agg[] = "(array_agg(i.{$c} order by i.period desc) filter (where i.{$c} is not null))[1] as {$c}";
        }

        $rows = DB::connection('master')->table('mahallas as m')
            ->leftJoin('mahalla_indicators as i', 'i.mahalla_id', '=', 'm.id')
            ->where('m.district_id', $districtId)
            ->where('m.is_active', true)
            ->groupBy('m.id', 'm.name_cyr', 'm.sort_order')
            ->orderBy('m.sort_order')->orderBy('m.name_cyr')
            ->select(DB::connection('master')->raw('m.id, m.name_cyr, '.implode(', ', $agg)))
            ->get();

        return $rows->map(function ($r) {
            $households = $r->households === null ? null : (int) $r->households;
            $tomorqaArea = $r->tomorqa_area_sotix === null ? null : (float) $r->tomorqa_area_sotix;
            // Xonadonga o'rtacha tomorqa (sotix) — bo'sh resurs (IPI imkoniyat) proksisi.
            $tomorqaPerHh = ($tomorqaArea !== null && $households) ? round($tomorqaArea / $households, 2) : null;

            return [
                'mahalla' => ['id' => $r->id, 'name' => $r->name_cyr],
                'poverty_rate' => $r->poverty_rate === null ? null : (float) $r->poverty_rate,
                'employment_rate' => $r->employment_rate === null ? null : (float) $r->employment_rate,
                'social_registry_rate' => $r->social_registry_rate === null ? null : (float) $r->social_registry_rate,
                'specialization_defined' => $r->specialization_defined === null ? null : (int) (bool) $r->specialization_defined,
                'specialization' => $r->specialization,
                'population' => $r->population === null ? null : (int) $r->population,
                'households' => $households,
                'families' => $r->families === null ? null : (int) $r->families,
                'employed_population' => $r->employed_population === null ? null : (int) $r->employed_population,
                'poor_families' => $r->poor_families === null ? null : (int) $r->poor_families,
                'unemployment_rate' => $r->unemployment_rate === null ? null : (float) $r->unemployment_rate,
                'unemployed' => $r->unemployed === null ? null : (int) $r->unemployed,
                'tomorqa_area_sotix' => $tomorqaArea,
                'tomorqa_households' => $r->tomorqa_households === null ? null : (int) $r->tomorqa_households,
                'tomorqa_per_hh' => $tomorqaPerHh,
            ];
        })->all();
    }

    private function num(mixed $v): ?float
    {
        return $v === null || $v === '' ? null : (float) $v;
    }

    /**
     * 0..100 ga normallash. invert=true → yuqori qiymat past skor.
     *
     * @param  array<int, float|null>  $vals
     * @return array<int, float|null>
     */
    private function minmax(array $vals, bool $invert): array
    {
        $present = array_values(array_filter($vals, fn ($v) => $v !== null));
        if ($present === []) {
            return array_fill(0, count($vals), null);
        }
        $lo = min($present);
        $hi = max($present);

        return array_map(function ($v) use ($lo, $hi, $invert) {
            if ($v === null) {
                return null;
            }
            if ($hi === $lo) {
                return 50.0; // variatsiya yo'q — neytral
            }
            $n = ($v - $lo) / ($hi - $lo) * 100.0;

            return round($invert ? 100.0 - $n : $n, 2);
        }, $vals);
    }

    /** @param array<int, float> $xs */
    private function median(array $xs): ?float
    {
        $xs = array_values($xs);
        sort($xs);
        $n = count($xs);
        if ($n === 0) {
            return null;
        }

        return $n % 2 ? $xs[intdiv($n, 2)] : ($xs[$n / 2 - 1] + $xs[$n / 2]) / 2;
    }

    /**
     * Mahalla darajasidagi daromad manbai tavsiyalari — ixtisoslashuv +
     * bandlik asosida (oila mikrodatasi kelganda oila kesimida aniqlashadi).
     *
     * @param  array<string, mixed>  $r
     * @return array<int, array{yonalish: string, imtiyoz: string}>
     */
    private function recommendations(array $r): array
    {
        $ix = mb_strtolower((string) ($r['specialization'] ?? ''));
        $out = [];
        $add = function (array $keys, string $yonalish, string $imtiyoz) use ($ix, &$out) {
            foreach ($keys as $k) {
                if (str_contains($ix, $k)) {
                    $out[$yonalish] = ['yonalish' => $yonalish, 'imtiyoz' => $imtiyoz];

                    return;
                }
            }
        };
        $add(['чорва', 'қорамол', 'сут'], 'Chorvachilik/sutchilikni kengaytirish', 'Chorva lizingi / imtiyozli kredit');
        $add(['боғ', 'мева', 'кўчат'], "Bog'dorchilik/ko'chatchilik + issiqxona", 'Issiqxona subsidiyasi');
        $add(['деҳқон', 'дехқон', 'полиз', 'сабзавот'], 'Issiqxona/intensiv dehqonchilik', 'Issiqxona subsidiyasi');
        $add(['савдо', 'хизмат'], 'Savdo-xizmat mikro-tadbirkorligi', 'Oilaviy tadbirkorlik dasturi');
        $add(['ҳунар', 'хунар', 'тикув', 'косиб'], 'Hunarmandchilik mikroloyihasi', 'Uskuna granti');
        $add(['парранда'], 'Parrandachilik klasteri', 'Imtiyozli kredit');
        $add(['нонвой', 'озиқ', 'овқат'], "Oziq-ovqat qayta ishlash", 'Mikroloyiha krediti');
        // Bandligi past bo'lsa — ko'nikma orqali bandlik doim dolzarb.
        $out['raqamli'] = ['yonalish' => 'Raqamli kasb/IT-kurs → masofaviy bandlik', 'imtiyoz' => "Bandlik jamg'armasi vaucheri"];

        return array_slice(array_values($out), 0, 3);
    }

    private function quadrant(?float $koi, ?float $ipi, float $koiMed, float $ipiMed): string
    {
        if ($koi === null || $ipi === null) {
            return 'Maʼlumot yetarli emas';
        }
        $need = $koi >= $koiMed;
        $opp = $ipi >= $ipiMed;

        return match (true) {
            $need && $opp => 'A — Tezkor taʼsir (yuqori ehtiyoj + imkoniyat)',
            $need && ! $opp => 'B — Chuqur aralashuv (avval infratuzilma/koʻnikma)',
            ! $need && $opp => 'C — Oʻsish nuqtasi (potentsialni ishga solish)',
            default => 'D — Barqaror (kuzatuv)',
        };
    }
}
