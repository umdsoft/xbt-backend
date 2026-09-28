<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Services;

use App\Domains\Advisor\Outreach\Models\McpToken;
use App\Domains\Advisor\Outreach\Support\Actor;
use App\Domains\Advisor\Outreach\Support\RuleViolation;
use App\Domains\Advisor\Outreach\Support\Stage;
use App\Domains\Advisor\Support\AdvisorAccess;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Personal MCP tokens (PLAN-crm.md §5, layer 2).
 *
 * - 256 random bits, prefix `omcp_` so a leaked token is recognisable in logs
 *   and secret scanners; only its sha256 is stored.
 * - Issued only from the UI and only to users with `outreach.mcp` (viloyat).
 * - Expire after `outreach.mcp.token_ttl_days`; revocable at any time.
 * - Verified again on every request: a revoked role disables the token too.
 */
final class McpTokenService
{
    public const PREFIX = 'omcp_';

    private const LAST_USED_RESOLUTION_SECONDS = 60;

    public function __construct(
        private readonly AdvisorAccess $access,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{token: McpToken, plain_text: string} */
    public function issue(Actor $actor, string $name): array
    {
        $this->requireOwner($actor);

        $plain = self::PREFIX.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $token = DB::connection('advisor')->transaction(function () use ($actor, $name, $plain): McpToken {
            $token = McpToken::query()->create([
                'user_id' => $actor->userId(),
                'name' => $name,
                'token_hash' => hash('sha256', $plain),
                'expires_at' => now()->addDays((int) config('outreach.mcp.token_ttl_days', 90)),
            ]);
            $this->audit->log($actor, 'mcp_token.issued', 'mcp_token', $token->id, ['name' => $name, 'expires_at' => $token->expires_at->toIso8601String()]);

            return $token;
        });

        return ['token' => $token, 'plain_text' => $plain];
    }

    public function revoke(Actor $actor, string $tokenId): McpToken
    {
        $this->requireOwner($actor);

        return DB::connection('advisor')->transaction(function () use ($actor, $tokenId): McpToken {
            $token = (Str::isUuid($tokenId) ? McpToken::query()->where('user_id', $actor->userId())->lockForUpdate()->find($tokenId) : null)
                ?? throw new RuleViolation(RuleViolation::NOT_FOUND, 'Токен топилмади.');

            if ($token->revoked_at === null) {
                $token->forceFill(['revoked_at' => now()])->save();
                $this->audit->log($actor, 'mcp_token.revoked', 'mcp_token', $token->id);
            }

            return $token;
        });
    }

    /**
     * Resolve a presented bearer token to [token, user], or null when it is
     * unknown, expired, revoked, or its owner lost access.
     *
     * @return array{0: McpToken, 1: User}|null
     */
    public function verify(?string $plain, ?string $ip): ?array
    {
        if ($plain === null || ! str_starts_with($plain, self::PREFIX) || strlen($plain) > 128) {
            return null;
        }

        $token = McpToken::query()->where('token_hash', hash('sha256', $plain))->first();
        if ($token === null || ! $token->isUsable()) {
            return null;
        }

        $user = User::query()->find($token->user_id);
        if ($user === null || ! $user->is_active || ! $this->access->can($user, OutreachGate::MCP)) {
            return null;
        }

        if ($token->last_used_at === null || $token->last_used_at->diffInSeconds(now()) >= self::LAST_USED_RESOLUTION_SECONDS) {
            $token->forceFill(['last_used_at' => now(), 'last_used_ip' => $ip])->save();
        }

        return [$token, $user];
    }

    private function requireOwner(Actor $actor): void
    {
        if ($actor->via !== Stage::VIA_UI || $actor->user === null || ! $this->access->can($actor->user, OutreachGate::MCP)) {
            throw new RuleViolation(RuleViolation::FORBIDDEN, 'MCP токенни фақат вилоят маслаҳатчиси интерфейс орқали бошқаради.');
        }
    }
}
