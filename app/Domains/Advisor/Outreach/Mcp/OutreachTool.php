<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Mcp;

use App\Domains\Advisor\Outreach\Http\OutreachPresenter;
use App\Domains\Advisor\Outreach\Support\Actor;
use App\Domains\Advisor\Outreach\Support\RuleViolation;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Base for outreach MCP tools.
 *
 * Tools are thin: validate shape, call the same service the UI uses (so every
 * business rule and audit record is identical), present JSON. A RuleViolation
 * becomes a tool error `{reason, message, context}` the model can act on.
 * Write tools additionally pass the per-minute and daily write caps.
 */
abstract class OutreachTool extends Tool
{
    private const MINUTE = 60;

    private const DAY = 86400;

    protected function actor(): Actor
    {
        return app(McpSession::class)->actor();
    }

    protected function present(): OutreachPresenter
    {
        return app(OutreachPresenter::class);
    }

    /** @param  callable(): array<string, mixed>  $fn */
    protected function read(callable $fn): Response
    {
        try {
            return Response::json($fn());
        } catch (RuleViolation $e) {
            return $this->violation($e);
        }
    }

    /**
     * @param  callable(): array<string, mixed>  $fn
     * @param  int  $cost  write units the call may use (a bulk call costs one per item)
     */
    protected function write(callable $fn, int $cost = 1): Response
    {
        $limited = $this->consumeWriteQuota($this->actor(), max(1, $cost));

        return $limited ?? $this->read($fn);
    }

    private function consumeWriteQuota(Actor $actor, int $cost): ?Response
    {
        $user = (string) $actor->userId();
        $perMinute = (int) config('outreach.mcp.writes_per_minute', 60);
        $perDay = (int) config('outreach.mcp.daily_write_cap', 3000);
        $minuteKey = 'outreach-mcp-w:'.$user;
        $dayKey = 'outreach-mcp-d:'.$user.':'.now()->toDateString();

        if ($cost > $perDay || $cost > $perMinute) {
            return $this->limitError('write_rate_limited', "One call may use at most {$perMinute} write units.", $perMinute);
        }

        // Reserve first, then check: the cache increment is atomic, so two
        // concurrent bulk calls can never both slip under the cap (a
        // read-then-increment would let each see the old count).
        if (RateLimiter::increment($dayKey, self::DAY, $cost) > $perDay) {
            RateLimiter::decrement($dayKey, self::DAY, $cost);

            return $this->limitError('daily_write_cap', "Daily write cap of {$perDay} reached. Resume tomorrow.", $perDay);
        }
        if (RateLimiter::increment($minuteKey, self::MINUTE, $cost) > $perMinute) {
            RateLimiter::decrement($minuteKey, self::MINUTE, $cost);
            RateLimiter::decrement($dayKey, self::DAY, $cost);

            return $this->limitError('write_rate_limited', 'Too many writes. Retry in '.RateLimiter::availableIn($minuteKey).' s.', $perMinute);
        }

        return null;
    }

    private function limitError(string $reason, string $message, int $limit): Response
    {
        return Response::error((string) json_encode(['reason' => $reason, 'message' => $message, 'context' => ['limit' => $limit]]));
    }

    private function violation(RuleViolation $e): Response
    {
        return Response::error((string) json_encode([
            'reason' => $e->reason,
            'message' => $e->getMessage(),
            'context' => (object) $e->context,
        ], JSON_UNESCAPED_UNICODE));
    }
}
