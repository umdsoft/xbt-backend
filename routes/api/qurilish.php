<?php

declare(strict_types=1);

use App\Domains\Qurilish\Http\Controllers\Api\AdminController;
use App\Domains\Qurilish\Http\Controllers\Api\AuditController;
use App\Domains\Qurilish\Http\Controllers\Api\ContextController;
use App\Domains\Qurilish\Http\Controllers\Api\DashboardController;
use App\Domains\Qurilish\Http\Controllers\Api\DocumentController;
use App\Domains\Qurilish\Http\Controllers\Api\ExportController;
use App\Domains\Qurilish\Http\Controllers\Api\MediaController;
use App\Domains\Qurilish\Http\Controllers\Api\MonthlyController;
use App\Domains\Qurilish\Http\Controllers\Api\ObjectController;
use App\Domains\Qurilish\Http\Controllers\Api\RepairNeedController;
use App\Domains\Qurilish\Http\Controllers\Api\StageController;
use App\Domains\Qurilish\Http\Controllers\Api\WeeklyController;
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

        // Bosqich workflow — XNP TZ v2.0 moderatsiya sikli.
        // `/moderation/queue` {object} marshrutlaridan mustaqil: u obyektga
        // emas, foydalanuvchining butun navbatiga tegishli.
        Route::get('/moderation/queue', [StageController::class, 'queue'])->name('stages.queue');
        Route::get('/objects/{object}/stages', [StageController::class, 'index'])->name('stages.index');
        Route::patch('/objects/{object}/stages/{stage}', [StageController::class, 'update'])->name('stages.update');
        Route::post('/objects/{object}/stages/{stage}/submit', [StageController::class, 'submit'])->name('stages.submit');
        Route::post('/objects/{object}/stages/{stage}/review', [StageController::class, 'review'])->name('stages.review');
        Route::post('/objects/{object}/stages/{stage}/approve', [StageController::class, 'approve'])->name('stages.approve');
        Route::post('/objects/{object}/stages/{stage}/reject', [StageController::class, 'reject'])->name('stages.reject');
        Route::post('/objects/{object}/stages/{stage}/reopen', [StageController::class, 'reopen'])->name('stages.reopen');
        Route::post('/objects/{object}/stages/{stage}/not-required', [StageController::class, 'notRequired'])->name('stages.not_required');

        // Oylik ijro grafigi.
        Route::get('/objects/{object}/monthly', [MonthlyController::class, 'index'])->name('monthly.index');
        Route::put('/objects/{object}/monthly', [MonthlyController::class, 'upsert'])->name('monthly.upsert');

        // Bosqich dalillari — surat va video.
        Route::get('/objects/{object}/media', [MediaController::class, 'index'])->name('media.index');
        Route::post('/objects/{object}/media', [MediaController::class, 'store'])->name('media.store');
        Route::get('/objects/{object}/media/{media}/file', [MediaController::class, 'show'])->name('media.file');
        Route::post('/objects/{object}/media/{media}/cover', [MediaController::class, 'setCover'])->name('media.cover');
        Route::delete('/objects/{object}/media/{media}', [MediaController::class, 'destroy'])->name('media.destroy');

        // Haftalik ijro hisoboti va arxivi.
        // `/weekly/queue` obyektga bog'lanmagan — u butun portfel navbati.
        Route::get('/weekly/queue', [WeeklyController::class, 'queue'])->name('weekly.queue');
        Route::get('/objects/{object}/weekly', [WeeklyController::class, 'index'])->name('weekly.index');
        Route::post('/objects/{object}/weekly', [WeeklyController::class, 'store'])->name('weekly.store');
        Route::post('/objects/{object}/weekly/{report}/submit', [WeeklyController::class, 'submit'])->name('weekly.submit');
        Route::post('/objects/{object}/weekly/{report}/review', [WeeklyController::class, 'review'])->name('weekly.review');
        Route::post('/objects/{object}/weekly/{report}/approve', [WeeklyController::class, 'approve'])->name('weekly.approve');
        Route::post('/objects/{object}/weekly/{report}/reject', [WeeklyController::class, 'reject'])->name('weekly.reject');

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

        /*
         * TIZIM MODERATORI ish o'rni. Alohida prefiks `/admin`: bu marshrutlar
         * obyekt ma'lumotiga emas, tizimning O'ZIGA tegishli va ularning
         * ruxsati ham boshqacha (`user.manage` / `reference.manage`).
         */
        Route::prefix('admin')->name('admin.')->group(function () {
            Route::get('/users', [AdminController::class, 'userIndex'])->name('users.index');
            Route::post('/users', [AdminController::class, 'userStore'])->name('users.store');
            Route::patch('/users/{user}', [AdminController::class, 'userUpdate'])->name('users.update');
            Route::post('/users/{user}/password', [AdminController::class, 'userResetPassword'])->name('users.password');
            Route::post('/users/{user}/active', [AdminController::class, 'userSetActive'])->name('users.active');

            Route::get('/programs', [AdminController::class, 'programIndex'])->name('programs.index');
            Route::post('/programs', [AdminController::class, 'programStore'])->name('programs.store');
            Route::patch('/programs/{program}', [AdminController::class, 'programUpdate'])->name('programs.update');
            Route::delete('/programs/{program}', [AdminController::class, 'programDestroy'])->name('programs.destroy');

            Route::get('/sectors', [AdminController::class, 'sectorIndex'])->name('sectors.index');
            Route::post('/sectors', [AdminController::class, 'sectorStore'])->name('sectors.store');
            Route::patch('/sectors/{sector}', [AdminController::class, 'sectorUpdate'])->name('sectors.update');
            Route::delete('/sectors/{sector}', [AdminController::class, 'sectorDestroy'])->name('sectors.destroy');

            Route::get('/organizations', [AdminController::class, 'organizationIndex'])->name('orgs.index');
            Route::post('/organizations', [AdminController::class, 'organizationSave'])->name('orgs.store');
            Route::patch('/organizations/{organization}', [AdminController::class, 'organizationSave'])->name('orgs.update');

            Route::get('/audit', [AdminController::class, 'auditIndex'])->name('audit');
        });
    });
