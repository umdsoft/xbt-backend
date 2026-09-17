<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\MobileAuthController;
use App\Support\Auth\LoginThrottle;
use Illuminate\Support\Facades\Route;

/*
 * Markaziy identifikatsiya (Sanctum SPA). Barcha domen modullari shu API ostida.
 */
/*
 * Kirish cheklovi — `LoginThrottle` (HISOB bo'yicha, IP bo'yicha EMAS).
 *
 * Avvalgi `throttle:5,1` faqat IPni sanardi va bitta idoradagi
 * o'nlab faolni bir-birining xatosi uchun bloklardi. Sabab va uch
 * qatlamli yangi qoida `app/Support/Auth/LoginThrottle.php` da.
 */
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:'.LoginThrottle::NAME)
    ->name('api.login');

// Mobil (Sanctum API token) login — SPA sessiyadan alohida, xuddi shu qoida.
Route::post('/mobile/login', [MobileAuthController::class, 'login'])
    ->middleware('throttle:'.LoginThrottle::NAME)
    ->name('api.mobile.login');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me'])->name('api.me');
    Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');
    Route::post('/mobile/logout', [MobileAuthController::class, 'logout'])->name('api.mobile.logout');

    // Parolni o'zgartirish (markaziy — barcha tizimlar). throttle:6,1 — brute-force
    // himoyasi (jorij parolni topishga urinishlarni cheklaydi).
    Route::post('/change-password', [AuthController::class, 'changePassword'])
        ->middleware('throttle:6,1')
        ->name('api.change-password');
});

// Domen modullari
require __DIR__.'/api/mahalla.php';
require __DIR__.'/api/hr.php';
require __DIR__.'/api/advisor.php';
require __DIR__.'/api/murojaat.php';
require __DIR__.'/api/sport.php';
require __DIR__.'/api/qurilish.php';

require __DIR__.'/api/yoshlar.php';
require __DIR__.'/api/ayollar.php';
