<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Services\Sending;

use App\Domains\Advisor\Outreach\Services\AuditLogger;
use App\Domains\Advisor\Outreach\Support\Actor;
use Illuminate\Support\Facades\DB;

/**
 * Global sending switches (PLAN-send.md §8):
 *  - pause:   set/cleared by a person in the UI;
 *  - breaker: opened automatically on bad delivery signals, closed only by a person.
 * While either is on, nothing is sent; the queue is kept.
 */
final class SendControl
{
    public const PAUSE = 'pause';

    public const BREAKER = 'breaker';

    public function __construct(private readonly AuditLogger $audit) {}

    /** Why sending is stopped right now, or null when it may run. */
    public function stopReason(): ?string
    {
        foreach ([self::BREAKER, self::PAUSE] as $key) {
            if ($this->get($key) !== null) {
                return $key;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public function get(string $key): ?array
    {
        $raw = DB::connection('advisor')->table('outreach_send_state')->where('key', $key)->value('value');
        $value = $raw === null ? null : json_decode((string) $raw, true);

        return is_array($value) && $value !== [] ? $value : null;
    }

    public function stop(Actor $actor, string $key, string $reason): void
    {
        $this->put($key, ['reason' => $reason, 'at' => now()->toIso8601String(), 'by' => $actor->userId() ?? $actor->kind()]);
        $this->audit->log($actor, "send.{$key}_on", 'send_state', $key, ['reason' => $reason]);
    }

    public function clear(Actor $actor, string $key): void
    {
        $this->put($key, null);
        $this->audit->log($actor, "send.{$key}_off", 'send_state', $key);
    }

    /** @param  array<string, mixed>|null  $value */
    private function put(string $key, ?array $value): void
    {
        DB::connection('advisor')->table('outreach_send_state')->updateOrInsert(
            ['key' => $key],
            ['value' => $value === null ? null : json_encode($value), 'updated_at' => now()->toIso8601String()],
        );
    }
}
