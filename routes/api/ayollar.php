<?php

declare(strict_types=1);

use App\Domains\Ayollar\Http\Controllers\Api\AdminController;
use App\Domains\Ayollar\Http\Controllers\Api\AnalyticsController;
use App\Domains\Ayollar\Http\Controllers\Api\AnketaController;
use App\Domains\Ayollar\Http\Controllers\Api\BalanceController;
use App\Domains\Ayollar\Http\Controllers\Api\BootstrapController;
use App\Domains\Ayollar\Http\Controllers\Api\ContextController;
use App\Domains\Ayollar\Http\Controllers\Api\DeviceController;
use App\Domains\Ayollar\Http\Controllers\Api\ExportController;
use App\Domains\Ayollar\Http\Controllers\Api\GeoController;
use App\Domains\Ayollar\Http\Controllers\Api\HouseholdController;
use App\Domains\Ayollar\Http\Controllers\Api\PublicQrController;
use App\Domains\Ayollar\Http\Controllers\Api\RulesController;
use App\Domains\Ayollar\Http\Controllers\Api\StaffController;
use App\Domains\Ayollar\Http\Controllers\Api\TabletController;
use App\Domains\Ayollar\Http\Controllers\Api\WomanController;
use App\Domains\Ayollar\Http\Controllers\Api\WorkPlanController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| AYOLLAR BALANSI
|--------------------------------------------------------------------------
|
| Ikki guruh: OCHIQ (QR tekshiruvi) va HIMOYALANGAN (auth + gvardiya).
| Ochiq guruh birinchi turadi va u ATAYLAB juda tor — bitta endpoint.
|
*/

/*
 * QR hujjat tekshiruvi — AUTENTIFIKATSIYASIZ.
 *
 * Bosilgan hujjatdagi QR'ni skanerlagan har kim ochadi. Javobda shaxsiy
 * ma'lumot YO'Q (promt §7) — faqat hujjat faktlari va imzolar zanjiri.
 *
 * `throttle:30,1` — token maydonini brute-force qilishga qarshi. Imzo
 * tekshiruvi ham bor, lekin rate-limit birinchi to'siq: u hujumni
 * boshlanishidayoq qimmatga aylantiradi.
 */
Route::get('/ayollar/public/a/{token}', PublicQrController::class)
    ->middleware('throttle:30,1')
    ->where('token', '[A-Za-z0-9]{4,10}')
    ->name('api.ayollar.public.verify');

/*
 * Himoyalangan API. auth:sanctum + `ayollar` gvardiyasi.
 * Auth-siz -> 401; rolsiz -> 403.
 */
Route::middleware(['auth:sanctum', 'ayollar'])
    ->prefix('ayollar')
    ->name('api.ayollar.')
    ->group(function () {
        // ---------- Kontekst va offline paket ----------
        Route::get('/context', ContextController::class)->name('context');
        Route::get('/bootstrap', BootstrapController::class)->name('bootstrap');
        Route::get('/rules', RulesController::class)->name('rules');

        // Planshet kirgandan keyin qurilmani qayd etadi (promt §11).
        Route::post('/device/register', [DeviceController::class, 'register'])->name('device.register');

        // ---------- Xonadon va ayol ----------
        Route::get('/households', [HouseholdController::class, 'index'])->name('households.index');
        Route::post('/households', [HouseholdController::class, 'store'])->name('households.store');

        Route::get('/women', [WomanController::class, 'index'])->name('women.index');
        Route::post('/women', [WomanController::class, 'store'])->name('women.store');

        // DIQQAT: `check-duplicate` POST va `{woman}` dan OLDIN.
        // GET bo'lsa JShShIR URL'ga, ya'ni server jurnaliga va brauzer
        // tarixiga tushardi (promt §14).
        Route::post('/women/check-duplicate', [WomanController::class, 'checkDuplicate'])
            ->middleware('throttle:60,1')
            ->name('women.check_duplicate');

        // Maxfiy maydonni ochish — har chaqiruv jurnalga tushadi.
        Route::post('/women/{woman}/reveal-pii', [WomanController::class, 'revealPii'])
            ->middleware('throttle:30,1')
            ->name('women.reveal_pii');

        // ---------- Anketa ----------
        // `conflicts` `{anketa}` dan OLDIN — aks holda «conflicts» so'zi
        // parametr sifatida ushlanadi.
        Route::get('/anketas/conflicts', [AnketaController::class, 'conflicts'])->name('anketas.conflicts');
        Route::post('/anketas/batch', [AnketaController::class, 'batch'])->name('anketas.batch');
        Route::get('/anketas', [AnketaController::class, 'index'])->name('anketas.index');
        Route::post('/anketas', [AnketaController::class, 'store'])->name('anketas.store');
        Route::get('/anketas/{anketa}', [AnketaController::class, 'show'])->name('anketas.show');
        Route::get('/anketas/{anketa}/qr.svg', [AnketaController::class, 'qrSvg'])->name('anketas.qr');
        Route::post('/conflicts/{conflict}/resolve', [AnketaController::class, 'resolveConflict'])
            ->name('conflicts.resolve');

        // ---------- Balans ----------
        Route::get('/balances/region', [BalanceController::class, 'region'])->name('balances.region');
        Route::get('/balances/district/{district}', [BalanceController::class, 'district'])->name('balances.district');
        Route::get('/balances/district/{district}/mahallas', [BalanceController::class, 'mahallasOfDistrict'])
            ->name('balances.district.mahallas');
        Route::get('/balances/mahalla/{mahalla}', [BalanceController::class, 'mahalla'])->name('balances.mahalla');

        Route::post('/balances/{type}/{id}/close', [BalanceController::class, 'close'])->name('balances.close');
        Route::post('/balances/{type}/{id}/sign', [BalanceController::class, 'sign'])->name('balances.sign');
        Route::post('/balances/{type}/{id}/return', [BalanceController::class, 'returnBack'])->name('balances.return');

        // ---------- Tahlil ----------
        Route::get('/analytics/needs', [AnalyticsController::class, 'needs'])->name('analytics.needs');
        Route::get('/analytics/activists', [AnalyticsController::class, 'activists'])->name('analytics.activists');
        Route::get('/analytics/pace', [AnalyticsController::class, 'pace'])->name('analytics.pace');
        Route::get('/analytics/red', [AnalyticsController::class, 'redComposition'])->name('analytics.red');

        // ---------- Ish rejasi ----------
        Route::get('/work-plans', [WorkPlanController::class, 'index'])->name('work_plans.index');
        Route::post('/work-plans', [WorkPlanController::class, 'store'])->name('work_plans.store');
        Route::patch('/work-plans/{workPlan}', [WorkPlanController::class, 'update'])->name('work_plans.update');
        Route::get('/red-list', [WorkPlanController::class, 'redList'])->name('red_list');

        // ---------- Administrator ----------
        // Jurnal O'QISH uchun: u nazorat vositasi va o'zgartirilmaydi.
        /*
         * GEOGRAFIYA — tuman, MFY, ko'cha, uy.
         *
         * Manba `master` sxemasi (kadastr). Planshet manzil tanlashda,
         * administrator esa hisob ochishda ishlatadi — ikkalasi ham
         * AYNAN BIR ro'yxatdan o'qiydi.
         */
        Route::get('/geo/districts', [GeoController::class, 'districts'])->name('geo.districts');
        Route::get('/geo/districts/{district}/mahallas', [GeoController::class, 'mahallas'])
            ->name('geo.mahallas')->whereUuid('district');
        Route::get('/geo/streets', [GeoController::class, 'streets'])->name('geo.streets.own');
        Route::get('/geo/mahallas/{mahalla}/streets', [GeoController::class, 'streets'])
            ->name('geo.streets')->whereUuid('mahalla');
        Route::get('/geo/streets/{street}/houses', [GeoController::class, 'houses'])
            ->name('geo.houses')->whereUuid('street');

        /* Planshet bosh ekrani — bitta so'rovda barcha bloklar. */
        Route::get('/tablet/home', [TabletController::class, 'home'])->name('tablet.home');

        /*
         * HISOBLAR — administrator faol uchun login/parol ochadi.
         *
         * Avval bu faqat serverdagi CLI buyrug'i edi; 509 MFY uchun
         * bunday ish tartibi real emas.
         */
        Route::get('/staff', [StaffController::class, 'index'])->name('staff.index');
        Route::post('/staff', [StaffController::class, 'store'])->name('staff.store');
        Route::patch('/staff/{id}', [StaffController::class, 'update'])->name('staff.update')->whereUuid('id');
        Route::post('/staff/{id}/password', [StaffController::class, 'resetPassword'])
            ->name('staff.password')->whereUuid('id');
        Route::delete('/staff/{id}', [StaffController::class, 'destroy'])->name('staff.destroy')->whereUuid('id');

        Route::get('/admin/audit', [AdminController::class, 'audit'])->name('admin.audit');
        Route::get('/admin/sensitive-access', [AdminController::class, 'sensitiveAccess'])
            ->name('admin.sensitive_access');
        Route::get('/admin/metrics', [AdminController::class, 'metrics'])->name('admin.metrics');
        Route::patch('/admin/metrics/{code}', [AdminController::class, 'updateMetric'])->name('admin.metrics.update');
        Route::get('/admin/health', [AdminController::class, 'health'])->name('admin.health');

        // ---------- Eksport ----------
        Route::get('/export/registry', [ExportController::class, 'registry'])->name('export.registry');
        Route::get('/export/balance/{type}/{id}', [ExportController::class, 'balance'])->name('export.balance');
        // Rasmiy shakl ko'rinishidagi XLSX — qog'ozdagi bilan bir xil.
        Route::get('/export/balance-form/{type}/{id}', [ExportController::class, 'balanceForm'])
            ->name('export.balance_form');
        // Rasmiy hujjat — QR bilan. V bo'lim javoblari PDF'ga tushmaydi.
        Route::get('/export/anketa/{anketa}/pdf', [ExportController::class, 'anketaPdf'])->name('export.anketa_pdf');
    });
