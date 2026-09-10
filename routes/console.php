<?php

use App\Console\Commands\PruneInteriorPhotos;
use App\Console\Commands\ReanalyzeStuckObservations;
use App\Domains\Ayollar\Console\Commands\MakeAyollarUserCommand;
use App\Domains\Ayollar\Console\Commands\PurgeAyollarDataCommand;
use App\Domains\Ayollar\Console\Commands\RecalculateBalancesCommand;
use App\Domains\Ayollar\Console\Commands\SeedAyollarDemoCommand;
use App\Domains\Mahalla\Console\Commands\AddMahallaAliasCommand;
use App\Domains\Mahalla\Console\Commands\CyrillicizeMahallaNamesCommand;
use App\Domains\Mahalla\Console\Commands\AddMahallaAliasCommand;
use App\Domains\Mahalla\Console\Commands\ImportMahallaIndicatorsCommand;
use App\Domains\Mahalla\Console\Commands\RenameMahallaCommand;
use App\Domains\Mahalla\Console\Commands\ImportNonResidentialCommand;
use App\Domains\Mahalla\Console\Commands\MakeViewerCommand;
use App\Domains\Qurilish\Console\Commands\ImportQurilishCommand;
use App\Domains\Qurilish\Console\Commands\MakeQurilishUserCommand;
use App\Domains\Sport\Console\Commands\ImportTrainersCommand;
use App\Domains\Yoshlar\Console\Commands\CheckDeadlinesCommand;
use App\Domains\Ayollar\Console\Commands\MakeAyollarUserCommand;
use App\Domains\Ayollar\Console\Commands\RecalculateBalancesCommand;
use App\Domains\Ayollar\Console\Commands\SeedAyollarDemoCommand;
use App\Domains\Yoshlar\Console\Commands\MakeYoshlarUserCommand;
use App\Domains\Yoshlar\Console\Commands\RefreshRegistryCommand;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// `bootstrap/app.php` `withRouting(commands: routes/console.php)` ni aniq
// belgilagani uchun `app/Console/Commands` avtomatik skanerlanmaydi (Laravel'ning
// standart papkani qidirish xatti-harakati bekor qilinadi). Domen tuzilishiga mos
// ravishda buyruq `App\Domains\Mahalla\Console\Commands` ostida yashaydi — shu
// bois uni bu yerda qo'lda ro'yxatdan o'tkazamiz. DIQQAT: bu yerda `Artisan`
// FASAD emas, `Illuminate\Console\Application::starting()` kerak — fasad
// (`Illuminate\Support\Facades\Artisan`) `Kernel` kontraktiga bog'lanadi, u
// `starting()` metodiga ega emas (u faqat konsol `Application` sinfida bor).
ConsoleApplication::starting(function ($artisan) {
    $artisan->resolve(MakeViewerCommand::class);
    $artisan->resolve(CyrillicizeMahallaNamesCommand::class);
    $artisan->resolve(ImportNonResidentialCommand::class);
    $artisan->resolve(RenameMahallaCommand::class);
    $artisan->resolve(ImportMahallaIndicatorsCommand::class);
    $artisan->resolve(AddMahallaAliasCommand::class);
    // Maxfiylik + node-down tiklanishi buyruqlari (app/Console/Commands avtomatik
    // skanerlanmagani uchun bu yerda ham qo'lda ro'yxatdan o'tkaziladi).
    $artisan->resolve(PruneInteriorPhotos::class);
    $artisan->resolve(ReanalyzeStuckObservations::class);
    // Sport domeni.
    $artisan->resolve(ImportTrainersCommand::class);
    // Qurilish domeni: ETL va hisob yaratish.
    $artisan->resolve(ImportQurilishCommand::class);
    $artisan->resolve(MakeQurilishUserCommand::class);
    // Yoshlar domeni: hisob yaratish va reyestr yosh chegarasini yangilash.
    $artisan->resolve(MakeYoshlarUserCommand::class);
    $artisan->resolve(RefreshRegistryCommand::class);
    $artisan->resolve(CheckDeadlinesCommand::class);
    // Ayollar Balansi domeni: hisob yaratish (auth + doira birga).
    $artisan->resolve(MakeAyollarUserCommand::class);
    $artisan->resolve(SeedAyollarDemoCommand::class);
    $artisan->resolve(PurgeAyollarDataCommand::class);
    $artisan->resolve(RecalculateBalancesCommand::class);
});

/*
 * REJALASHTIRISH (Laravel 11 — schedule shu faylda):
 *  - prune-interior-photos: har kuni 03:00 da uy-ichi rasmlarini tozalaydi.
 *  - reanalyze-stuck: har 15 daqiqada 'pending'da qolgan kuzatuvlarni tiklaydi.
 * withoutOverlapping — uzoq davom etsa, keyingi ishga tushish ustma-ust kelmasin.
 */
Schedule::command('mahalla:prune-interior-photos')
    ->dailyAt('03:00')
    ->withoutOverlapping();

Schedule::command('mahalla:reanalyze-stuck')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Yoshlar reyestri: 30 yoshdan oshganlar tunda arxivga o'tadi (o'chirilmaydi).
Schedule::command('yoshlar:refresh-registry')
    ->dailyAt('02:30')
    ->withoutOverlapping();

// Muddat nazorati va eskalatsiya — ish kuni boshlanishidan oldin.
Schedule::command('yoshlar:check-deadlines')
    ->dailyAt('07:00')
    ->withoutOverlapping();

// Ayollar Balansi: to'liq qayta hisoblash — XAVFSIZLIK TO'RI.
//
// Kunlik ish inkremental yangilash bilan bajariladi (anketa saqlanganda).
// Bu esa har ehtimolga qarshi: yangilash biror sababga ko'ra o'tkazib
// yuborilgan bo'lsa (uzilish, to'g'ridan-to'g'ri SQL), kechasi tiklanadi.
// 03:30 — boshqa og'ir ishlardan keyin, ish kuni boshlanishidan ancha oldin.
Schedule::command('ayollar:recalculate')
    ->dailyAt('03:30')
    ->withoutOverlapping();
