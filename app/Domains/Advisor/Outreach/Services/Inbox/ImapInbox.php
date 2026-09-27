<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Services\Inbox;

use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\MessageInterface;

/**
 * Reads unseen mail from the central outreach inbox on Mailcow (pool
 * mailboxes forward replies and bounces there). Thin adapter: it only hands
 * raw RFC 822 text to a callback and marks the mail seen after the callback
 * succeeded, so a crash never loses a message.
 */
class ImapInbox
{
    /** @param  callable(string): void  $handle */
    public function each(callable $handle, int $limit): int
    {
        $mailbox = new Mailbox([
            'host' => (string) config('outreach.inbox.host'),
            'port' => (int) config('outreach.inbox.port', 993),
            'encryption' => (string) config('outreach.inbox.encryption', 'ssl'),
            'username' => (string) config('outreach.inbox.username'),
            'password' => (string) config('outreach.inbox.password'),
            'validate_cert' => (bool) config('outreach.inbox.validate_cert', true),
        ]);

        $folder = $mailbox->folders()->findOrFail((string) config('outreach.inbox.folder', 'INBOX'));
        $messages = $folder->messages()->unseen()->withHeaders()->withBody()->limit($limit)->get();

        $done = 0;
        /** @var MessageInterface $message */
        foreach ($messages as $message) {
            $handle((string) $message);
            $message->markSeen();
            $done++;
        }

        $mailbox->disconnect();

        return $done;
    }
}
