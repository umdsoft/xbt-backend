<?php

use App\Domains\Mahalla\Console\Commands\CyrillicizeMahallaNamesCommand;
use App\Domains\Mahalla\Console\Commands\ImportMahallaIndicatorsCommand;
use App\Domains\Mahalla\Console\Commands\RenameMahallaCommand;
use App\Domains\Mahalla\Console\Commands\ImportNonResidentialCommand;
use App\Domains\Mahalla\Console\Commands\MakeViewerCommand;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

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
});
