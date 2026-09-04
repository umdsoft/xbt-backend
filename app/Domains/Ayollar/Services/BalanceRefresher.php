<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Services;

use App\Domains\Ayollar\Models\DistrictBalance;
use App\Domains\Ayollar\Models\MahallaBalance;
use App\Domains\Ayollar\Models\RegionBalance;
use Illuminate\Support\Facades\DB;

/**
 * BALANSNI YANGILASH — inkremental va to'liq.
 *
 * NEGA ALOHIDA SERVIS: `BalanceCalculator` bitta hududni hisoblaydi,
 * bu servis esa QAYSI hududlarni va QACHON yangilash kerakligini biladi.
 * Ikkisini aralashtirish o'qish endpoint'ida butun viloyatni qayta
 * hisoblashga olib keldi — 509 MFY × ~5 so'rov = sahifa umuman
 * ochilmasdi.
 *
 * IKKI REJIM (promt §4: «kechasi qayta hisoblanadi + real vaqtda
 * inkremental»):
 *
 *   `afterAnketaChange()` — anketa saqlanganda. FAQAT o'zgargan MFY
 *       qayta hisoblanadi, keyin uning tumani va viloyat YIG'INDIDAN
 *       yangilanadi. Jami ~8 so'rov — foydalanuvchi kutmaydi.
 *
 *   `full()` — kechasi, `ayollar:recalculate` buyrug'i orqali. Bu
 *       yerda 509 MFY qayta hisoblanishi normal: hech kim kutmayapti.
 *
 * O'QISH ENDPOINT'LARI HISOBLAMAYDI — ular saqlangan qatorni o'qiydi.
 */
class BalanceRefresher
{
    public function __construct(private readonly BalanceCalculator $calculator) {}

    /**
     * Kechiktirilgan rejim — paketli operatsiyalar uchun.
     *
     * NEGA KERAK: 100 ta anketani ketma-ket saqlashda har biri o'z MFY,
     * tuman va viloyat balansini yangilasa, viloyat 100 marta qayta
     * hisoblanadi — bir xil natija bilan. Kechiktirilgan rejimda
     * o'zgargan MFY'lar TO'PLANADI va oxirida BIR marta yangilanadi.
     *
     * @var array<string, string>|null mahalla_id => district_id
     */
    private ?array $pending = null;

    /**
     * Blok ichidagi barcha yangilanishlarni oxirida BIR marta bajaradi.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function defer(callable $work, ?int $year = null, ?int $month = null): mixed
    {
        // Ichma-ich chaqiruv: tashqi blok baribir yakunda yuvadi.
        if ($this->pending !== null) {
            return $work();
        }

        $this->pending = [];

        try {
            $result = $work();
        } finally {
            $dirty = $this->pending;
            $this->pending = null;

            if ($dirty !== []) {
                $this->flush($dirty, $year ?? (int) now()->year, $month ?? (int) now()->month);
            }
        }

        return $result;
    }

    /**
     * Anketa o'zgargandan keyin — INKREMENTAL.
     *
     * Yopilgan balans `BalanceCalculator::store()` da baribir chetlab
     * o'tiladi, shuning uchun bu yerda alohida tekshiruv kerak emas.
     */
    public function afterAnketaChange(string $mahallaId, string $districtId, ?int $year = null, ?int $month = null): void
    {
        if ($this->pending !== null) {
            $this->pending[$mahallaId] = $districtId;

            return;
        }

        $year ??= (int) now()->year;
        $month ??= (int) now()->month;

        $this->calculator->calculateMahalla($mahallaId, $year, $month);
        $this->rollUpDistrict($districtId, $year, $month);
        $this->rollUpRegion($year, $month);
    }

    /**
     * To'plangan o'zgarishlarni yuvadi.
     *
     * Tuman BIR marta yangilanadi — o'sha tumandagi 10 ta MFY o'zgargan
     * bo'lsa ham.
     *
     * @param  array<string, string>  $dirty
     */
    private function flush(array $dirty, int $year, int $month): void
    {
        foreach ($dirty as $mahallaId => $districtId) {
            $this->calculator->calculateMahalla($mahallaId, $year, $month);
        }

        foreach (array_unique(array_values($dirty)) as $districtId) {
            $this->rollUpDistrict($districtId, $year, $month);
        }

        $this->rollUpRegion($year, $month);
    }

    /** Tuman balansini MFY yig'indisidan yangilaydi. */
    public function rollUpDistrict(string $districtId, int $year, int $month): DistrictBalance
    {
        return $this->calculator->calculateDistrict(
            $districtId,
            $this->mahallaIdsOf($districtId),
            $year,
            $month,
        );
    }

    /** Viloyat balansini tuman yig'indisidan yangilaydi. */
    public function rollUpRegion(int $year, int $month): RegionBalance
    {
        return $this->calculator->calculateRegion(
            $this->regionId(),
            $this->districtIds(),
            $year,
            $month,
        );
    }

    /**
     * TO'LIQ qayta hisoblash — kechasi.
     *
     * `$onProgress` konsol indikatori uchun: 509 MFY bir necha daqiqa
     * oladi va buyruq «qotib qolgandek» ko'rinmasligi kerak.
     *
     * @param  callable(string): void|null  $onProgress
     * @return array{mahallas: int, districts: int}
     */
    public function full(int $year, int $month, ?callable $onProgress = null): array
    {
        $districts = 0;
        $mahallas = 0;

        foreach ($this->districtIds() as $districtId) {
            foreach ($this->mahallaIdsOf($districtId) as $mahallaId) {
                $this->calculator->calculateMahalla($mahallaId, $year, $month);
                $mahallas++;

                if ($onProgress !== null) {
                    $onProgress($mahallaId);
                }
            }

            $this->rollUpDistrict($districtId, $year, $month);
            $districts++;
        }

        $this->rollUpRegion($year, $month);

        return ['mahallas' => $mahallas, 'districts' => $districts];
    }

    /**
     * MFY balansini o'qiydi; yo'q bo'lsa BIR MARTA hisoblaydi.
     *
     * «Yo'q bo'lsa hisoblash» kerak, chunki yangi MFY yoki yangi oy
     * boshlanganda qator hali yaratilmagan bo'ladi va foydalanuvchi
     * «ma'lumot yo'q» o'rniga nol ko'rishi kerak. Bu BITTA hudud —
     * arzon.
     */
    public function readMahalla(string $mahallaId, int $year, int $month): MahallaBalance
    {
        $balance = MahallaBalance::query()
            ->where('mahalla_id', $mahallaId)
            ->where('period_year', $year)->where('period_month', $month)
            ->first();

        return $balance ?? $this->calculator->calculateMahalla($mahallaId, $year, $month);
    }

    public function readDistrict(string $districtId, int $year, int $month): DistrictBalance
    {
        $balance = DistrictBalance::query()
            ->where('district_id', $districtId)
            ->where('period_year', $year)->where('period_month', $month)
            ->first();

        return $balance ?? $this->rollUpDistrict($districtId, $year, $month);
    }

    public function readRegion(int $year, int $month): RegionBalance
    {
        $balance = RegionBalance::query()
            ->where('region_id', $this->regionId())
            ->where('period_year', $year)->where('period_month', $month)
            ->first();

        return $balance ?? $this->rollUpRegion($year, $month);
    }

    /** @return array<int, string> */
    public function mahallaIdsOf(string $districtId): array
    {
        return DB::connection('master')->table('mahallas')
            ->where('district_id', $districtId)->where('is_active', true)
            ->pluck('id')->map(fn ($v) => (string) $v)->all();
    }

    /** @return array<int, string> */
    public function districtIds(): array
    {
        return DB::connection('master')->table('districts')
            ->orderBy('sort_order')
            ->pluck('id')->map(fn ($v) => (string) $v)->all();
    }

    public function regionId(): string
    {
        return (string) DB::connection('master')->table('regions')->value('id');
    }
}
