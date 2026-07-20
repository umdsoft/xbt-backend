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
        // AI (Anthropic) tahlil navbati uchun rate-limit (daqiqadagi so'rov).
        // AnalyzePhotoJob'dagi RateLimited('mahalla-ai') shu limiterни ishlatadi —
        // limit oshsa job avtomatik kechiktirilib qayta navbatga qo'yiladi.
        RateLimiter::for('mahalla-ai', fn () => Limit::perMinute((int) config('mahalla.ai.rpm', 50)));

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
