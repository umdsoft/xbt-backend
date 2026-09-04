<?php

declare(strict_types=1);

namespace Tests\Unit\Ayollar;

use App\Domains\Ayollar\Services\CategoryResolution;
use App\Domains\Ayollar\Services\CategoryResolver;
use App\Domains\Ayollar\Support\Rules;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Toifalash zinapoyasi — 23 qadamning HAR BIRI uchun test (promt §12).
 *
 * Bu tizimning eng muhim testi: bu yerdagi xato butun viloyat balansini
 * jimgina noto'g'ri qiladi va uni faqat oy oxirida, «yashil + sariq ≠ jami»
 * xatosi chiqqanda sezish mumkin bo'lardi.
 */
class CategoryResolverTest extends TestCase
{
    private CategoryResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        Rules::flush();
        $this->resolver = new CategoryResolver;
    }

    /**
     * Har qadam uchun: [qadam, yosh, javoblar, kutilgan toifa, kutilgan qator].
     *
     * Javoblar ATAYLAB minimal — faqat shu qadamni ishga tushiradigan va
     * undan YUQORIDAGI qadamlarni ishga tushirMAYdigan qiymatlar. Agar
     * zinapoya tartibi buzilsa, aynan shu narsa testni yiqitadi.
     *
     * @return array<string, array{int, int, array<string, mixed>, string, string}>
     */
    public static function ladderProvider(): array
    {
        // «Hech narsa» holati: undan yuqoridagi barcha shartlar yolg'on.
        $none = ['q11' => 'yoq', 'q12' => 'yoq', 'q13' => 'yoq'];
        $edu = fn (string $v) => ['q11' => 'yoq', 'q12' => $v, 'q13' => 'yoq'];
        $emp = fn (string $v) => ['q11' => $v, 'q12' => 'yoq', 'q13' => 'yoq'];

        return [
            '1 · yosh 0-2' => [1, 1, $none, 'green', 'age_0_2'],
            '2 · yosh 3-6' => [2, 5, $none, 'green', 'age_3_6'],
            '3 · oliy keyingi' => [3, 30, $edu('oliy_keyingi'), 'green', 'edu_postgrad'],
            '4 · oliy' => [4, 22, $edu('oliy'), 'green', 'edu_higher'],
            '5 · professional' => [5, 19, $edu('professional'), 'green', 'edu_professional'],
            '6 · maktab' => [6, 12, $edu('maktab'), 'green', 'edu_school'],
            '7 · MTT' => [7, 8, $edu('mtt'), 'green', 'edu_preschool'],
            '8 · davlat' => [8, 35, $emp('rasmiy_davlat'), 'green', 'emp_gov'],
            '9 · xususiy' => [9, 35, $emp('rasmiy_xususiy'), 'green', 'emp_private'],
            '10 · tadbirkor' => [10, 40, $emp('rasmiy_tadbirkor'), 'green', 'emp_entrepreneur'],
            '11 · YaTT' => [11, 28, $emp('rasmiy_yatt'), 'green', 'emp_yatt'],
            '12 · o‘zini o‘zi band' => [12, 33, $emp('ozini_ozi_band'), 'green', 'emp_self'],
            '13 · fermer' => [13, 45, $emp('fermer_dehqon'), 'green', 'emp_farmer'],
            '14 · nafaqa' => [14, 62, $emp('yoshga_doir_nafaqa'), 'green', 'emp_pension'],
            '15 · harbiy' => [15, 24, $emp('harbiy_xizmat'), 'green', 'emp_military'],
            '16 · migratsiya' => [16, 29, ['q11' => 'yoq', 'q12' => 'yoq', 'q13' => 'tashqi'], 'yellow', 'yel_migration'],
            '17 · JIEM' => [17, 26, $emp('jiem'), 'yellow', 'yel_jiem'],
            '18 · layoqatsiz' => [18, 41, $emp('mehnatga_layoqatsiz'), 'yellow', 'yel_incapable'],
            '19 · norasmiy' => [19, 31, $emp('norasmiy_band'), 'yellow', 'yel_informal'],
            '20 · ishsiz' => [20, 27, $emp('ishsiz'), 'yellow', 'yel_unemployed'],
            '21 · uy bekasi' => [21, 34, $emp('uy_bekasi'), 'yellow', 'yel_homemaker'],
            '22 · abituriyent' => [22, 18, $edu('abituriyent'), 'yellow', 'yel_applicant'],
        ];
    }

    /** @param array<string, mixed> $answers */
    #[DataProvider('ladderProvider')]
    public function test_ladder_step(int $step, int $age, array $answers, string $category, string $row): void
    {
        $r = $this->resolver->resolve($answers, $age);

        $this->assertSame($step, $r->step, "Qadam {$step} o‘rniga {$r->step} ishladi.");
        $this->assertSame($category, $r->category);
        $this->assertSame($row, $r->balanceRow);
    }

    /** 23-qadam: hech biri mos kelmadi -> balansdan tashqarida. */
    public function test_step_23_incomplete(): void
    {
        $r = $this->resolver->resolve(['q11' => 'yoq', 'q12' => 'yoq', 'q13' => 'yoq'], 25);

        $this->assertSame(23, $r->step);
        $this->assertTrue($r->isIncomplete());
        $this->assertNull($r->balanceRow);
    }

    /** Bo'sh anketa ham yiqilmaydi — `incomplete` bo'ladi. */
    public function test_empty_answers_are_incomplete_not_error(): void
    {
        $r = $this->resolver->resolve([], 25);

        $this->assertTrue($r->isIncomplete());
    }

    // ---------------------------------------------------------------
    // ZINAPOYA TARTIBI — eng nozik qism
    // ---------------------------------------------------------------

    /**
     * Yosh HAMMA NARSADAN ustun.
     *
     * 2 yoshli bolaga ta'lim/bandlik javobi tasodifan yozilib qolsa ham,
     * u `age_0_2` qatorida qolishi kerak.
     */
    public function test_age_beats_everything(): void
    {
        $r = $this->resolver->resolve(
            ['q11' => 'rasmiy_davlat', 'q12' => 'oliy', 'q13' => 'tashqi'],
            2,
        );

        $this->assertSame(1, $r->step);
        $this->assertSame('age_0_2', $r->balanceRow);
    }

    /**
     * Ta'lim bandlikdan USTUN.
     *
     * Kunduzgi talaba ayni paytda rasmiy ishlasa — u `edu_higher` da,
     * `emp_gov` da EMAS. Aks holda talabalar soni kam ko'rsatilardi.
     */
    public function test_education_beats_employment(): void
    {
        $r = $this->resolver->resolve(
            ['q12' => 'oliy', 'q11' => 'rasmiy_davlat', 'q13' => 'yoq'],
            21,
        );

        $this->assertSame('edu_higher', $r->balanceRow);
    }

    /**
     * Rasmiy bandlik migratsiyadan USTUN.
     *
     * Bu chegara holati: rasmiy ishlaydigan, lekin ichki migratsiyada
     * bo'lgan ayol YASHIL qoladi (16-qadam 15-qadamdan keyin turadi).
     */
    public function test_formal_employment_beats_migration(): void
    {
        $r = $this->resolver->resolve(
            ['q11' => 'rasmiy_xususiy', 'q12' => 'yoq', 'q13' => 'ichki'],
            30,
        );

        $this->assertSame('emp_private', $r->balanceRow);
        $this->assertTrue($r->isGreen());
    }

    /**
     * Migratsiya sariq bandlikdan USTUN.
     *
     * Ishsiz + migratsiyada bo'lgan ayol `yel_migration` da, `yel_unemployed`
     * da EMAS — 16-qadam 20-qadamdan oldin.
     */
    public function test_migration_beats_yellow_employment(): void
    {
        $r = $this->resolver->resolve(
            ['q11' => 'ishsiz', 'q12' => 'yoq', 'q13' => 'mavsumiy'],
            33,
        );

        $this->assertSame('yel_migration', $r->balanceRow);
    }

    /** Sariq bandlik abituriyentdan ustun (22-qadam eng oxirida). */
    public function test_yellow_employment_beats_applicant(): void
    {
        $r = $this->resolver->resolve(
            ['q11' => 'norasmiy_band', 'q12' => 'abituriyent', 'q13' => 'yoq'],
            18,
        );

        $this->assertSame('yel_informal', $r->balanceRow);
    }

    // ---------------------------------------------------------------
    // BUZILMAS QOIDA (promt §1.1)
    // ---------------------------------------------------------------

    /**
     * YASHIL ∩ SARIQ = ∅ va har javob QAT'IY BITTA qatorga tushadi.
     *
     * Zinapoyaning har qadami uchun bittadan namuna yuritiladi va natija
     * to'plami tekshiriladi: 22 ta har xil qator, takror YO'Q.
     */
    public function test_green_and_yellow_never_overlap(): void
    {
        $rows = [];
        $categories = [];

        foreach (self::ladderProvider() as $case) {
            [, $age, $answers] = $case;
            $r = $this->resolver->resolve($answers, $age);

            $this->assertNotNull($r->balanceRow);
            $rows[] = $r->balanceRow;
            $categories[$r->balanceRow] = $r->category;
        }

        $this->assertCount(22, $rows);
        $this->assertSame(22, count(array_unique($rows)), 'Ikki qadam bir xil qatorga tushdi.');
        $this->assertCount(15, array_filter($categories, fn ($c) => $c === 'green'));
        $this->assertCount(7, array_filter($categories, fn ($c) => $c === 'yellow'));
    }

    /** Toifa faqat `green`, `yellow` yoki `incomplete` bo'lishi mumkin. */
    public function test_category_is_never_red(): void
    {
        foreach (self::ladderProvider() as $case) {
            [, $age, $answers] = $case;
            $category = $this->resolver->resolve($answers, $age)->category;

            $this->assertContains($category, [CategoryResolution::GREEN, CategoryResolution::YELLOW]);
            $this->assertNotSame('red', $category, 'Qizil ALOHIDA toifa emas — u ustma-ust belgi.');
        }
    }

    // ---------------------------------------------------------------
    // QIZIL BELGILAR
    // ---------------------------------------------------------------

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function redFlagProvider(): array
    {
        return [
            'surunkali kasallik' => [['q27' => true], 'chronic_illness'],
            'ajrashgan' => [['q7' => 'ajrashgan'], 'divorced_widowed'],
            'beva' => [['q7' => 'beva'], 'divorced_widowed'],
            'nizoli oila' => [['q9' => 'ha'], 'conflict_family'],
            'ijtimoiy reestr' => [['q23' => 1], 'social_registry'],
            'aliment muammosi' => [['q10' => true], 'alimony_problem'],
            'zo‘ravonlik' => [['q31' => ['violence' => true]], 'violence_victim'],
            'himoya orderi' => [['q31' => ['protection_order' => true]], 'protection_order'],
            'voyaga yetmagan ona' => [['q31' => ['minor_mother' => true]], 'minor_mother'],
            'probatsiya' => [['q30' => ['probation' => true]], 'probation'],
            'profilaktika' => [['q30' => ['prevention' => true]], 'prevention_record'],
            'narkologiya' => [['q30' => ['narcology' => true]], 'narcology_record'],
            'yot g‘oya' => [['q31' => ['alien_ideology' => true]], 'alien_ideology'],
            'odam savdosi' => [['q31' => ['human_trafficking' => true]], 'human_trafficking'],
        ];
    }

    /** @param array<string, mixed> $answers */
    #[DataProvider('redFlagProvider')]
    public function test_red_flag_detected(array $answers, string $code): void
    {
        $this->assertContains($code, array_column($this->resolver->redFlagsFor($answers), 'code'));
    }

    /** Belgilarsiz anketada qizil belgi CHIQMAYDI. */
    public function test_no_red_flags_when_clean(): void
    {
        $this->assertSame([], $this->resolver->redFlagsFor(['q11' => 'rasmiy_davlat', 'q7' => 'turmushda']));
    }

    /**
     * Bitta ayolda 5 tagacha belgi bo'lishi mumkin — cheklov YO'Q.
     */
    public function test_multiple_red_flags_coexist(): void
    {
        $flags = $this->resolver->redFlagsFor([
            'q7' => 'ajrashgan',
            'q9' => 'ha',
            'q23' => true,
            'q27' => true,
            'q31' => ['violence' => true, 'protection_order' => true],
        ]);

        $this->assertCount(6, $flags);
    }

    /**
     * QIZIL ⊆ (YASHIL ∪ SARIQ) — belgi TOIFANI O'ZGARTIRMAYDI.
     *
     * Norasmiy band, zo'ravonlik qurboni ayol `yel_informal` qatorida
     * QOLADI. Aks holda qizil alohida bo'lakka aylanib, `yashil + sariq =
     * jami` tengligi buzilardi.
     */
    public function test_red_flags_do_not_change_category(): void
    {
        $base = ['q11' => 'norasmiy_band', 'q12' => 'yoq', 'q13' => 'yoq'];
        $flagged = $base + ['q27' => true, 'q31' => ['violence' => true], 'q30' => ['probation' => true]];

        $clean = $this->resolver->resolve($base, 31);
        $risky = $this->resolver->resolve($flagged, 31);

        $this->assertSame($clean->category, $risky->category);
        $this->assertSame($clean->balanceRow, $risky->balanceRow);
        $this->assertCount(3, $risky->redFlags);
    }

    /** Turli manbadan kelgan «ha» bir xil tushuniladi. */
    public function test_truthy_accepts_bool_int_and_string(): void
    {
        foreach ([true, 1, 'ha', 'HA', 'true', 'ҳа'] as $value) {
            $this->assertCount(1, $this->resolver->redFlagsFor(['q27' => $value]), "«{$value}» ha deb tushunilmadi.");
        }

        foreach ([false, 0, 'yoq', '', null] as $value) {
            $this->assertCount(0, $this->resolver->redFlagsFor(['q27' => $value]));
        }
    }

    // ---------------------------------------------------------------
    // YOSH
    // ---------------------------------------------------------------

    public function test_age_groups(): void
    {
        $this->assertSame('0_2', $this->resolver->ageGroup(0));
        $this->assertSame('0_2', $this->resolver->ageGroup(2));
        $this->assertSame('3_6', $this->resolver->ageGroup(3));
        $this->assertSame('3_6', $this->resolver->ageGroup(6));
        $this->assertSame('7_17', $this->resolver->ageGroup(7));
        $this->assertSame('7_17', $this->resolver->ageGroup(17));
        $this->assertSame('18_up', $this->resolver->ageGroup(18));
        $this->assertSame('18_up', $this->resolver->ageGroup(95));
    }

    /**
     * Tug'ilgan kuni HALI KELMAGAN — yosh bir kam.
     *
     * Yilni ayirish (2026−2008 = 18) bu qizni 18 yoshli deb ko'rsatib, unga
     * V bo'lim (ijtimoiy nazorat) savollarini ochib yuborardi.
     */
    public function test_age_respects_birthday_not_yet_reached(): void
    {
        $at = Carbon::parse('2026-08-29');

        $this->assertSame(17, $this->resolver->ageAt(Carbon::parse('2008-12-31'), $at));
        $this->assertSame(18, $this->resolver->ageAt(Carbon::parse('2008-08-29'), $at));
        $this->assertSame(18, $this->resolver->ageAt(Carbon::parse('2008-01-01'), $at));
    }

    public function test_invalid_age_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->resolver->resolve([], 121);
    }

    // ---------------------------------------------------------------
    // IZ (trace) — «Toifa qanday aniqlandi» bloki uchun
    // ---------------------------------------------------------------

    /**
     * Iz mos kelgan qadamgacha BARCHA tekshiruvlarni saqlaydi.
     *
     * Faqat g'olib qadamni saqlash yetmaydi: foydalanuvchi «nega yashil
     * emas?» deb so'raganda, qaysi shartlar tekshirilib o'tkazib
     * yuborilgani ko'rsatilishi kerak.
     */
    public function test_trace_records_every_evaluated_step(): void
    {
        $r = $this->resolver->resolve(['q11' => 'ishsiz', 'q12' => 'yoq', 'q13' => 'yoq'], 27);

        $this->assertSame(20, $r->step);
        $this->assertCount(20, $r->trace, 'Iz 20-qadamgacha barcha tekshiruvni saqlashi kerak.');
        $this->assertFalse($r->trace[0]['matched']);
        $this->assertTrue($r->trace[19]['matched']);
        $this->assertSame('yel_unemployed', $r->trace[19]['balance_row']);
    }

    /** `incomplete` da iz TO'LIQ 22 qadamni saqlaydi. */
    public function test_trace_is_complete_when_incomplete(): void
    {
        $r = $this->resolver->resolve([], 25);

        $this->assertCount(22, $r->trace);
        $this->assertSame([], array_filter(array_column($r->trace, 'matched')));
    }

    /** Qoida versiyasi natijaga yoziladi — keyin qайta hisoblashda taqqoslash uchun. */
    public function test_resolution_carries_rules_version(): void
    {
        $this->assertSame(Rules::version(), $this->resolver->resolve([], 25)->rulesVersion);
    }
}
