<?php

declare(strict_types=1);

namespace Tests\Feature\Qurilish;

use Illuminate\Support\Str;

/**
 * Obyekt ustida ishlaydigan testlar uchun poydevor.
 *
 * MUAMMO: testlar UMUMIY dev bazasida yuradi va u yerda haqiqiy import
 * ma'lumoti (611 obyekt) turadi. `DatabaseTransactions` faqat testning O'Z
 * yozuvlarini qaytaradi — mavjud qatorlarni yashira olmaydi. Ya'ni
 * `/objects` ro'yxati 611 ta begona obyektni ham qaytaradi va
 * `meta.total` bo'yicha assertion beqaror bo'lardi.
 *
 * YECHIM: har test uchun noyob prefiks. Obyekt nomlari shu prefiks bilan
 * boshlanadi, so'rovlar esa `?q=<prefiks>` bilan filtrlanadi — natijada test
 * faqat o'zi yaratgan ma'lumotni ko'radi.
 */
abstract class QurilishObjectTestCase extends QurilishTestCase
{
    protected string $prefix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'ZZT'.Str::upper(Str::random(6));
    }

    /** Test doirasidagi noyob obyekt nomi. */
    protected function tag(string $suffix): string
    {
        return $this->prefix.'-'.$suffix;
    }
}
