<?php

declare(strict_types=1);

use App\Domains\Advisor\Outreach\Http\Controllers\ActivityController;
use App\Domains\Advisor\Outreach\Http\Controllers\ApprovalController;
use App\Domains\Advisor\Outreach\Http\Controllers\CompanyController;
use App\Domains\Advisor\Outreach\Http\Controllers\InboxController;
use App\Domains\Advisor\Outreach\Http\Controllers\MailController;
use App\Domains\Advisor\Outreach\Http\Controllers\McpTokenController;
use App\Domains\Advisor\Outreach\Http\Controllers\ReferenceController;
use App\Domains\Advisor\Outreach\Mcp\AuthenticateMcpToken;
use App\Domains\Advisor\Outreach\Mcp\OutreachServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

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

        // Incoming mail, series, meetings, opt-out, sanctions (docs/outreach/API-contract.md "Mail").
        Route::get('/replies', [InboxController::class, 'index'])->name('replies.index');
        Route::get('/replies/{id}', [InboxController::class, 'show'])->name('replies.show');
        Route::post('/replies/{id}/classify', [InboxController::class, 'classify'])->name('replies.classify');
        Route::post('/replies/{id}/handled', [InboxController::class, 'handled'])->name('replies.handled');
        Route::get('/companies/{id}/thread', [InboxController::class, 'thread'])->name('companies.thread');
        Route::post('/companies/{id}/sanctions', [MailController::class, 'sanctions'])->name('companies.sanctions');
        Route::post('/contacts/{id}/unsubscribe', [MailController::class, 'unsubscribe'])->name('contacts.unsubscribe');
        Route::post('/series', [MailController::class, 'storeSeries'])->name('series.store');
        Route::get('/meetings', [MailController::class, 'meetings'])->name('meetings.index');
        Route::post('/meetings', [MailController::class, 'storeMeeting'])->name('meetings.store');
        Route::patch('/meetings/{id}', [MailController::class, 'updateMeeting'])->name('meetings.update');

        Route::get('/mcp-tokens', [McpTokenController::class, 'index'])->name('mcp-tokens.index');
        Route::post('/mcp-tokens', [McpTokenController::class, 'store'])->name('mcp-tokens.store');
        Route::post('/mcp-tokens/{id}/revoke', [McpTokenController::class, 'revoke'])->name('mcp-tokens.revoke');
        Route::get('/mcp-info', [McpTokenController::class, 'info'])->name('mcp-info');
    });

/*
 * MCP endpoint for Claude. Authenticated ONLY by an outreach MCP token (no
 * Sanctum, no session); a request budget per token on top of per-tool write
 * caps. See docs/outreach/README-mcp.md.
 */
Mcp::web(config('outreach.mcp.path'), OutreachServer::class)
    ->middleware([AuthenticateMcpToken::class, 'throttle:outreach-mcp'])
    ->name('api.outreach.mcp');
