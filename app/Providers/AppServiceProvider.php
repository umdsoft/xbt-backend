<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
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
    }
}
