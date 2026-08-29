<?php

declare(strict_types=1);

namespace Tests\Feature\Ayollar;

use App\Domains\Ayollar\Models\Anketa;
use App\Domains\Ayollar\Services\BalanceCalculator;
use Illuminate\Support\Facades\DB;

/**
 * BUZILMAS TENGLIKLAR (promt §13 tayyorlik mezonlari).
 *
 * Bu testlar bitta savolga javob beradi: balansga ISHONISH mumkinmi?
 * Ular yiqilsa, tizim raqam ko'rsatadi, lekin u raqam hech narsani
 * anglatmaydi.
 */
class BalanceCalculatorTest extends AyollarTestCase
{
    private BalanceCalculator $calc;

    private string $districtId;

    private string $mahallaId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = app(BalanceCalculator::class);
        $this->districtId = $this->someDistrictId();
        $this->mahallaId = $this->someMahallaId($this->districtId);
    }

    /**
     * Bir nechta har xil toifadagi ayol yaratadi.
     *
     * @return array{green: int, yellow: int}
     */
    private function seedMahalla(string $mahallaId, string $districtId): array
    {
        $hh = $this->makeHousehold($mahallaId, $districtId);

        // 3 yashil
        $this->makeAnketa($this->makeWoman($hh, 35), ['q11' => 'rasmiy_davlat', 'q12' => 'yoq', 'q13' => 'yoq']);
        $this->makeAnketa($this->makeWoman($hh, 22), ['q11' => 'yoq', 'q12' => 'oliy', 'q13' => 'yoq']);
        $this->makeAnketa($this->makeWoman($hh, 10), ['q11' => 'yoq', 'q12' => 'maktab', 'q13' => 'yoq']);

        // 2 sariq (biri qizil belgili)
        $this->makeAnketa($this->makeWoman($hh, 27), ['q11' => 'ishsiz', 'q12' => 'yoq', 'q13' => 'yoq']);
        $this->makeAnketa($this->makeWoman($hh, 31), [
            'q11' => 'norasmiy_band', 'q12' => 'yoq', 'q13' => 'yoq',
            'q27' => true, 'q31' => ['violence' => true],
        ]);

        return ['green' => 3, 'yellow' => 2];
    }

    // ---------------------------------------------------------------

    public function test_green_plus_yellow_equals_total(): void
    {
        $expected = $this->seedMahalla($this->mahallaId, $this->districtId);

        $balance = $this->calc->calculateMahalla($this->mahallaId, 2026, 8);

        $this->assertSame(5, $balance->total);
        $this->assertSame($expected['green'], $balance->green);
        $this->assertSame($expected['yellow'], $balance->yellow);
        $this->assertSame(
            $balance->total,
            $balance->green + $balance->yellow,
            'BUZILMAS QOIDA: yashil + sariq = jami.',
        );
        $this->assertSame([], $this->calc->verify($balance));
    }

    /** Qizil AYOLLAR soni jamidan oshmaydi — 5 belgili bitta ayol ham 1 ta. */
    public function test_red_never_exceeds_total(): void
    {
        $hh = $this->makeHousehold($this->mahallaId, $this->districtId);

        // Bitta ayol, olti qizil belgi.
        $this->makeAnketa($this->makeWoman($hh, 31), [
            'q11' => 'norasmiy_band', 'q12' => 'yoq', 'q13' => 'yoq',
            'q7' => 'ajrashgan', 'q9' => 'ha', 'q10' => true, 'q23' => true, 'q27' => true,
            'q31' => ['violence' => true],
        ]);

        $balance = $this->calc->calculateMahalla($this->mahallaId, 2026, 8);

        $this->assertSame(1, $balance->total);
        $this->assertSame(1, $balance->red, 'Qizil — AYOLLAR soni, belgilar soni EMAS.');
        $this->assertLessThanOrEqual($balance->total, $balance->red);
        $this->assertSame([], $this->calc->verify($balance));

        // Belgilar soni esa metrikalarda alohida — 6 ta.
        $metrics = $balance->metrics;
        $flagSum = $metrics['divorced_widowed'] + $metrics['conflict_family']
            + $metrics['alimony_problem'] + $metrics['social_registry']
            + $metrics['chronic_illness'] + $metrics['violence_victim'];
        $this->assertSame(6, $flagSum);
    }

    /** Yosh guruhlari yig'indisi jamiga teng. */
    public function test_age_group_sum_equals_total(): void
    {
        $this->seedMahalla($this->mahallaId, $this->districtId);

        $balance = $this->calc->calculateMahalla($this->mahallaId, 2026, 8);
        $metrics = $balance->metrics;

        $ageSum = $metrics['age_grp_0_2'] + $metrics['age_grp_3_6']
            + $metrics['age_grp_7_17'] + $metrics['age_grp_18_up'];

        $this->assertSame($balance->total, $ageSum);
    }

    /**
     * Qoralama va qaytarilgan anketa balansga TUSHMAYDI.
     *
     * Aks holda faol anketani ochib qo'yishi bilan jami o'sib ketardi va
     * MFY raisi tushuntirib bo'lmaydigan raqam ko'rardi.
     */
    public function test_draft_and_returned_are_excluded(): void
    {
        $hh = $this->makeHousehold($this->mahallaId, $this->districtId);

        $this->makeAnketa($this->makeWoman($hh, 35), ['q11' => 'rasmiy_davlat', 'q12' => 'yoq', 'q13' => 'yoq']);
        $this->makeAnketa($this->makeWoman($hh, 36), ['q11' => 'rasmiy_davlat', 'q12' => 'yoq', 'q13' => 'yoq'], Anketa::STATUS_DRAFT);
        $this->makeAnketa($this->makeWoman($hh, 37), ['q11' => 'rasmiy_davlat', 'q12' => 'yoq', 'q13' => 'yoq'], Anketa::STATUS_RETURNED);

        $balance = $this->calc->calculateMahalla($this->mahallaId, 2026, 8);

        $this->assertSame(1, $balance->total);
    }

    /** To'liq bo'lmagan anketa ham jamiga kirmaydi. */
    public function test_incomplete_is_outside_balance(): void
    {
        $hh = $this->makeHousehold($this->mahallaId, $this->districtId);

        $this->makeAnketa($this->makeWoman($hh, 35), ['q11' => 'rasmiy_davlat', 'q12' => 'yoq', 'q13' => 'yoq']);
        $incomplete = $this->makeAnketa($this->makeWoman($hh, 40), ['q11' => 'yoq', 'q12' => 'yoq', 'q13' => 'yoq']);

        $this->assertSame('incomplete', $incomplete->category);

        $balance = $this->calc->calculateMahalla($this->mahallaId, 2026, 8);

        $this->assertSame(1, $balance->total);
        $this->assertSame([], $this->calc->verify($balance));
    }

    // ---------------------------------------------------------------
    // AGREGATSIYA — og'ish 0
    // ---------------------------------------------------------------

    /** Tuman balansi = MFY balanslari yig'indisi, og'ishsiz. */
    public function test_district_equals_sum_of_mahallas(): void
    {
        $m1 = $this->mahallaId;
        $m2 = $this->otherMahallaId($m1, $this->districtId);

        $this->seedMahalla($m1, $this->districtId);
        $this->seedMahalla($m2, $this->districtId);

        $b1 = $this->calc->calculateMahalla($m1, 2026, 8);
        $b2 = $this->calc->calculateMahalla($m2, 2026, 8);

        $district = $this->calc->calculateDistrict($this->districtId, [$m1, $m2], 2026, 8);

        $this->assertSame($b1->total + $b2->total, $district->total);
        $this->assertSame($b1->green + $b2->green, $district->green);
        $this->assertSame($b1->yellow + $b2->yellow, $district->yellow);
        $this->assertSame($b1->red + $b2->red, $district->red);
        $this->assertSame([], $this->calc->verify($district));
        $this->assertSame([], $this->calc->verifyRollUp($district, collect([$b1, $b2])));
    }

    /** Viloyat balansi = tumanlar yig'indisi, og'ishsiz. */
    public function test_region_equals_sum_of_districts(): void
    {
        $d1 = $this->districtId;
        $d2 = $this->otherDistrictId($d1);
        $m1 = $this->someMahallaId($d1);
        $m2 = $this->someMahallaId($d2);

        $this->seedMahalla($m1, $d1);
        $this->seedMahalla($m2, $d2);

        $this->calc->calculateMahalla($m1, 2026, 8);
        $this->calc->calculateMahalla($m2, 2026, 8);

        $db1 = $this->calc->calculateDistrict($d1, [$m1], 2026, 8);
        $db2 = $this->calc->calculateDistrict($d2, [$m2], 2026, 8);

        $regionId = (string) DB::connection('master')->table('regions')->value('id');
        $region = $this->calc->calculateRegion($regionId, [$d1, $d2], 2026, 8);

        $this->assertSame($db1->total + $db2->total, $region->total);
        $this->assertSame([], $this->calc->verifyRollUp($region, collect([$db1, $db2])));
    }

    /**
     * Metrikalar jadvali agregatsiyada ham yig'iladi.
     *
     * Faqat `total/green/yellow` ustunlari qo'shilib, `metrics` bo'sh
     * qolsa, tuman shakli bo'sh chiqardi — MFY'da 12 ta himoya orderi,
     * tumanda 0. Aynan promt §4.1 dagi muammo.
     */
    public function test_metrics_are_summed_on_roll_up(): void
    {
        $m1 = $this->mahallaId;
        $m2 = $this->otherMahallaId($m1, $this->districtId);

        $this->seedMahalla($m1, $this->districtId);
        $this->seedMahalla($m2, $this->districtId);

        $this->calc->calculateMahalla($m1, 2026, 8);
        $this->calc->calculateMahalla($m2, 2026, 8);

        $district = $this->calc->calculateDistrict($this->districtId, [$m1, $m2], 2026, 8);

        $this->assertSame(2, $district->metrics['emp_gov']);
        $this->assertSame(2, $district->metrics['yel_informal']);
        $this->assertSame(2, $district->metrics['violence_victim'], 'Qizil qator tumanda YO‘QOLMASLIGI kerak.');
    }

    /** Yopilgan balans qayta hisoblanmaydi — imzolangan raqam o'zgarmaydi. */
    public function test_closed_balance_is_not_recalculated(): void
    {
        $hh = $this->makeHousehold($this->mahallaId, $this->districtId);
        $this->makeAnketa($this->makeWoman($hh, 35), ['q11' => 'rasmiy_davlat', 'q12' => 'yoq', 'q13' => 'yoq']);

        $balance = $this->calc->calculateMahalla($this->mahallaId, 2026, 8);
        $this->assertSame(1, $balance->total);

        $balance->update(['status' => 'closed', 'closed_at' => now()]);

        // Yangi ayol qo'shiladi — lekin balans yopilgan.
        $this->makeAnketa($this->makeWoman($hh, 40), ['q11' => 'rasmiy_davlat', 'q12' => 'yoq', 'q13' => 'yoq']);

        $recalculated = $this->calc->calculateMahalla($this->mahallaId, 2026, 8);

        $this->assertSame(1, $recalculated->total, 'Yopilgan balans o‘zgardi — imzolangan hisobot buzildi.');
    }

    /** Bir hudud + bir davr = BITTA balans qatori. */
    public function test_recalculation_updates_not_duplicates(): void
    {
        $this->seedMahalla($this->mahallaId, $this->districtId);

        $first = $this->calc->calculateMahalla($this->mahallaId, 2026, 8);
        $second = $this->calc->calculateMahalla($this->mahallaId, 2026, 8);

        $this->assertSame($first->id, $second->id);
    }

    /** Buzuq balansni `verify()` USHLAYDI. */
    public function test_verify_catches_broken_balance(): void
    {
        $this->seedMahalla($this->mahallaId, $this->districtId);
        $balance = $this->calc->calculateMahalla($this->mahallaId, 2026, 8);

        // Qo'lda buzamiz — bu holat faqat ma'lumot buzilishida yuz beradi.
        $balance->green = $balance->green + 1;

        $errors = $this->calc->verify($balance);

        $this->assertNotEmpty($errors);
        $this->assertContains('sum_mismatch', array_column($errors, 'code'));
    }
}
