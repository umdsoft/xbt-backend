<?php

declare(strict_types=1);

use App\Domains\Qurilish\Http\Controllers\Api\ContextController;
use Illuminate\Support\Facades\Route;

/*
 * QURILISH domeni API (davlat dasturlari qurilish/ta'mirlash ijrosi).
 * auth:sanctum + qurilish gvardiyasi. Auth-siz -> 401; rolsiz -> 403.
 * Rol ichidagi vakolat (buyurtmachi/boshqarma scope) QurilishScope da.
 */
Route::middleware(['auth:sanctum', 'qurilish'])
    ->prefix('qurilish')
    ->name('api.qurilish.')
    ->group(function () {
        // SPA boshlanish konteksti: rol, ruxsat, scope, spravochniklar.
        Route::get('/context', ContextController::class)->name('context');
    });
