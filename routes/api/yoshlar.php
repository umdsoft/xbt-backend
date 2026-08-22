<?php

declare(strict_types=1);

use App\Domains\Yoshlar\Http\Controllers\Api\AdminController;
use App\Domains\Yoshlar\Http\Controllers\Api\AuditController;
use App\Domains\Yoshlar\Http\Controllers\Api\CaseController;
use App\Domains\Yoshlar\Http\Controllers\Api\ContextController;
use App\Domains\Yoshlar\Http\Controllers\Api\EmploymentController;
use App\Domains\Yoshlar\Http\Controllers\Api\ExecutiveController;
use App\Domains\Yoshlar\Http\Controllers\Api\ExportController;
use App\Domains\Yoshlar\Http\Controllers\Api\OrganizationController;
use App\Domains\Yoshlar\Http\Controllers\Api\SectorController;
use App\Domains\Yoshlar\Http\Controllers\Api\StaffController;
use App\Domains\Yoshlar\Http\Controllers\Api\TaskController;
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

        // F2 — protokol va topshiriq ijrosi.
        // `stats`/`queue`/`protocols` `{task}` dan OLDIN: aks holda ular
        // parametr sifatida ushlanadi.
        Route::get('/tasks/stats', [TaskController::class, 'stats'])->name('tasks.stats');
        Route::get('/tasks/queue', [TaskController::class, 'queue'])->name('tasks.queue');
        Route::get('/protocols', [TaskController::class, 'protocols'])->name('protocols.index');
        Route::post('/protocols', [TaskController::class, 'storeProtocol'])->name('protocols.store');
        Route::get('/tasks', [TaskController::class, 'index'])->name('tasks.index');
        Route::post('/tasks', [TaskController::class, 'store'])->name('tasks.store');
        Route::get('/tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
        Route::patch('/tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
        Route::post('/tasks/{task}/submit', [TaskController::class, 'submit'])->name('tasks.submit');
        Route::post('/task-updates/{update}/review', [TaskController::class, 'review'])->name('tasks.review');

        // F3 — bandlik: 3 tomonlama tasdiqlash zanjiri.
        Route::get('/employment/stats', [EmploymentController::class, 'stats'])->name('employment.stats');
        Route::get('/employment/queue', [EmploymentController::class, 'queue'])->name('employment.queue');
        Route::get('/employment', [EmploymentController::class, 'index'])->name('employment.index');
        Route::post('/employment', [EmploymentController::class, 'store'])->name('employment.store');
        Route::get('/employment/{employment}', [EmploymentController::class, 'show'])->name('employment.show');
        Route::post('/employment/{employment}/review', [EmploymentController::class, 'review'])->name('employment.review');

        // F4 — muammolar (case management).
        Route::get('/cases/stats', [CaseController::class, 'stats'])->name('cases.stats');
        Route::get('/cases', [CaseController::class, 'index'])->name('cases.index');
        Route::post('/cases', [CaseController::class, 'store'])->name('cases.store');
        Route::get('/cases/{case}', [CaseController::class, 'show'])->name('cases.show');
        Route::patch('/cases/{case}', [CaseController::class, 'update'])->name('cases.update');

        // F4 — otaliq.
        Route::get('/patronage/stats', [CaseController::class, 'patronageStats'])->name('patronage.stats');
        Route::get('/patronage', [CaseController::class, 'patronageIndex'])->name('patronage.index');
        Route::post('/patronage', [CaseController::class, 'patronageStore'])->name('patronage.store');
        Route::get('/patronage/{patronage}', [CaseController::class, 'patronageShow'])->name('patronage.show');
        Route::post('/patronage/{patronage}/end', [CaseController::class, 'patronageEnd'])->name('patronage.end');
        Route::post('/patronage/{patronage}/logs', [CaseController::class, 'patronageLog'])->name('patronage.log');

        // F5 — rahbariyat paneli va Excel eksport (doiradan o'tadi, PII yo'q).
        Route::get('/executive', ExecutiveController::class)->name('executive');
        Route::get('/export/youth', [ExportController::class, 'youth'])->name('export.youth');
        Route::get('/export/tasks', [ExportController::class, 'tasks'])->name('export.tasks');
        Route::get('/export/employment', [ExportController::class, 'employment'])->name('export.employment');
        Route::get('/export/cases', [ExportController::class, 'cases'])->name('export.cases');

        // Spravochniklar (o'qish — barcha rol, yozish — admin).
        Route::get('/organizations', [OrganizationController::class, 'index'])->name('organizations.index');
        Route::post('/organizations', [OrganizationController::class, 'store'])->name('organizations.store');
        Route::patch('/organizations/{organization}', [OrganizationController::class, 'update'])->name('organizations.update');
        Route::get('/sectors', [SectorController::class, 'index'])->name('sectors.index');
        Route::post('/sectors', [SectorController::class, 'store'])->name('sectors.store');
        Route::patch('/sectors/{sector}', [SectorController::class, 'update'])->name('sectors.update');
        Route::get('/staff', [StaffController::class, 'index'])->name('staff.index');
        Route::post('/staff', [StaffController::class, 'store'])->name('staff.store');
        Route::patch('/staff/{staff}', [StaffController::class, 'update'])->name('staff.update');

        // Hisob boshqaruvi (faqat admin) va audit jurnali.
        Route::get('/admin/users', [AdminController::class, 'index'])->name('admin.users.index');
        Route::post('/admin/users', [AdminController::class, 'store'])->name('admin.users.store');
        Route::patch('/admin/users/{user}', [AdminController::class, 'update'])->name('admin.users.update');
        Route::get('/audit', AuditController::class)->name('audit');
    });
