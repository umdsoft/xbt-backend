<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\MobileAuthController;
use Illuminate\Support\Facades\Route;

/*
 * Markaziy identifikatsiya (Sanctum SPA). Barcha domen modullari shu API ostida.
 */
// throttle:5,1 — brute-force himoyasi (daqiqada 5 urinish, IP bo'yicha).
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1')
    ->name('api.login');

// Mobil (Sanctum API token) login — SPA sessiyadan alohida, xuddi shunday rate-limit.
Route::post('/mobile/login', [MobileAuthController::class, 'login'])
    ->middleware('throttle:5,1')
    ->name('api.mobile.login');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me'])->name('api.me');
    Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');
    Route::post('/mobile/logout', [MobileAuthController::class, 'logout'])->name('api.mobile.logout');
});

// Domen modullari
require __DIR__.'/api/mahalla.php';
require __DIR__.'/api/hr.php';
