<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Console;

use App\Domains\Advisor\Outreach\Services\Inbox\ImapInbox;
use App\Domains\Advisor\Outreach\Services\Inbox\InboundMail;
use App\Domains\Advisor\Outreach\Services\Inbox\InboundProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads replies, auto-replies and bounces from the outreach inbox on Mailcow
 * (every 5 minutes). Does nothing until OUTREACH_INBOX_HOST is configured.
 */
class PollInboxCommand extends Command
{
    protected $signature = 'outreach:poll-inbox {--limit=100}';

    protected $description = 'Import replies and bounces for outreach letters from the IMAP inbox';

    public function handle(ImapInbox $inbox, InboundProcessor $processor): int
    {
        if ((string) config('outreach.inbox.host') === '') {
            $this->line('inbox not configured');

            return self::SUCCESS;
        }

        $stored = 0;
        $read = $inbox->each(function (string $raw) use ($processor, &$stored): void {
            try {
                $stored += $processor->process(InboundMail::parse($raw)) === null ? 0 : 1;
            } catch (Throwable $e) {
                // One malformed mail must not block the rest; it stays in the log for a person.
                Log::error('outreach.inbox: could not process a message', ['error' => $e->getMessage()]);
            }
        }, max(1, (int) $this->option('limit')));

        $this->line("read {$read}, stored {$stored}");

        return self::SUCCESS;
    }
}
