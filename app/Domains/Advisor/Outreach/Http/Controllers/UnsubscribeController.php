<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Http\Controllers;

use App\Domains\Advisor\Outreach\Models\Contact;
use App\Domains\Advisor\Outreach\Models\Suppression;
use App\Domains\Advisor\Outreach\Services\ContactService;
use App\Domains\Advisor\Outreach\Support\Actor;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Public unsubscribe endpoint linked from every letter (SPEC §4.3, RFC 8058,
 * CLAUDE.md rule 5). The URL is signed with APP_KEY, so only a recipient of a
 * real letter can use it and nobody can unsubscribe someone else by guessing.
 *
 * GET  shows a confirmation page — link scanners that prefetch URLs must not
 *      unsubscribe people by accident.
 * POST unsubscribes immediately — this is what mail clients call for the
 *      one-click List-Unsubscribe-Post header.
 *
 * Recipients are foreign, so the page is in English.
 */
class UnsubscribeController extends Controller
{
    public function __construct(private readonly ContactService $contacts) {}

    public function show(Request $request, string $contact): Response
    {
        $found = $this->find($contact);
        if ($found === null) {
            return $this->page('Link no longer valid', 'This unsubscribe link is not valid.', 404);
        }

        if ($found->unsubscribed_at !== null) {
            return $this->page('Unsubscribed', 'You are already unsubscribed. You will not receive further messages from us.');
        }

        $action = e($request->fullUrl());

        return $this->page('Unsubscribe', 'Stop receiving messages from the Khorezm Regional Government investment team?',
            200, "<form method=\"post\" action=\"{$action}\"><button type=\"submit\">Unsubscribe</button></form>");
    }

    public function store(string $contact): Response
    {
        $found = $this->find($contact);
        if ($found === null) {
            return $this->page('Link no longer valid', 'This unsubscribe link is not valid.', 404);
        }

        DB::connection('advisor')->transaction(function () use ($found): void {
            if ($found->unsubscribed_at === null) {
                $this->contacts->upsert(Actor::system(), [
                    'company_id' => $found->company_id,
                    'contact_id' => $found->id,
                    'unsubscribed' => true,
                ]);
            }

            if ($found->email !== null) {
                Suppression::query()->firstOrCreate(['email' => strtolower($found->email)], ['reason' => 'unsubscribed']);
            }
        });

        return $this->page('Unsubscribed', 'You have been unsubscribed. You will not receive further messages from us.');
    }

    private function find(string $id): ?Contact
    {
        return Str::isUuid($id) ? Contact::query()->find($id) : null;
    }

    private function page(string $title, string $text, int $status = 200, string $extra = ''): Response
    {
        $title = e($title);
        $text = e($text);
        $html = <<<HTML
            <!doctype html><html lang="en"><head><meta charset="utf-8">
            <meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
            <title>{$title}</title>
            <style>body{font:16px/1.5 system-ui,sans-serif;max-width:32rem;margin:15vh auto;padding:0 1rem;color:#1e293b}
            button{font:inherit;padding:.6rem 1.2rem;border:0;border-radius:.4rem;background:#15803d;color:#fff;cursor:pointer}</style>
            </head><body><h1>{$title}</h1><p>{$text}</p>{$extra}</body></html>
            HTML;

        return response($html, $status)->header('Content-Type', 'text/html; charset=utf-8');
    }
}
