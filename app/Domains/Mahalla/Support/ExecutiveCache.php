<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Секин ўзгарадиган маълумот учун кеш.
 *
 * Rahbariyat paneli ikki xil ma'lumotni aralashtiradi:
 *
 *   TEZ o'zgaradi — kuzatuv soni, o'zgargan xonadonlar. Har surat
 *                   yuklanganda yangilanadi, keshlanmaydi.
 *   SEKIN o'zgaradi — chegaralar, kadastr binolari, ijtimoiy obyektlar,
 *                     statistik ko'rsatkichlar. Import bilan, ya'ni oyiga
 *                     bir-ikki marta o'zgaradi.
 *
 * Faqat ikkinchisi keshlanadi. Birinchisini keshlash panelni yolg'onchi
 * qiladi: rais surat yuklaydi, rahbar esa eski raqamni ko'radi.
 *
 * NEGA VERSIYA, TEG EMAS: `database` kesh drayveri teglarni
 * (`Cache::tags()`) qo'llab-quvvatlamaydi. Versiya raqami har kalitga
 * qo'shiladi; `flush()` uni bittaga oshiradi va butun to'plam bir zumda
 * eskiradi — eski yozuvlarni birma-bir o'chirish shart emas, ular TTL
 * bilan o'z-o'zidan yo'qoladi.
 */
class ExecutiveCache
{
    private const VERSION_KEY = 'mahalla:ref:v';

    /** Bir kun. Import `flush()` chaqirgani uchun TTL faqat zaxira chora. */
    private const TTL_SECONDS = 86400;

    /**
     * Versiya raqami request davomida bir marta o'qiladi (memoizatsiya).
     * `database` kesh drayverida har `remember()` ilgari 2× cache so'rov qilardi
     * (versiya + qiymat); endi versiya so'rovi bir marta. `flush()` uni yangilaydi.
     */
    private static ?int $versionMemo = null;

    /**
     * @template T
     *
     * @param  Closure(): T  $fn
     * @return T
     */
    public static function remember(string $key, Closure $fn): mixed
    {
        return Cache::remember(self::key($key), self::TTL_SECONDS, $fn);
    }

    /**
     * Ma'lumotnoma o'zgargach chaqiriladi (import, nom o'zgartirish).
     *
     * Versiyani oshiradi — keyingi so'rov yangi kalitni topa olmaydi va
     * qaytadan hisoblaydi.
     */
    public static function flush(): void
    {
        $next = self::version() + 1;
        Cache::forever(self::VERSION_KEY, $next);
        self::$versionMemo = $next; // memoni ham yangilaymiz (shu request'da eskirmasin)
    }

    private static function key(string $key): string
    {
        return 'mahalla:ref:'.self::version().':'.$key;
    }

    private static function version(): int
    {
        return self::$versionMemo ??= (int) Cache::rememberForever(self::VERSION_KEY, fn () => 1);
    }
}
