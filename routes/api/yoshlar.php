<?php

declare(strict_types=1);

use App\Domains\Yoshlar\Http\Controllers\Api\ContextController;
use App\Domains\Yoshlar\Http\Controllers\Api\YouthController;
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

        // Yoshlar reyestri. DIQQAT: `/youth/stats` `{youth}` dan OLDIN —
        // aks holda «stats» so'zi parametr sifatida ushlanadi.
        Route::get('/youth/stats', [YouthController::class, 'stats'])->name('youth.stats');
        Route::get('/youth', [YouthController::class, 'index'])->name('youth.index');
        Route::post('/youth', [YouthController::class, 'store'])->name('youth.store');
        Route::get('/youth/{youth}', [YouthController::class, 'show'])->name('youth.show');
        Route::patch('/youth/{youth}', [YouthController::class, 'update'])->name('youth.update');
        Route::delete('/youth/{youth}', [YouthController::class, 'destroy'])->name('youth.destroy');

        // Tasdiqlash sikli (reyestrga yoshlar vertikali egalik qiladi).
        Route::post('/youth/{youth}/verify', [YouthController::class, 'verify'])->name('youth.verify');
        Route::post('/youth/{youth}/reject', [YouthController::class, 'reject'])->name('youth.reject');

        // Maxfiy maydonni ochish — har chaqiruv jurnalga tushadi.
        Route::post('/youth/{youth}/reveal-pii', [YouthController::class, 'revealPii'])->name('youth.reveal_pii');
    });
