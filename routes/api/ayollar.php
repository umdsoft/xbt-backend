<?php

declare(strict_types=1);

use App\Domains\Ayollar\Http\Controllers\Api\ContextController;
use Illuminate\Support\Facades\Route;

/*
 * AYOLLAR BALANSI domeni API. auth:sanctum + `ayollar` gvardiyasi.
 * Auth-siz -> 401; rolsiz -> 403.
 *
 * Domen endpoint'lari texnik talab kelganda shu guruh ichiga qo'shiladi —
 * guruhdan TASHQARIDA yozilgan route gvardiyani chetlab o'tadi.
 */
Route::middleware(['auth:sanctum', 'ayollar'])
    ->prefix('ayollar')
    ->name('api.ayollar.')
    ->group(function () {
        // SPA boshlanish konteksti: rol, ruxsat, spravochniklar.
        Route::get('/context', ContextController::class)->name('context');
    });
