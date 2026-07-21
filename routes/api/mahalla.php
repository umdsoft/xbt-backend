<?php

declare(strict_types=1);

use App\Domains\Mahalla\Http\Controllers\Api\Admin\GeoController;
use App\Domains\Mahalla\Http\Controllers\Api\Admin\OverviewController;
use App\Domains\Mahalla\Http\Controllers\Api\Admin\ReportsController;
use App\Domains\Mahalla\Http\Controllers\Api\Admin\ReviewController;
use App\Domains\Mahalla\Http\Controllers\Api\Admin\UserManagementController;
use App\Domains\Mahalla\Http\Controllers\Api\ContextController;
use App\Domains\Mahalla\Http\Controllers\Api\DashboardController;
use App\Domains\Mahalla\Http\Controllers\Api\Executive\DistrictDashboardController;
use App\Domains\Mahalla\Http\Controllers\Api\Executive\SocialObjectsController;
use App\Domains\Mahalla\Http\Controllers\Api\Rais\CadastreController;
use App\Domains\Mahalla\Http\Controllers\Api\Rais\ContractController;
use App\Domains\Mahalla\Http\Controllers\Api\Hokim\ProjectController;
use App\Domains\Mahalla\Http\Controllers\Api\Executive\DistrictGeoJsonController;
use App\Domains\Mahalla\Http\Controllers\Api\Executive\MahallaDashboardController;
use App\Domains\Mahalla\Http\Controllers\Api\Executive\ObodDashboardController;
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
         * МАҲАЛЛА РАИСИ — ўз маҳалласи доирасида кўради ВА тузатади.
         *
         * Marshrutlarda `{mahalla}` YO'Q: qamrov foydalanuvchi profilidan
         * olinadi. Parametr bo'lsa uni almashtirib boshqa mahallani ochish
         * mumkin bo'lardi.
         */
        Route::prefix('rais')
            ->name('rais.')
            ->middleware('mahalla.rais')
            ->group(function () {
                Route::get('/overview', [CadastreController::class, 'overview'])->name('overview');
                Route::get('/buildings', [CadastreController::class, 'buildings'])->name('buildings');
                Route::patch('/buildings/{building}', [CadastreController::class, 'classify'])
                    ->name('buildings.classify')
                    ->whereUuid('building');

                // Ижтимоий шартнома — хонадон кесимида.
                Route::get('/contracts/households', [ContractController::class, 'households'])->name('contracts.households');
                Route::get('/contracts/building/{building}', [ContractController::class, 'show'])
                    ->name('contracts.show')->whereUuid('building');
                Route::post('/contracts/building/{building}', [ContractController::class, 'store'])
                    ->name('contracts.store')->whereUuid('building');
                Route::delete('/contracts/{contract}', [ContractController::class, 'destroy'])
                    ->name('contracts.destroy')->whereUuid('contract');
                Route::get('/contracts/file/{file}', [ContractController::class, 'download'])
                    ->name('contracts.download')->whereUuid('file');
            });

        /*
         * ҲОКИМ ЁРДАМЧИСИ — ўз маҳалласида микролойиҳаларни юритади.
         */
        Route::prefix('hokim')
            ->name('hokim.')
            ->middleware('mahalla.hokim')
            ->group(function () {
                Route::get('/overview', [ProjectController::class, 'overview'])->name('overview');
                Route::get('/projects', [ProjectController::class, 'index'])->name('projects');
                Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
                Route::get('/projects/{project}', [ProjectController::class, 'show'])
                    ->name('projects.show')->whereUuid('project');
                Route::patch('/projects/{project}', [ProjectController::class, 'update'])
                    ->name('projects.update')->whereUuid('project');
                Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])
                    ->name('projects.destroy')->whereUuid('project');
                Route::post('/projects/{project}/updates', [ProjectController::class, 'addUpdate'])
                    ->name('projects.updates')->whereUuid('project');
                Route::post('/projects/{project}/files', [ProjectController::class, 'uploadFile'])
                    ->name('projects.files')->whereUuid('project');
                Route::get('/projects/file/{file}', [ProjectController::class, 'downloadFile'])
                    ->name('projects.download')->whereUuid('file');
            });

        /*
         * RAHBARIYAT (viloyat hokimi o'rinbosari) — FAQAT KO'RISH.
         * `mahalla.viewer`: admin + viloyat. Boshqaruv endpointlari
         * o'z gvardiyasida (`mahalla.admin`) qoladi.
         */
        Route::prefix('executive')
            ->name('executive.')
            ->middleware('mahalla.viewer')
            ->group(function () {
                /*
                 * whereUuid(...): {district}/{mahalla} to'g'ridan-to'g'ri
                 * findOrFail() ga uzatiladi. Noto'g'ri formatdagi qiymat
                 * (masalan "not-a-valid-uuid") cheklovsiz PostgreSQL drayveriga
                 * yetib borib, u yerda QueryException (22P02/25P02) sifatida
                 * portlaydi — findOrFail() faqat ModelNotFoundException'ni
                 * (404) tutadi, drayver xatosini emas. Natijada 500 va javobda
                 * stack trace + xom SQL + baza ulanish tafsilotlari sizib chiqadi.
                 * Format cheklovi bunday satrni marshrutlashda rad etib, toza
                 * 404 qaytaradi — so'rov bazaga umuman yetib bormaydi.
                 * {district?} ixtiyoriy bo'lib qoladi: cheklov faqat qiymat
                 * BERILGANDA tekshiriladi, parametrsiz so'rov standart tumanga
                 * (Shovot) tushishda davom etadi.
                 */
                Route::get('/districts/{district?}', DistrictDashboardController::class)
                    ->name('district')
                    ->whereUuid('district');

                // Panelda "113" raqami ochilganda — ijtimoiy obyektlar ro'yxati.
                // `{district}` majburiy: Laravel ixtiyoriy parametrni faqat
                // URL oxirida qo'llab-quvvatlaydi.
                Route::get('/districts/{district}/social-objects', SocialObjectsController::class)
                    ->name('district.social-objects')
                    ->whereUuid('district');
                /*
                 * `{district}` bu yerda MAJBURIY — asosiy `districts/{district?}`
                 * dan farqli. Laravel ixtiyoriy marshrut parametrini faqat URL
                 * OXIRIDA qo'llab-quvvatlaydi, ya'ni `{district?}/geojson`
                 * ko'rinishi ishlamaydi. Bu muammo emas: frontend chegaralarni
                 * jadval yuklangandan KEYIN so'raydi, demak tuman `id` si
                 * allaqachon qo'lida bo'ladi.
                 */
                Route::get('/districts/{district}/geojson', DistrictGeoJsonController::class)
                    ->whereUuid('district')
                    ->name('district.geojson');
                Route::get('/mahallas/{mahalla}', MahallaDashboardController::class)
                    ->name('mahalla')
                    ->whereUuid('mahalla');
                // Mahalla ichidagi Ободонлаштириш kesimi (masъul × ko'cha × iш turi)
                Route::get('/mahallas/{mahalla}/obod', ObodDashboardController::class)
                    ->name('mahalla.obod')
                    ->whereUuid('mahalla');
            });

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
