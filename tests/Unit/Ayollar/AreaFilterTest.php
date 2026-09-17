<?php

declare(strict_types=1);

namespace Tests\Unit\Ayollar;

use App\Domains\Ayollar\Services\DailyChange;
use App\Domains\Ayollar\Support\AreaFilter;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * HUDUD FILTRI VA DAVR — YAROQSIZ QIYMATDA 500 BO'LMASIN.
 *
 * 2026-09-17 da tekshirilganda hudud filtri qabul qiladigan BARCHA
 * endpoint yaroqsiz UUIDda 500 berardi (`/anketas?district_id=yoq`,
 * `/analytics/needs`, `/analytics/daily`, `/export/registry`).
 * Sababi: ustun PostgreSQLda `uuid` turida va SQL o'zi xato berardi.
 *
 * Bu test shakl tekshiruvini qulflaydi.
 */
class AreaFilterTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function yaroqsizProvider(): array
    {
        return [
            'oddiy soz' => ['yoq'],
            'son' => ['123'],
            'qisqa' => ['abc-def'],
            'chiziqsiz' => ['0192a3b4c5d6789e0f1234567890abcd'],
            'inyeksiya' => ["' or '1'='1"],
            'bosh joy' => ['  '],
        ];
    }

    #[DataProvider('yaroqsizProvider')]
    public function test_yaroqsiz_uuid_qabul_qilinmaydi(string $value): void
    {
        $request = Request::create('/x', 'GET', ['district_id' => $value]);

        [$given, $parsed] = AreaFilter::read($request, 'district_id');

        // Berilgan, LEKIN yaroqsiz — ikkalasini ajratish muhim:
        // «berilmagan» filtrsiz ro'yxat, «yaroqsiz» esa bo'sh ro'yxat.
        $this->assertTrue($given || trim($value) === '');
        $this->assertNull($parsed);
    }

    public function test_haqiqiy_uuid_otadi(): void
    {
        $uuid = '0199f3e4-5a6b-7c8d-9e0f-123456789abc';
        $request = Request::create('/x', 'GET', ['district_id' => $uuid]);

        $this->assertSame([true, $uuid], AreaFilter::read($request, 'district_id'));
    }

    public function test_berilmagan_filtr_bosh_qoladi(): void
    {
        $this->assertSame([false, null], AreaFilter::read(Request::create('/x'), 'district_id'));
    }

    /** @return array<string, array{mixed, int}> */
    public static function davrProvider(): array
    {
        return [
            'berilmagan -> 7' => [null, 7],
            'oddiy' => ['14', 14],
            'chegaradan katta -> 60' => ['999', 60],
            'nol -> 7' => ['0', 7],
            'manfiy -> 7' => ['-5', 7],
            'harf -> 7' => ['abc', 7],
            'bitta kun -> 2' => ['1', 2],
        ];
    }

    /**
     * Ma'nosiz `days` DEFAULTga qaytsin, eng kichik chegaraga emas.
     *
     * Avval `days=abc` ikki ustunli jadval berardi: `integer()` uni 0
     * deb o'qir, keyin `max(2, …)` uni 2 ga ko'tarardi. Foydalanuvchi
     * 7 kunlik ekranni kutib, sababsiz qisqargan jadvalni olardi.
     */
    #[DataProvider('davrProvider')]
    public function test_davr_chegaralari(mixed $input, int $kutilgan): void
    {
        $query = $input === null ? [] : ['days' => $input];

        $this->assertSame($kutilgan, DailyChange::days(Request::create('/x', 'GET', $query)));
    }
}
