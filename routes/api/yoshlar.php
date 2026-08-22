<?php

declare(strict_types=1);

use App\Domains\Yoshlar\Http\Controllers\Api\ContextController;
use Illuminate\Support\Facades\Route;

/*
 * YOSHLAR domeni API. auth:sanctum + yoshlar gvardiyasi.
 * Auth-siz -> 401; rolsiz -> 403. Rol ichidagi doira YoshlarScope da.
 */
Route::middleware(['auth:sanctum', 'yoshlar'])
    ->prefix('yoshlar')
    ->name('api.yoshlar.')
    ->group(function () {
        // SPA boshlanish konteksti: rol, ruxsat, doira, spravochniklar.
        Route::get('/context', ContextController::class)->name('context');
    });
