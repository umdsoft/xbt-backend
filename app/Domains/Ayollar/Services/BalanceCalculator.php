<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Services;

use App\Domains\Ayollar\Models\Anketa;
use App\Domains\Ayollar\Models\Balance;
use App\Domains\Ayollar\Models\DistrictBalance;
use App\Domains\Ayollar\Models\MahallaBalance;
use App\Domains\Ayollar\Models\Metric;
use App\Domains\Ayollar\Models\RegionBalance;
use Illuminate\Support\Facades\DB;

/**
 * BALANS HISOBLASH — pastdan yuqoriga, og'ishsiz.
 *
 *     anketalar -> MFY balansi -> tuman balansi -> viloyat balansi
 *
 * Har daraja QUYIDAGISINING yig'indisi. Tuman balansi anketalardan QAYTA
 * hisoblanmaydi — u MFY balanslarini qo'shadi. Sabab: ikki xil hisoblash
 * yo'li ikki xil natija berish IMKONIYATIni yaratadi (bittasi o'chirilgan
 * yozuvni hisobga oladi, ikkinchisi yo'q), va bu farq faqat oy oxirida,
 * imzolar qo'yilgandan keyin sezilardi.
 *
 * BUZILMAS TENGLIK (promt §1.6):
 *     SUM(yashil) + SUM(sariq) === jami
 *     SUM(qizil)  <= jami
 *     SUM(yosh guruhlari) === jami
 *
 * Birinchisi `CategoryResolver` tuzilishidan kelib chiqadi (har anketa
 * qat'iy bitta qatorga tushadi), lekin BU YERDA HAM tekshiriladi: yopish
 * paytidagi tekshiruv — noto'g'ri balans imzolanishiga qarshi oxirgi
 * to'siq.
 */
class BalanceCalculator
{
    /**
     * Lug'at BIR MARTA o'qiladi.
     *
     * NEGA MUHIM: to'liq qayta hisoblashda `calculateMahalla()` 509 marta
     * chaqiriladi va har chaqiruvda lug'at qayta so'ralsa, bu 509 ta bir
     * xil so'rov degani. O'lchovda bu qayta hisoblash vaqtining yarmini
     * tashkil qilardi.
     *
     * @var array<string, int>|null
     */
    private ?array $emptyMetricsCache = null;

    /** @var \Illuminate\Support\Collection<string, string>|null */
    private $categoryCache = null;

    /**
     * MFY balansi — anketalardan BEVOSITA.
     *
     * @return MahallaBalance
     */
    public function calculateMahalla(string $mahallaId, int $year, int $month): MahallaBalance
    {
        $rows = Anketa::query()
            ->countable()
            ->where('mahalla_id', $mahallaId)
            ->selectRaw('balance_row, category, age_group, count(*) as c')
            ->groupBy('balance_row', 'category', 'age_group')
            ->get();

        $metrics = $this->emptyMetrics();
        $total = 0;
        $green = 0;
        $yellow = 0;

        foreach ($rows as $row) {
            $count = (int) $row->c;
            $total += $count;

            $metrics[$row->balance_row] = ($metrics[$row->balance_row] ?? 0) + $count;
            $metrics['age_grp_'.$row->age_group] = ($metrics['age_grp_'.$row->age_group] ?? 0) + $count;

            if ($row->category === 'green') {
                $green += $count;
            } elseif ($row->category === 'yellow') {
                $yellow += $count;
            }
        }

        // Qizil belgilar ALOHIDA so'rov bilan: ular anketa bilan 1:N, ya'ni
        // yuqoridagi `group by` ga qo'shilsa, bir anketa bir necha marta
        // sanalib, jami sun'iy o'sib ketardi.
        $red = $this->redFlagCounts($mahallaId);
        $metrics = array_merge($metrics, $red['by_code']);
        $metrics['total'] = $total;

        return $this->store(
            MahallaBalance::query()->firstOrNew([
                'mahalla_id' => $mahallaId,
                'period_year' => $year,
                'period_month' => $month,
            ]),
            $metrics,
            $total,
            $green,
            $yellow,
            $red['women'],
        );
    }

    /**
     * Tuman balansi — MFY balanslari YIG'INDISI.
     *
     * @param  array<int, string>  $mahallaIds  tumanning barcha MFY'lari
     */
    public function calculateDistrict(string $districtId, array $mahallaIds, int $year, int $month): DistrictBalance
    {
        $children = MahallaBalance::query()
            ->whereIn('mahalla_id', $mahallaIds)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->get();

        return $this->rollUp(
            DistrictBalance::query()->firstOrNew([
                'district_id' => $districtId,
                'period_year' => $year,
                'period_month' => $month,
            ]),
            $children,
        );
    }

    /**
     * Viloyat balansi — 13 tuman YIG'INDISI.
     *
     * @param  array<int, string>  $districtIds
     */
    public function calculateRegion(string $regionId, array $districtIds, int $year, int $month): RegionBalance
    {
        $children = DistrictBalance::query()
            ->whereIn('district_id', $districtIds)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->get();

        return $this->rollUp(
            RegionBalance::query()->firstOrNew([
                'region_id' => $regionId,
                'period_year' => $year,
                'period_month' => $month,
            ]),
            $children,
        );
    }

    /**
     * Yopishdan oldingi tekshiruvlar.
     *
     * Natija — XATOLAR RO'YXATI, `bool` emas: ekran qaysi QATORDA
     * nomuvofiqlik borligini ko'rsatishi kerak (promt §1.6), «balans
     * noto'g'ri» degan xabar tuzatish uchun yaroqsiz.
     *
     * @return array<int, array{code: string, message: string, expected: int, actual: int}>
     */
    public function verify(Balance $balance): array
    {
        $errors = [];
        $metrics = $balance->metrics ?? [];

        if ($balance->green + $balance->yellow !== $balance->total) {
            $errors[] = [
                'code' => 'sum_mismatch',
                'message' => 'Yashil + sariq jamiga teng emas.',
                'expected' => $balance->total,
                'actual' => $balance->green + $balance->yellow,
            ];
        }

        if ($balance->red > $balance->total) {
            $errors[] = [
                'code' => 'red_exceeds_total',
                'message' => 'Qizil toifadagilar soni jamidan ko‘p.',
                'expected' => $balance->total,
                'actual' => $balance->red,
            ];
        }

        $ageSum = 0;
        foreach ($metrics as $code => $value) {
            if (str_starts_with((string) $code, 'age_grp_')) {
                $ageSum += (int) $value;
            }
        }

        if ($ageSum !== $balance->total) {
            $errors[] = [
                'code' => 'age_sum_mismatch',
                'message' => 'Yosh guruhlari yig‘indisi jamiga teng emas.',
                'expected' => $balance->total,
                'actual' => $ageSum,
            ];
        }

        // Qator yig'indisi ham tekshiriladi: metrikalar jadvalida yashil va
        // sariq qatorlarning yig'indisi `green`/`yellow` ustunlariga teng
        // bo'lishi kerak. Farq bo'lsa, demak `metric_registry` da bo'lmagan
        // `balance_row` paydo bo'lgan — ya'ni `rules.json` va lug'at
        // sinxrondan chiqqan.
        $rowSums = $this->rowSums($metrics);

        if ($rowSums['green'] !== $balance->green) {
            $errors[] = [
                'code' => 'green_rows_mismatch',
                'message' => 'Yashil qatorlar yig‘indisi ustunga teng emas — lug‘at va qoidalar sinxrondan chiqqan.',
                'expected' => $balance->green,
                'actual' => $rowSums['green'],
            ];
        }

        if ($rowSums['yellow'] !== $balance->yellow) {
            $errors[] = [
                'code' => 'yellow_rows_mismatch',
                'message' => 'Sariq qatorlar yig‘indisi ustunga teng emas — lug‘at va qoidalar sinxrondan chiqqan.',
                'expected' => $balance->yellow,
                'actual' => $rowSums['yellow'],
            ];
        }

        return $errors;
    }

    /**
     * Tuman/viloyat balansi bolalarning YIG'INDISIga tengmi (og'ish 0).
     *
     * @param  \Illuminate\Support\Collection<int, Balance>  $children
     * @return array<int, array{code: string, message: string, expected: int, actual: int}>
     */
    public function verifyRollUp(Balance $parent, $children): array
    {
        $sum = ['total' => 0, 'green' => 0, 'yellow' => 0, 'red' => 0];

        foreach ($children as $child) {
            foreach ($sum as $key => $_) {
                $sum[$key] += (int) $child->{$key};
            }
        }

        $errors = [];

        foreach ($sum as $key => $expected) {
            if ((int) $parent->{$key} !== $expected) {
                $errors[] = [
                    'code' => "rollup_{$key}_mismatch",
                    'message' => "Yig‘indi mos emas: {$key}.",
                    'expected' => $expected,
                    'actual' => (int) $parent->{$key},
                ];
            }
        }

        return $errors;
    }

    // ---------------------------------------------------------------

    /**
     * MFY'dagi qizil belgilar.
     *
     * `by_code` — har belgi bo'yicha son (bir ayolda bir nechta bo'lishi
     * mumkin, shuning uchun yig'indisi jamidan katta bo'lishi NORMAL).
     * `women`   — noyob AYOLLAR soni: `qizil <= jami` tekshiruvi aynan
     * shu songa nisbatan bo'ladi, aks holda 5 belgili bitta ayol
     * tekshiruvni yiqitardi.
     *
     * @return array{by_code: array<string, int>, women: int}
     */
    private function redFlagCounts(string $mahallaId): array
    {
        $anketaIds = Anketa::query()
            ->countable()
            ->where('mahalla_id', $mahallaId)
            ->select('id');

        $byCode = DB::connection('ayollar')->table('anketa_red_flags')
            ->whereIn('anketa_id', $anketaIds)
            ->selectRaw('flag_code, count(*) as c')
            ->groupBy('flag_code')
            ->pluck('c', 'flag_code')
            ->map(fn ($v) => (int) $v)
            ->all();

        $women = DB::connection('ayollar')->table('anketa_red_flags')
            ->whereIn('anketa_id', $anketaIds)
            ->distinct()
            ->count('anketa_id');

        return ['by_code' => $byCode, 'women' => (int) $women];
    }

    /**
     * Bolalarni qo'shadi.
     *
     * @param  \Illuminate\Support\Collection<int, Balance>  $children
     *
     * @template TBalance of Balance
     */
    private function rollUp(Balance $parent, $children): Balance
    {
        $metrics = $this->emptyMetrics();
        $total = 0;
        $green = 0;
        $yellow = 0;
        $red = 0;

        foreach ($children as $child) {
            $total += (int) $child->total;
            $green += (int) $child->green;
            $yellow += (int) $child->yellow;
            $red += (int) $child->red;

            foreach (($child->metrics ?? []) as $code => $value) {
                $metrics[$code] = ($metrics[$code] ?? 0) + (int) $value;
            }
        }

        $metrics['total'] = $total;

        return $this->store($parent, $metrics, $total, $green, $yellow, $red);
    }

    /** @param array<string, int> $metrics */
    private function store(Balance $balance, array $metrics, int $total, int $green, int $yellow, int $red): Balance
    {
        // Yopilgan/tasdiqlangan balans QAYTA HISOBLANMAYDI. Aks holda
        // kechasi yuriladigan qayta hisoblash imzolangan hisobot ostidan
        // raqamni o'zgartirib yuborardi.
        if (! $balance->exists || $balance->isEditable()) {
            $balance->fill([
                'metrics' => $metrics,
                'total' => $total,
                'green' => $green,
                'yellow' => $yellow,
                'red' => $red,
                'calculated_at' => now(),
            ]);

            if (! $balance->exists) {
                $balance->status = Balance::OPEN;
            }

            $balance->save();
        }

        return $balance;
    }

    /**
     * Lug'atdagi barcha qatorlar nol bilan.
     *
     * NEGA NOLLAR KERAK: shakl `metric_registry` dan generatsiya qilinadi
     * va MFY'da uchramagan qator ham jadvalda `0` bo'lib turishi kerak.
     * Yo'q qator «ma'lumot yig'ilmagan» degan shubha tug'dirardi.
     *
     * @return array<string, int>
     */
    private function emptyMetrics(): array
    {
        return $this->emptyMetricsCache ??= Metric::query()->pluck('code')
            ->mapWithKeys(fn (string $code) => [$code => 0])
            ->all();
    }

    /**
     * Metrikalarni toifa bo'yicha yig'adi.
     *
     * @param  array<string, mixed>  $metrics
     * @return array{green: int, yellow: int}
     */
    private function rowSums(array $metrics): array
    {
        $categories = $this->categoryCache ??= Metric::query()->pluck('category', 'code');
        $sums = ['green' => 0, 'yellow' => 0];

        foreach ($metrics as $code => $value) {
            $category = $categories[$code] ?? null;

            if ($category === 'green' || $category === 'yellow') {
                $sums[$category] += (int) $value;
            }
        }

        return $sums;
    }
}
