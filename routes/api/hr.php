<?php

declare(strict_types=1);

use App\Domains\Hr\Http\Controllers\Api\AuditController;
use App\Domains\Hr\Http\Controllers\Api\CatalogController;
use App\Domains\Hr\Http\Controllers\Api\CitizenAppealController;
use App\Domains\Hr\Http\Controllers\Api\ControlPlanController;
use App\Domains\Hr\Http\Controllers\Api\DashboardController;
use App\Domains\Hr\Http\Controllers\Api\EmployeeController;
use App\Domains\Hr\Http\Controllers\Api\ExportController;
use App\Domains\Hr\Http\Controllers\Api\HokimYordamchisiController;
use App\Domains\Hr\Http\Controllers\Api\ItemDocumentController;
use App\Domains\Hr\Http\Controllers\Api\MahallaCouncilController;
use App\Domains\Hr\Http\Controllers\Api\OrganizationController;
use App\Domains\Hr\Http\Controllers\Api\OrganizationUserController;
use App\Domains\Hr\Http\Controllers\Api\TaskController;
use App\Domains\Hr\Http\Controllers\Api\TenantController;
use App\Domains\Hr\Http\Controllers\Api\UserController;
use App\Domains\Hr\Http\Controllers\Api\YoshlarYetakchisiController;
use App\Domains\Hr\Http\Controllers\Api\YouthMeetingController;
use Illuminate\Support\Facades\Route;

/*
 * HR (KBT — Kadrlar Boshqaruv Tizimi) domeni API.
 * auth:sanctum + xbt tizimiga ruxsat + HR tenant konteksti (hr.context —
 * SubstituteBindings'dan oldin ishlab, route-model binding'ni tenant bo'yicha
 * scope qiladi; xbt HIGH-1 IDOR security fix).
 */
Route::middleware(['auth:sanctum', 'system.access:xbt', 'hr.context'])
    ->prefix('hr')
    ->name('api.hr.')
    ->group(function () {
        // Dashboard (rol/tenantga qarab turli rejim)
        Route::get('/dashboard', DashboardController::class)->name('dashboard');

        // Super-admin / viloyat-admin uchun tenant tanlovi
        Route::post('/tenant/switch', [TenantController::class, 'switch'])->name('tenant.switch');

        // Audit jurnali
        Route::get('/audit', [AuditController::class, 'index'])
            ->name('audit.index')
            ->middleware('hr.can:audit.view');

        // ===== Xodimlar (CRUD + bloklar + eksport) =====
        Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
        Route::post('employees', [EmployeeController::class, 'store'])->name('employees.store');
        Route::get('employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show');
        Route::get('employees/{employee}/edit', [EmployeeController::class, 'edit'])->name('employees.edit');
        Route::match(['put', 'patch'], 'employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');
        Route::delete('employees/{employee}', [EmployeeController::class, 'destroy'])->name('employees.destroy');
        Route::get('employees/{employee}/photo', [EmployeeController::class, 'photo'])->name('employees.photo');
        Route::put('employees/{employee}/work-history', [EmployeeController::class, 'saveWorkHistory'])->name('employees.work-history.save');
        Route::put('employees/{employee}/relatives', [EmployeeController::class, 'saveRelatives'])->name('employees.relatives.save');
        Route::get('employees/{employee}/export/malumotnoma', [ExportController::class, 'downloadMalumotnoma'])->name('employees.export.malumotnoma');

        // ===== Nazorat rejalar (control plans) + bandlar — tadbirlar.view talab qilinadi =====
        Route::middleware('hr.can:tadbirlar.view')->group(function () {
            // Band ko'rish/status — resource'dan OLDIN (route conflict oldini olish)
            Route::get('control-plans/items/{item}', [ControlPlanController::class, 'showItem'])->name('control-plan-items.show');
            Route::put('control-plans/items/{item}/status', [ControlPlanController::class, 'updateItemStatus'])->name('control-plan-items.update-status');
            Route::get('control-plans/{controlPlan}/export', [ControlPlanController::class, 'export'])->name('control-plans.export');

            Route::get('control-plans', [ControlPlanController::class, 'index'])->name('control-plans.index');
            Route::post('control-plans', [ControlPlanController::class, 'store'])->name('control-plans.store');
            Route::get('control-plans/{id}', [ControlPlanController::class, 'show'])->name('control-plans.show');
            Route::get('control-plans/{id}/edit', [ControlPlanController::class, 'edit'])->name('control-plans.edit');
            Route::match(['put', 'patch'], 'control-plans/{id}', [ControlPlanController::class, 'update'])->name('control-plans.update');
            Route::delete('control-plans/{id}', [ControlPlanController::class, 'destroy'])->name('control-plans.destroy');
        });

        // ===== Hujjat almashinuvi (EDO) — ruxsat controllerda ControlPlanAccessService orqali =====
        Route::post('control-plans/items/{itemId}/documents', [ItemDocumentController::class, 'store'])->name('item-documents.store');
        Route::get('documents/{id}/download', [ItemDocumentController::class, 'download'])->name('documents.download');
        Route::delete('documents/{id}', [ItemDocumentController::class, 'destroy'])->name('documents.destroy');

        // ===== Mustaqil topshiriqlar (tasks) =====
        Route::get('topshiriqlar', [TaskController::class, 'index'])->name('topshiriqlar.index');
        Route::get('topshiriqlar/create', [TaskController::class, 'create'])->name('topshiriqlar.create');
        Route::post('topshiriqlar', [TaskController::class, 'store'])->name('topshiriqlar.store');
        Route::get('topshiriqlar/{task}', [TaskController::class, 'show'])->name('topshiriqlar.show');
        Route::put('topshiriqlar/{task}/status', [TaskController::class, 'updateStatus'])->name('topshiriqlar.update-status');
        Route::post('topshiriqlar/{task}/respond', [TaskController::class, 'respond'])->name('topshiriqlar.respond');
        Route::put('topshiriqlar/{task}/approve', [TaskController::class, 'approve'])->name('topshiriqlar.approve');
        Route::put('topshiriqlar/{task}/return', [TaskController::class, 'returnForRework'])->name('topshiriqlar.return');
        Route::put('topshiriqlar/{task}/remove-control', [TaskController::class, 'removeFromControl'])->name('topshiriqlar.remove-control');
        Route::put('topshiriqlar/{task}/restore-control', [TaskController::class, 'restoreControl'])->name('topshiriqlar.restore-control');

        // ===== Hokim yordamchilari =====
        Route::get('hokim-yordamchilari', [HokimYordamchisiController::class, 'index'])->name('hokim-yordamchilari.index');
        Route::get('hokim-yordamchilari/create', [HokimYordamchisiController::class, 'create'])->name('hokim-yordamchilari.create');
        Route::post('hokim-yordamchilari', [HokimYordamchisiController::class, 'store'])->name('hokim-yordamchilari.store');
        Route::get('hokim-yordamchilari/{hokimYordamchisi}', [HokimYordamchisiController::class, 'show'])->name('hokim-yordamchilari.show');
        Route::get('hokim-yordamchilari/{hokimYordamchisi}/edit', [HokimYordamchisiController::class, 'edit'])->name('hokim-yordamchilari.edit');
        Route::match(['put', 'patch'], 'hokim-yordamchilari/{hokimYordamchisi}', [HokimYordamchisiController::class, 'update'])->name('hokim-yordamchilari.update');
        Route::delete('hokim-yordamchilari/{hokimYordamchisi}', [HokimYordamchisiController::class, 'destroy'])->name('hokim-yordamchilari.destroy');

        // ===== Yoshlar yetakchilari =====
        Route::get('yoshlar-yetakchilari', [YoshlarYetakchisiController::class, 'index'])->name('yoshlar-yetakchilari.index');
        Route::get('yoshlar-yetakchilari/create', [YoshlarYetakchisiController::class, 'create'])->name('yoshlar-yetakchilari.create');
        Route::post('yoshlar-yetakchilari', [YoshlarYetakchisiController::class, 'store'])->name('yoshlar-yetakchilari.store');
        Route::get('yoshlar-yetakchilari/{yoshlarYetakchi}', [YoshlarYetakchisiController::class, 'show'])->name('yoshlar-yetakchilari.show');
        Route::get('yoshlar-yetakchilari/{yoshlarYetakchi}/edit', [YoshlarYetakchisiController::class, 'edit'])->name('yoshlar-yetakchilari.edit');
        Route::match(['put', 'patch'], 'yoshlar-yetakchilari/{yoshlarYetakchi}', [YoshlarYetakchisiController::class, 'update'])->name('yoshlar-yetakchilari.update');
        Route::delete('yoshlar-yetakchilari/{yoshlarYetakchi}', [YoshlarYetakchisiController::class, 'destroy'])->name('yoshlar-yetakchilari.destroy');

        // ===== Yoshlar uchrashuvlari (meetings) =====
        Route::get('meetings', [YouthMeetingController::class, 'index'])->name('meetings.index');
        Route::get('meetings/create', [YouthMeetingController::class, 'create'])->name('meetings.create');
        Route::post('meetings', [YouthMeetingController::class, 'store'])->name('meetings.store');
        Route::get('meetings/{meeting}', [YouthMeetingController::class, 'show'])->name('meetings.show');
        Route::get('meetings/{meeting}/edit', [YouthMeetingController::class, 'edit'])->name('meetings.edit');
        Route::match(['put', 'patch'], 'meetings/{meeting}', [YouthMeetingController::class, 'update'])->name('meetings.update');
        Route::delete('meetings/{meeting}', [YouthMeetingController::class, 'destroy'])->name('meetings.destroy');

        // ===== Mahalla yettiligi (councils) =====
        Route::get('councils', [MahallaCouncilController::class, 'index'])->name('councils.index');
        Route::get('councils/create', [MahallaCouncilController::class, 'create'])->name('councils.create');
        Route::post('councils', [MahallaCouncilController::class, 'store'])->name('councils.store');
        Route::get('councils/{council}', [MahallaCouncilController::class, 'show'])->name('councils.show');
        Route::get('councils/{council}/edit', [MahallaCouncilController::class, 'edit'])->name('councils.edit');
        Route::match(['put', 'patch'], 'councils/{council}', [MahallaCouncilController::class, 'update'])->name('councils.update');
        Route::delete('councils/{council}', [MahallaCouncilController::class, 'destroy'])->name('councils.destroy');

        // ===== Tashkilotlar (organizations) + tashkilot foydalanuvchilari =====
        Route::get('organizations', [OrganizationController::class, 'index'])->name('organizations.index');
        Route::get('organizations/create', [OrganizationController::class, 'create'])->name('organizations.create');
        Route::post('organizations', [OrganizationController::class, 'store'])->name('organizations.store');
        Route::get('organizations/{organization}', [OrganizationController::class, 'show'])->name('organizations.show');
        Route::get('organizations/{organization}/edit', [OrganizationController::class, 'edit'])->name('organizations.edit');
        Route::match(['put', 'patch'], 'organizations/{organization}', [OrganizationController::class, 'update'])->name('organizations.update');
        Route::delete('organizations/{organization}', [OrganizationController::class, 'destroy'])->name('organizations.destroy');
        Route::post('organizations/{organization}/users', [OrganizationUserController::class, 'store'])->name('organizations.users.store');
        Route::delete('organizations/{organization}/users/{user}', [OrganizationUserController::class, 'destroy'])->name('organizations.users.destroy');

        // ===== Murojaatlar (citizen appeals) =====
        Route::get('appeals', [CitizenAppealController::class, 'index'])->name('appeals.index');
        Route::get('appeals/create', [CitizenAppealController::class, 'create'])->name('appeals.create');
        Route::post('appeals', [CitizenAppealController::class, 'store'])->name('appeals.store');
        Route::get('appeals/{appeal}', [CitizenAppealController::class, 'show'])->name('appeals.show');
        Route::get('appeals/{appeal}/edit', [CitizenAppealController::class, 'edit'])->name('appeals.edit');
        Route::match(['put', 'patch'], 'appeals/{appeal}', [CitizenAppealController::class, 'update'])->name('appeals.update');
        Route::delete('appeals/{appeal}', [CitizenAppealController::class, 'destroy'])->name('appeals.destroy');
        Route::post('appeals/{appeal}/triage', [CitizenAppealController::class, 'triage'])->name('appeals.triage');
        Route::post('appeals/{appeal}/decisions', [CitizenAppealController::class, 'recordDecision'])->name('appeals.decisions.store');

        // ===== Foydalanuvchilar boshqaruvi (users) — show'siz (UserPolicy) =====
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::get('users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::match(['put', 'patch'], 'users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

        // ===== Katalog API — frontend dropdownlar uchun =====
        Route::prefix('catalogs')->name('catalogs.')->group(function () {
            Route::get('/regions', [CatalogController::class, 'regions'])->name('regions');
            Route::get('/regions/{region}/districts', [CatalogController::class, 'districts'])->name('districts');
            Route::get('/districts/{district}/mahallas', [CatalogController::class, 'mahallas'])->name('mahallas');
            Route::get('/nationalities', [CatalogController::class, 'nationalities'])->name('nationalities');
            Route::get('/specialties', [CatalogController::class, 'specialties'])->name('specialties');
            Route::get('/departments', [CatalogController::class, 'departments'])->name('departments');
            Route::get('/positions', [CatalogController::class, 'positions'])->name('positions');
            Route::get('/departments/{department}/positions', [CatalogController::class, 'positions'])->name('department.positions');
        });
    });
