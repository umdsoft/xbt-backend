<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Services\Sending;

/**
 * What SendGuard decided for one message at send time.
 *
 *  send    all checks passed
 *  hold    not now, keep it queued (pause, sanctions not yet checked, previous step pending)
 *  cancel  never send it (unsubscribed, suppressed, lead closed, ...)
 *  revert  text no longer matches the approval: back to draft for a new review
 *  skip    not a sendable message at all (not approved); leave it untouched
 */
final class GuardDecision
{
    public const SEND = 'send';

    public const HOLD = 'hold';

    public const CANCEL = 'cancel';

    public const REVERT = 'revert';

    public const SKIP = 'skip';

    private function __construct(
        public readonly string $action,
        public readonly ?string $reason,
    ) {}

    public static function send(): self
    {
        return new self(self::SEND, null);
    }

    public static function hold(string $reason): self
    {
        return new self(self::HOLD, $reason);
    }

    public static function cancel(string $reason): self
    {
        return new self(self::CANCEL, $reason);
    }

    public static function revert(string $reason): self
    {
        return new self(self::REVERT, $reason);
    }

    public static function skip(string $reason): self
    {
        return new self(self::SKIP, $reason);
    }

    public function allowsSending(): bool
    {
        return $this->action === self::SEND;
    }
}
