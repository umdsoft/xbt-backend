<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Console;

use App\Domains\Advisor\Outreach\Services\Sending\SendPreflight;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Run before switching OUTREACH_SEND_MODE to live: DNS/FCrDNS, SPF, DKIM,
 * DMARC, sender identity, mailbox pool and SMTP login. Optionally sends one
 * plain test letter (e.g. to a Gmail address) through the outreach mailer.
 */
class SendPreflightCommand extends Command
{
    protected $signature = 'outreach:send-preflight {--test-to= : Also send one test letter to this address}';

    protected $description = 'Check that everything required for live outreach sending is in place';

    public function handle(SendPreflight $preflight): int
    {
        $results = $preflight->run();

        $this->table(['check', 'status', 'detail'], array_map(
            fn (array $r) => [$r['check'], strtoupper($r['status']), $r['detail']],
            $results,
        ));

        $ok = SendPreflight::passed($results);
        $ok ? $this->info('Ready for live sending.') : $this->error('NOT ready for live sending: fix every FAIL first.');

        if ($to = $this->option('test-to')) {
            $ok = $this->sendTest((string) $to) && $ok;
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function sendTest(string $to): bool
    {
        $from = (string) config('outreach.send.test_from', config('mail.from.address'));

        try {
            Mail::mailer('outreach')->raw(
                "Outreach preflight test letter.\nIf you can read this in the inbox (not spam), delivery works.\n",
                fn ($m) => $m->from($from)->to($to)->subject('Outreach preflight test'),
            );
        } catch (Throwable $e) {
            $this->error('Test letter refused by Mailcow: '.$e->getMessage());

            return false;
        }

        $this->info("Test letter handed to Mailcow for {$to}. Check that inbox (and spam) — a remote refusal arrives later as a bounce.");

        return true;
    }
}
