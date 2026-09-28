<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Services\Sending;

use App\Domains\Advisor\Outreach\Models\Message;
use App\Domains\Advisor\Outreach\Models\Sender;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Chooses the mailbox for the next letter (PLAN-send.md §1a): usable, under
 * today's warm-up cap, and rested since its last letter. The least-used
 * mailbox wins, so volume spreads evenly across the pool.
 */
final class SenderPool
{
    /** Statuses that mean "this mailbox spent a letter on it". */
    private const SPENT = [Message::SENDING, Message::SENT, Message::REPLIED, Message::BOUNCED, Message::SEND_UNKNOWN];

    public function pick(Carbon $now): ?Sender
    {
        $day = $this->day($now);

        return Sender::query()->where('active', true)->whereNull('paused_at')->get()
            ->map(fn (Sender $s): array => ['sender' => $s, 'used' => $this->usedOn($s, $day)])
            ->filter(fn (array $x): bool => $x['used'] < $x['sender']->capOn($day) && $this->rested($x['sender'], $now))
            ->sortBy('used')
            ->first()['sender'] ?? null;
    }

    public function usedOn(Sender $sender, Carbon $day): int
    {
        return Message::query()
            ->where('sender_id', $sender->id)
            ->whereIn('status', self::SPENT)
            ->where('claimed_at', '>=', $day->copy()->startOfDay()->format('Y-m-d H:i:sP'))
            ->where('claimed_at', '<', $day->copy()->addDay()->startOfDay()->format('Y-m-d H:i:sP'))
            ->count();
    }

    /** Remember when this mailbox may send again (random human-like gap). */
    public function markUsed(Sender $sender, Carbon $now): void
    {
        $gap = random_int((int) config('outreach.send.spacing_min', 180), (int) config('outreach.send.spacing_max', 420));
        Cache::put($this->restKey($sender), $now->copy()->addSeconds($gap)->getTimestamp(), 86400);
    }

    /** Today in the counters' zone (midnight reset in Tashkent, not UTC). */
    public function day(Carbon $now): Carbon
    {
        return $now->copy()->setTimezone((string) config('outreach.send.cap_timezone', 'Asia/Tashkent'))->startOfDay();
    }

    private function rested(Sender $sender, Carbon $now): bool
    {
        return (int) Cache::get($this->restKey($sender), 0) <= $now->getTimestamp();
    }

    private function restKey(Sender $sender): string
    {
        return 'outreach-sender-rest:'.$sender->id;
    }
}
