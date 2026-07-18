<?php

declare(strict_types=1);

use App\Domains\Mahalla\Http\Controllers\Api\Admin\GeoController;
use App\Domains\Mahalla\Http\Controllers\Api\Admin\OverviewController;
use App\Domains\Mahalla\Http\Controllers\Api\Admin\ReportsController;
use App\Domains\Mahalla\Http\Controllers\Api\Admin\ReviewController;
use App\Domains\Mahalla\Http\Controllers\Api\Admin\UserManagementController;
use App\Domains\Mahalla\Http\Controllers\Api\ContextController;
use App\Domains\Mahalla\Http\Controllers\Api\DashboardController;
use App\Domains\Mahalla\Http\Controllers\Api\HouseController;
use App\Domains\Mahalla\Http\Controllers\Api\ObservationController;
use App\Domains\Mahalla\Http\Controllers\Api\PhotoController;
use App\Domains\Mahalla\Http\Controllers\Api\PhotoUploadController;
use App\Domains\Mahalla\Http\Controllers\Api\WorklistController;
use Illuminate\Support\Facades\Route;

/*
 * MAHALLA domeni API. auth:sanctum + mahalla tizimiga ruxsat.
 */
Route::middleware(['auth:sanctum', 'system.access:mahalla'])
    ->prefix('mahalla')
    ->name('api.mahalla.')
    ->group(function () {
        Route::get('/context', ContextController::class)->name('context');
        Route::get('/dashboard', DashboardController::class)->name('dashboard');

        // WORKLIST — kadastr binolari asosida (deputat ko'chalari) + zona monitoring
        Route::get('/worklist', [WorklistController::class, 'index'])->name('worklist.index');
        Route::get('/buildings/{building}', [WorklistController::class, 'show'])->name('buildings.show');
        Route::post('/buildings/{building}/observations', [ObservationController::class, 'store'])->name('buildings.observations.store');

        // Eski (houses-asosli) endpointlar — moslik uchun saqlanadi
        Route::get('/houses', [HouseController::class, 'index'])->name('houses.index');
        Route::get('/houses/{house}', [HouseController::class, 'show'])->name('houses.show');
        Route::post('/houses/{house}/photos', [PhotoUploadController::class, 'store'])->name('houses.photos.store');

        Route::get('/photos/{photo}', [PhotoController::class, 'show'])->name('photos.show');

        /*
         * ADMIN boshqaruv API — faqat super-admin (`mahalla.admin` gvardiyasi).
         * Umumiy statistika + operatsion userlar CRUD + geo pickerlar.
         */
        Route::prefix('admin')
            ->name('admin.')
            ->middleware('mahalla.admin')
            ->group(function () {
                Route::get('/overview', OverviewController::class)->name('overview');
                Route::get('/geo', GeoController::class)->name('geo');
                Route::get('/reports', ReportsController::class)->name('reports');

                // Masul hodim review navbati (AI ikkilangan/o'zgarishsiz kuzatuvlar)
                Route::get('/reviews', [ReviewController::class, 'index'])->name('reviews.index');
                Route::post('/reviews/{observation}/confirm', [ReviewController::class, 'confirm'])->name('reviews.confirm');
                Route::post('/reviews/{observation}/reject', [ReviewController::class, 'reject'])->name('reviews.reject');

                Route::get('/users', [UserManagementController::class, 'index'])->name('users.index');
                Route::post('/users', [UserManagementController::class, 'store'])->name('users.store');
                Route::put('/users/{id}', [UserManagementController::class, 'update'])->name('users.update');
                Route::delete('/users/{id}', [UserManagementController::class, 'destroy'])->name('users.destroy');
            });
    });
