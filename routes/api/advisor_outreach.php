<?php

declare(strict_types=1);

use App\Domains\Advisor\Outreach\Http\Controllers\ActivityController;
use App\Domains\Advisor\Outreach\Http\Controllers\ApprovalController;
use App\Domains\Advisor\Outreach\Http\Controllers\CompanyController;
use App\Domains\Advisor\Outreach\Http\Controllers\ReferenceController;
use Illuminate\Support\Facades\Route;

/*
 * ADVISOR — OUTREACH CRM (foreign IT investors). Contract:
 * docs/outreach/API-contract.md. Same guards as the rest of the advisor API;
 * per-permission and per-lead checks happen in OutreachGate.
 */
Route::middleware(['auth:sanctum', 'advisor'])
    ->prefix('advisor/outreach')
    ->name('api.advisor.outreach.')
    ->group(function () {
        Route::get('/countries', [ReferenceController::class, 'countries'])->name('countries');
        Route::get('/advisors', [ReferenceController::class, 'advisors'])->name('advisors');
        Route::get('/stats', [ReferenceController::class, 'stats'])->name('stats');

        Route::get('/companies', [CompanyController::class, 'index'])->name('companies.index');
        Route::post('/companies', [CompanyController::class, 'store'])->name('companies.store');
        Route::get('/companies/{id}', [CompanyController::class, 'show'])->name('companies.show');
        Route::post('/companies/{id}/stage', [CompanyController::class, 'stage'])->name('companies.stage');
        Route::post('/companies/{id}/owner', [CompanyController::class, 'owner'])->name('companies.owner');
        Route::get('/dedupe', [CompanyController::class, 'dedupe'])->name('dedupe');

        Route::post('/contacts', [ActivityController::class, 'contact'])->name('contacts.upsert');
        Route::post('/touches', [ActivityController::class, 'touch'])->name('touches.store');

        Route::get('/approvals', [ApprovalController::class, 'index'])->name('approvals.index');
        Route::post('/approvals/bulk-approve', [ApprovalController::class, 'bulkApprove'])->name('approvals.bulk-approve');
        Route::post('/approvals/bulk-reject', [ApprovalController::class, 'bulkReject'])->name('approvals.bulk-reject');
        Route::patch('/messages/{id}', [ApprovalController::class, 'edit'])->name('messages.edit');
        Route::post('/messages/{id}/approve', [ApprovalController::class, 'approve'])->name('messages.approve');
        Route::post('/messages/{id}/reject', [ApprovalController::class, 'reject'])->name('messages.reject');
    });
