<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Console;

use App\Domains\Advisor\Outreach\Services\Sending\SendDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Scheduler entry point for automatic sending (runs every minute).
 * Does nothing unless OUTREACH_SEND_MODE is `test` or `live`.
 */
class SendDueLettersCommand extends Command
{
    protected $signature = 'outreach:send-due';

    protected $description = 'Send approved outreach letters that are due (respects caps, windows, pause and breaker)';

    public function handle(SendDispatcher $dispatcher): int
    {
        $counts = $dispatcher->run(Carbon::now());

        $this->line($counts === [] ? 'nothing sent' : json_encode($counts));

        return self::SUCCESS;
    }
}
