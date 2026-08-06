<?php

declare(strict_types=1);

use App\Domains\Murojaat\Http\Controllers\Api\AppealController;
use App\Domains\Murojaat\Http\Controllers\Api\DashboardController;
use App\Domains\Murojaat\Http\Controllers\Api\DistrictController;
use App\Domains\Murojaat\Http\Controllers\Api\DynamicsController;
use App\Domains\Murojaat\Http\Controllers\Api\ExportController;
use App\Domains\Murojaat\Http\Controllers\Api\ImportController;
use App\Domains\Murojaat\Http\Controllers\Api\KpiController;
use App\Domains\Murojaat\Http\Controllers\Api\MahallaController;
use App\Domains\Murojaat\Http\Controllers\Api\MeController;
use App\Domains\Murojaat\Http\Controllers\Api\MurojaatAdminController;
use App\Domains\Murojaat\Http\Controllers\Api\SayyorController;
use App\Domains\Murojaat\Http\Controllers\Api\StatistikaController;
use Illuminate\Support\Facades\Route;

/*
 * MUROJAAT domeni API (fuqarolar murojaatlari tahlili). auth:sanctum + murojaat gvardiyasi.
 * Auth-siz -> 401; murojaat bo'lmagan -> 403. Rol ichidagi vakolat (viloyat/tuman) +
 * ruxsat (murojaat.view/import/export/manage) kontrollerда (MurojaatAccess).
 */
Route::middleware(['auth:sanctum', 'murojaat'])
    ->prefix('murojaat')
    ->name('api.murojaat.')
    ->group(function () {
        Route::get('/me', MeController::class)->name('me');
        Route::get('/districts', DistrictController::class)->name('districts');

        // Import (client o'qiydi -> server normallashtiradi) + sessiyalar (arxiv).
        Route::post('/import', [ImportController::class, 'store'])->name('import.store');
        Route::get('/import/sessions', [ImportController::class, 'sessions'])->name('import.sessions');
        Route::post('/import/sessions/{session}/activate', [ImportController::class, 'activate'])->name('import.activate');

        // Tahlil (hammasi is_active sessiya + scope kesimida, SQL).
        Route::get('/dashboard', DashboardController::class)->name('dashboard');
        Route::get('/appeals', [AppealController::class, 'index'])->name('appeals');
        Route::get('/kpi', KpiController::class)->name('kpi');
        Route::get('/mahalla', MahallaController::class)->name('mahalla');
        Route::get('/sayyor', SayyorController::class)->name('sayyor');
        Route::get('/statistika', StatistikaController::class)->name('statistika');
        Route::get('/dynamics', DynamicsController::class)->name('dynamics');
        Route::get('/export', ExportController::class)->name('export');

        // Foydalanuvchilar boshqaruvi — FAQAT murojaat.manage.
        Route::get('/users', [MurojaatAdminController::class, 'index'])->name('users.index');
        Route::post('/users', [MurojaatAdminController::class, 'store'])->name('users.store');
        Route::post('/users/{user}/reset-password', [MurojaatAdminController::class, 'resetPassword'])->name('users.reset');
        Route::patch('/users/{user}', [MurojaatAdminController::class, 'update'])->name('users.update');
    });
