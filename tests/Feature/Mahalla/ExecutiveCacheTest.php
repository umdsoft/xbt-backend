<?php

declare(strict_types=1);

namespace Tests\Feature\Mahalla;

use App\Domains\Mahalla\Support\ExecutiveCache;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * Кеш эскирган маълумот кўрсатмаслиги керак.
 *
 * Keshning butun xavfi shunda: import bo'ldi, lekin panel eski raqamni
 * ko'rsatmoqda. Bunday xato jimgina bo'ladi — sahifa ochiladi, raqam turadi,
 * faqat u noto'g'ri. Shuning uchun tozalash mexanizmi test bilan mahkamlanadi.
 */
class ExecutiveCacheTest extends TestCase
{
    use DatabaseTransactions;

    public function test_remember_returns_cached_value_until_flushed(): void
    {
        $calls = 0;
        $fn = function () use (&$calls) {
            $calls++;

            return "hisoblandi-{$calls}";
        };

        $this->assertSame('hisoblandi-1', ExecutiveCache::remember('sinov', $fn));
        $this->assertSame('hisoblandi-1', ExecutiveCache::remember('sinov', $fn));
        $this->assertSame(1, $calls, 'Ikkinchi chaqiruv keshdan olinishi kerak');

        ExecutiveCache::flush();

        $this->assertSame('hisoblandi-2', ExecutiveCache::remember('sinov', $fn));
        $this->assertSame(2, $calls, 'flush() dan keyin qayta hisoblanishi kerak');
    }

    /**
     * `mahalla:*` buyruq tugagach kesh o'zi tozalanadi.
     *
     * Har importga qo'lda `flush()` yozish o'rniga shu tanlangan edi —
     * yangi buyruq yozganda unutish oson. Shu bog'lanish uzilib qolsa
     * import ishlaydi, lekin natijasi bir kun ko'rinmaydi.
     */
    public function test_mahalla_command_flushes_cache(): void
    {
        $calls = 0;
        $fn = function () use (&$calls) {
            $calls++;

            return $calls;
        };

        ExecutiveCache::remember('sinov', $fn);
        ExecutiveCache::remember('sinov', $fn);
        $this->assertSame(1, $calls);

        Event::dispatch(new CommandFinished(
            'mahalla:import-indicators', new ArrayInput([]), new NullOutput, 0
        ));

        ExecutiveCache::remember('sinov', $fn);
        $this->assertSame(2, $calls, 'mahalla: buyrug\'i keshni tozalashi kerak');
    }

    /** Muvaffaqiyatsiz buyruq keshni tozalamaydi — hisoblash behuda bo'lardi. */
    public function test_failed_command_does_not_flush(): void
    {
        $calls = 0;
        $fn = function () use (&$calls) {
            $calls++;

            return $calls;
        };

        ExecutiveCache::remember('sinov', $fn);

        Event::dispatch(new CommandFinished(
            'mahalla:import-indicators', new ArrayInput([]), new NullOutput, 1
        ));

        ExecutiveCache::remember('sinov', $fn);
        $this->assertSame(1, $calls);
    }

    /** Boshqa domendagi buyruq rahbariyat keshiga tegmaydi. */
    public function test_unrelated_command_does_not_flush(): void
    {
        $calls = 0;
        $fn = function () use (&$calls) {
            $calls++;

            return $calls;
        };

        ExecutiveCache::remember('sinov', $fn);

        Event::dispatch(new CommandFinished('migrate', new ArrayInput([]), new NullOutput, 0));

        ExecutiveCache::remember('sinov', $fn);
        $this->assertSame(1, $calls);
    }
}
