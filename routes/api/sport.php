<?php

declare(strict_types=1);

use App\Domains\Sport\Http\Controllers\Api\PublicTrainerController;
use Illuminate\Support\Facades\Route;

/*
 * SPORT domeni — ochiq (loginsiz) portal API'si.
 *
 * Trenerlar → mahalla qamrovi tahlili sport saytining OCHIQ portalida
 * ko'rsatiladi (hududlar-salomatligi kabi). PII qaytarilmaydi. Suiiste'moldan
 * himoya uchun throttle. Kelajakda /api/sport/... ostida auth'li resurslar
 * (murabbiylar CRUD, context) qo'shiladi.
 */
Route::prefix('sport/public')
    ->middleware('throttle:60,1')
    ->group(function () {
        Route::get('/trainer-coverage', [PublicTrainerController::class, 'overview'])
            ->name('api.sport.public.trainer-coverage');
        Route::get('/trainer-coverage/{district}', [PublicTrainerController::class, 'district'])
            ->name('api.sport.public.trainer-coverage.district');
    });
