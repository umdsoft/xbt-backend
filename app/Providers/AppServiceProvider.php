<?php

namespace App\Providers;

use App\Domains\Mahalla\Support\ExecutiveCache;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // AI tahlil navbati uchun rate-limit (daqiqadagi so'rov). AnalyzeObservationJob'dagi
        // RateLimited('mahalla-ai') shu limiterни ishlatadi — limit oshsa job avtomatik
        // kechiktirilib qayta navbatga qo'yiladi.
        //
        // DRAYVER-BILAN-XABARDOR: bulut (claude) uchun Anthropic RPM cheklovi kerak, lekin
        // LAN'dagi lokal GPU (local) o'z tezligida ishlaydi — unga 50/min (=72k/kun < 138k
        // talab) navbatni bo'g'adi. driver=local bo'lsa yuqori rpm_local (default 6000)
        // ishlatiladi (amalda cheklamaydi).
        RateLimiter::for('mahalla-ai', function () {
            $driver = config('mahalla.ai.driver');
            $rpm = $driver === 'local'
                ? (int) config('mahalla.ai.rpm_local')
                : (int) config('mahalla.ai.rpm');

            return Limit::perMinute($rpm);
        });

        /*
         * Har qanday `mahalla:*` buyruq tugagach rahbariyat keshini tozalaydi.
         *
         * Har bir import buyrug'iga qo'lda `flush()` qo'shish mumkin edi, lekin
         * kelajakda yangi buyruq yozilganda uni UNUTISH oson — va oqibati
         * jimgina bo'ladi: yangi ma'lumot bir kun (TTL) ko'rinmaydi va hech
         * kim sababini bilmaydi.
         *
         * Shuning uchun teskari tomonga xato qilinadi: o'qish buyrug'idan
         * keyin ham tozalanadi. Ortiqcha tozalash — bir marta ~60 ms qayta
         * hisoblash, unutilgan tozalash esa noto'g'ri hisobot.
         */
        Event::listen(CommandFinished::class, function (CommandFinished $event): void {
            if ($event->exitCode === 0 && str_starts_with((string) $event->command, 'mahalla:')) {
                ExecutiveCache::flush();
            }
        });
    }
}
