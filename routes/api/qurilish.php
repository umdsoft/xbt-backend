<?php

declare(strict_types=1);

use App\Domains\Qurilish\Http\Controllers\Api\AuditController;
use App\Domains\Qurilish\Http\Controllers\Api\ContextController;
use App\Domains\Qurilish\Http\Controllers\Api\DashboardController;
use App\Domains\Qurilish\Http\Controllers\Api\DocumentController;
use App\Domains\Qurilish\Http\Controllers\Api\ExportController;
use App\Domains\Qurilish\Http\Controllers\Api\MonthlyController;
use App\Domains\Qurilish\Http\Controllers\Api\ObjectController;
use App\Domains\Qurilish\Http\Controllers\Api\RepairNeedController;
use App\Domains\Qurilish\Http\Controllers\Api\StageController;
use Illuminate\Support\Facades\Route;

/*
 * QURILISH domeni API (davlat dasturlari qurilish/ta'mirlash ijrosi).
 * auth:sanctum + qurilish gvardiyasi. Auth-siz -> 401; rolsiz -> 403.
 * Rol ichidagi vakolat (buyurtmachi/boshqarma scope) QurilishScope da,
 * aniq amal ruxsati QurilishController::authorizeAction da.
 */
Route::middleware(['auth:sanctum', 'qurilish'])
    ->prefix('qurilish')
    ->name('api.qurilish.')
    ->group(function () {
        // SPA boshlanish konteksti: rol, ruxsat, scope, spravochniklar.
        Route::get('/context', ContextController::class)->name('context');

        // Boshqaruv paneli (hokimlik/prokuratura) — СВОД pivotlar JONLI hisoblanadi.
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/dashboard/executive', [DashboardController::class, 'executive'])->name('dashboard.executive');
        Route::get('/dashboard/svod/{dimension}', [DashboardController::class, 'svod'])->name('dashboard.svod');
        Route::get('/dashboard/funnel', [DashboardController::class, 'funnel'])->name('dashboard.funnel');
        Route::get('/dashboard/map', [DashboardController::class, 'map'])->name('dashboard.map');

        // Excel eksport.
        Route::get('/export/svod/{dimension}', [ExportController::class, 'svod'])->name('export.svod');
        Route::get('/export/objects', [ExportController::class, 'objects'])->name('export.objects');

        // Obyekt reyestri.
        Route::get('/objects', [ObjectController::class, 'index'])->name('objects.index');
        Route::post('/objects', [ObjectController::class, 'store'])->name('objects.store');
        Route::get('/objects/{object}', [ObjectController::class, 'show'])->name('objects.show');
        Route::patch('/objects/{object}', [ObjectController::class, 'update'])->name('objects.update');

        // Bosqich workflow.
        Route::get('/objects/{object}/stages', [StageController::class, 'index'])->name('stages.index');
        Route::patch('/objects/{object}/stages/{stage}', [StageController::class, 'update'])->name('stages.update');

        // Oylik ijro grafigi.
        Route::get('/objects/{object}/monthly', [MonthlyController::class, 'index'])->name('monthly.index');
        Route::put('/objects/{object}/monthly', [MonthlyController::class, 'upsert'])->name('monthly.upsert');

        // Hujjatlar.
        Route::get('/objects/{object}/documents', [DocumentController::class, 'index'])->name('documents.index');
        Route::post('/objects/{object}/documents', [DocumentController::class, 'store'])->name('documents.store');
        Route::get('/objects/{object}/documents/{document}', [DocumentController::class, 'download'])->name('documents.download');
        Route::delete('/objects/{object}/documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');

        // Ta'mirtalab obyektlar reyestri (2-maqsad) + ПАСПОРТ jonli hisoboti.
        // DIQQAT: `/pasport` `{need}` dan OLDIN turishi shart, aks holda
        // 'pasport' so'zi {need} parametri sifatida ushlanib qolardi.
        Route::get('/repair-needs/pasport', [RepairNeedController::class, 'pasport'])->name('repair.pasport');
        Route::get('/repair-needs', [RepairNeedController::class, 'index'])->name('repair.index');
        Route::post('/repair-needs', [RepairNeedController::class, 'store'])->name('repair.store');
        Route::patch('/repair-needs/{need}', [RepairNeedController::class, 'update'])->name('repair.update');
        Route::post('/repair-needs/{need}/promote', [RepairNeedController::class, 'promote'])->name('repair.promote');

        // Audit jurnali.
        Route::get('/objects/{object}/audit', AuditController::class)->name('audit');
    });
