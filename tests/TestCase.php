<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * XAVFSIZLIK DARVOZASI.
     *
     * Testlar UMUMIY dev bazasida (kbt) yuradi — alohida test bazasi yaratish
     * uchun `kbt` foydalanuvchisida CREATEDB huquqi yo'q, ustiga mahalla.houses
     * da master schema'siga tashqi kalitlar bor (fikstura commit qilingan
     * bo'lishi shart).
     *
     * `RefreshDatabase` / `DatabaseMigrations` / `DatabaseTruncation` bazani
     * O'CHIRADI. Agar kimdir ularni qo'shsa, bu darvoza testni darhol
     * to'xtatadi — dev ma'lumoti yo'qolgandan KEYIN emas, undan OLDIN.
     *
     * Tekshiruv parent::setUp() dan OLDIN turishi shart: trait'lar aynan
     * parent::setUp() ichida ishga tushadi.
     */
    protected function setUp(): void
    {
        // ISHLAB CHIQARISHDA UMUMAN YURMAYDI.
        //
        // `phpunit.xml` bazani ataylab qotirmaydi — testlar `.env` dagi bazani
        // ishlatadi (lokalda bu dev bazasi). Ammo bir xil kod serverda ham
        // turadi, u yerda esa `.env` PROD bazasini ko'rsatadi. Ya'ni serverda
        // tasodifan `php artisan test` yozilsa, testlar ishlab chiqarish
        // ma'lumotiga yozardi.
        //
        // DIQQAT: `env('APP_ENV')` bu yerda YARAMAYDI — `phpunit.xml` uni
        // `testing` deb ustidan yozadi, ya'ni serverda ham `testing` qaytaradi.
        // Shuning uchun haqiqiy muhit `.env` faylidan bevosita o'qiladi.
        if ($this->deploymentEnv() === 'production') {
            $this->fail(
                'Тестлар ишлаб чиқариш муҳитида юритилмайди: улар .env даги '.
                'базага ёзади. Тестларни фақат локал муҳитда юритинг.'
            );
        }

        $forbidden = [RefreshDatabase::class, DatabaseMigrations::class, DatabaseTruncation::class];
        $used = class_uses_recursive(static::class);

        foreach ($forbidden as $trait) {
            if (in_array($trait, $used, true)) {
                $this->fail(
                    class_basename($trait)." ishlatib bo'lmaydi: testlar umumiy dev bazasida ".
                    "yuradi va bu trait butun bazani o'chiradi. `DatabaseTransactions` ishlating."
                );
            }
        }

        parent::setUp();
    }

    /**
     * Haqiqiy joylashtirish muhiti — `.env` faylidan bevosita, `phpunit.xml`
     * ustidan yozgan qiymatni chetlab o'tib.
     */
    private function deploymentEnv(): string
    {
        $path = dirname(__DIR__).'/.env';
        if (! is_file($path)) {
            return 'unknown';
        }

        $raw = (string) file_get_contents($path);

        // `\r` ni ham tozalash SHART: CRLF qatorlarda `(.*)` uni ushlab qoladi
        // va "production\r" === "production" solishtiruvi jimgina false beradi,
        // ya'ni darvoza ishlayotgandek ko'rinib, aslida hech qachon yopilmaydi.
        return preg_match('/^APP_ENV=(.*)$/m', $raw, $m) === 1
            ? trim($m[1], " \t\r\n\"'")
            : 'unknown';
    }
}
