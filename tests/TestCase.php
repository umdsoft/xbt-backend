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
}
