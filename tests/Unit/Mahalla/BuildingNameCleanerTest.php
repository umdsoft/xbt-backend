<?php

declare(strict_types=1);

namespace Tests\Unit\Mahalla;

use App\Domains\Mahalla\Support\BuildingNameCleaner;
use PHPUnit\Framework\TestCase;

/**
 * B2: `master.buildings.purpose` (kadastr XOM matni) dan ko'rsatish nomini
 * chiqarish qoidalari. DB kerak emas — sof funksiya, shuning uchun bu yerda
 * Laravel emas, ODDIY PHPUnit TestCase'dan foydalaniladi.
 *
 * Reja hujjatidagi (`docs/superpowers/plans/2026-09-14-atrof-aniqlik.md`,
 * Task B2) aniq misollar: «ЙИЛКИЧИ БОБО КАБРИСТОНИ» va «Шовот деҳқон бозори».
 */
class BuildingNameCleanerTest extends TestCase
{
    public function test_strips_surrounding_ascii_quotes(): void
    {
        $this->assertSame(
            'Шовот деҳқон бозори',
            BuildingNameCleaner::clean('"Шовот деҳқон бозори"'),
        );
    }

    public function test_strips_surrounding_guillemets(): void
    {
        $this->assertSame(
            'Дўкон биноси',
            BuildingNameCleaner::clean('«Дўкон биноси»'),
        );
    }

    public function test_strips_surrounding_single_quotes_and_whitespace_together(): void
    {
        $this->assertSame(
            'Устахона',
            BuildingNameCleaner::clean("  '  Устахона  '  "),
        );
    }

    /**
     * Faqat CHETDAGI tirnoq olib tashlanadi — matn ICHIDAGI tirnoqqa
     * tegilmaydi (haqiqiy kadastr yozuvida ko'p uchraydigan holat:
     * `"Дукон"биноси` — faqat "Дукон" so'zi tirnoqqa olingan).
     */
    public function test_does_not_touch_quotes_in_the_middle_of_the_text(): void
    {
        $this->assertSame(
            'Дукон"биноси',
            BuildingNameCleaner::clean('"Дукон"биноси'),
        );
    }

    public function test_collapses_runs_of_internal_whitespace_to_a_single_space(): void
    {
        $this->assertSame(
            'Ишлаб чиқариш биноси',
            BuildingNameCleaner::clean("Ишлаб    чиқариш\tбиноси"),
        );
    }

    public function test_whitespace_only_text_becomes_null(): void
    {
        $this->assertNull(BuildingNameCleaner::clean('   '));
    }

    public function test_quotes_only_text_becomes_null(): void
    {
        $this->assertNull(BuildingNameCleaner::clean('""'));
        $this->assertNull(BuildingNameCleaner::clean('«»'));
    }

    public function test_null_input_returns_null(): void
    {
        $this->assertNull(BuildingNameCleaner::clean(null));
    }

    public function test_empty_string_returns_null(): void
    {
        $this->assertNull(BuildingNameCleaner::clean(''));
    }

    /**
     * MUHIM INVARIANT: harf registri HECH QACHON o'zgartirilmaydi. Manba
     * ma'lumoti — kadastrning rasmiy yozuvi; pastga tushirish qaytarib
     * bo'lmaydigan ma'lumot yo'qotadi. Ko'rsatish uslubi mobil ilova ishi.
     */
    public function test_preserves_upper_case_exactly(): void
    {
        $this->assertSame(
            'ЙИЛКИЧИ БОБО КАБРИСТОНИ',
            BuildingNameCleaner::clean('ЙИЛКИЧИ БОБО КАБРИСТОНИ'),
        );
    }
}
