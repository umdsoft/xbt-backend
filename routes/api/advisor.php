<?php

declare(strict_types=1);

use App\Domains\Advisor\Http\Controllers\Api\ActionPlanController;
use App\Domains\Advisor\Http\Controllers\Api\ActivityController;
use App\Domains\Advisor\Http\Controllers\Api\AdvisorAdminController;
use App\Domains\Advisor\Http\Controllers\Api\AnalyticsController;
use App\Domains\Advisor\Http\Controllers\Api\ArchiveController;
use App\Domains\Advisor\Http\Controllers\Api\DashboardController;
use App\Domains\Advisor\Http\Controllers\Api\DistrictController;
use App\Domains\Advisor\Http\Controllers\Api\KpiController;
use App\Domains\Advisor\Http\Controllers\Api\MeController;
use App\Domains\Advisor\Http\Controllers\Api\MonitoringController;
use App\Domains\Advisor\Http\Controllers\Api\OversightController;
use App\Domains\Advisor\Http\Controllers\Api\ProjectController;
use App\Domains\Advisor\Http\Controllers\Api\RankingController;
use App\Domains\Advisor\Http\Controllers\Api\ReportController;
use App\Domains\Advisor\Http\Controllers\Api\SvodController;
use App\Domains\Advisor\Http\Controllers\Api\TaskCategoryController;
use App\Domains\Advisor\Http\Controllers\Api\TaskController;
use Illuminate\Support\Facades\Route;

/*
 * ADVISOR domeni API (Hokim maslahatchilari). auth:sanctum + advisor gvardiyasi.
 * Auth-siz -> 401 (auth:sanctum); advisor bo'lmagan -> 403 (advisor middleware).
 * Rol ichidagi vakolat (viloyat/bo'linma/tuman) kontroller darajasida
 * (AdvisorAccess::can / scopeFor) tekshiriladi.
 */
Route::middleware(['auth:sanctum', 'advisor'])
    ->prefix('advisor')
    ->name('api.advisor.')
    ->group(function () {
        Route::get('/me', MeController::class)->name('me');
        Route::get('/districts', DistrictController::class)->name('districts');

        // Maslahatchilar (hisoblar) boshqaruvi — FAQAT viloyat super-admin
        // (kontrollerда 'advisors.manage' tekshiriladi). Login/parol yaratish + reset.
        Route::get('/users', [AdvisorAdminController::class, 'index'])->name('users.index');
        Route::post('/users', [AdvisorAdminController::class, 'store'])->name('users.store');
        Route::post('/users/{user}/reset-password', [AdvisorAdminController::class, 'resetPassword'])->name('users.reset');
        Route::patch('/users/{user}', [AdvisorAdminController::class, 'update'])->name('users.update');

        // Faoliyat nazorati + dashboard + svod (5-bosqich).
        Route::get('/dashboard', DashboardController::class)->name('dashboard');
        // Boshqaruv paneli tahlili (grafik) — butun yil kesimi (chorak tanlashsiz).
        Route::get('/dashboard/analytics', AnalyticsController::class)->name('dashboard.analytics');
        Route::get('/oversight', OversightController::class)->name('oversight');
        Route::get('/activity', ActivityController::class)->name('activity');
        Route::get('/export/svod', SvodController::class)->name('export.svod');

        // Topshiriqlar + kategoriyalar (2-bosqich).
        Route::get('/task-categories', TaskCategoryController::class)->name('task-categories');
        Route::get('/tasks', [TaskController::class, 'index'])->name('tasks.index');
        Route::post('/tasks', [TaskController::class, 'store'])->name('tasks.store');
        Route::get('/tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
        Route::post('/tasks/{task}/report', [TaskController::class, 'report'])->name('tasks.report');

        // Hisobot ustidan amallar (QA / tasdiq / qaytarish) + dalil fayli.
        Route::post('/reports/{report}/qa', [ReportController::class, 'qa'])->name('reports.qa');
        Route::post('/reports/{report}/approve', [ReportController::class, 'approve'])->name('reports.approve');
        Route::post('/reports/{report}/return', [ReportController::class, 'return'])->name('reports.return');
        Route::get('/reports/files/{file}', [ReportController::class, 'file'])->name('reports.file');

        // Arxiv / bilim bazasi (qidiruv + xlsx eksport).
        Route::get('/archive', [ArchiveController::class, 'index'])->name('archive.index');
        Route::get('/archive/export', [ArchiveController::class, 'export'])->name('archive.export');

        // Loyihalar (3-bosqich). Fayl stream route {project} dan OLDIN (segment
        // to'qnashuvidan himoya).
        Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
        Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
        Route::get('/projects/files/{file}', [ProjectController::class, 'file'])->name('projects.file');
        Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
        Route::patch('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
        Route::post('/projects/{project}/updates', [ProjectController::class, 'addUpdate'])->name('projects.updates');
        Route::post('/projects/{project}/files', [ProjectController::class, 'uploadFiles'])->name('projects.files.store');
        Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

        // KPI (4-bosqich). Katalog + kiritish (ijro%) + tasdiq + xulosa + derivatsiya.
        Route::get('/kpis', [KpiController::class, 'index'])->name('kpis.index');
        Route::get('/kpi/entries', [KpiController::class, 'entries'])->name('kpi.entries.index');
        Route::post('/kpi/entries', [KpiController::class, 'storeEntry'])->name('kpi.entries.store');
        Route::post('/kpi/entries/{entry}/approve', [KpiController::class, 'approveEntry'])->name('kpi.entries.approve');
        Route::get('/kpi/summary', [KpiController::class, 'summary'])->name('kpi.summary');
        Route::get('/kpi/matrix', [KpiController::class, 'matrix'])->name('kpi.matrix');
        Route::get('/kpi/year', [KpiController::class, 'year'])->name('kpi.year');

        // Reyting (4-bosqich). Ko'rish (hamma) + hisoblash (viloyat).
        Route::get('/rankings', [RankingController::class, 'index'])->name('rankings.index');
        Route::post('/rankings/compute', [RankingController::class, 'compute'])->name('rankings.compute');

        // Chora-tadbirlar (bir nechta yillik reja). Ro'yxat (jadval) + reja yaratish
        // (tasdiqlovchi hujjat majburiy, viloyat) + tafsilot + band qo'shish + hujjat +
        // band tuman kesimi + bajarilishini kiritish. {item} yo'llari {plan} dan OLDIN.
        Route::get('/action-plan', [ActionPlanController::class, 'index'])->name('action-plan.index');
        Route::post('/action-plan', [ActionPlanController::class, 'store'])->name('action-plan.store');
        // Statistika (har band = topshiriq) — {plan} binding'дан OLDIN ('stats' plan emas).
        Route::get('/action-plan/stats', [ActionPlanController::class, 'stats'])->name('action-plan.stats');
        Route::get('/action-plan/items/{item}', [ActionPlanController::class, 'showItem'])->name('action-plan.item');
        Route::post('/action-plan/items/{item}/progress', [ActionPlanController::class, 'progress'])->name('action-plan.progress');
        Route::get('/action-plan/{plan}', [ActionPlanController::class, 'show'])->name('action-plan.show');
        Route::post('/action-plan/{plan}/items', [ActionPlanController::class, 'storeItem'])->name('action-plan.items.store');
        Route::get('/action-plan/{plan}/document', [ActionPlanController::class, 'document'])->name('action-plan.document');

        // Svod jadvallar (qaror/farmon ijrosi monitoringi). Barcha rol ko'radi;
        // tuman o'z satrini kiritadi; viloyat yaratadi + tasdiqlaydi.
        Route::get('/monitoring', [MonitoringController::class, 'index'])->name('monitoring.index');
        Route::post('/monitoring', [MonitoringController::class, 'store'])->name('monitoring.store');
        // Statistika + eksport {sheet} binding'дан OLDIN emas (stats — alohida so'z).
        Route::get('/monitoring/stats', [MonitoringController::class, 'stats'])->name('monitoring.stats');
        Route::get('/monitoring/{sheet}', [MonitoringController::class, 'show'])->name('monitoring.show');
        Route::patch('/monitoring/{sheet}', [MonitoringController::class, 'update'])->name('monitoring.update');
        Route::get('/monitoring/{sheet}/export', [MonitoringController::class, 'export'])->name('monitoring.export');
        Route::post('/monitoring/{sheet}/duplicate', [MonitoringController::class, 'duplicate'])->name('monitoring.duplicate');
        Route::post('/monitoring/{sheet}/entry', [MonitoringController::class, 'entry'])->name('monitoring.entry');
        Route::post('/monitoring/{sheet}/entries/{district}/confirm', [MonitoringController::class, 'confirm'])->name('monitoring.confirm');
        Route::post('/monitoring/{sheet}/entries/{district}/return', [MonitoringController::class, 'return'])->name('monitoring.return');
    });
