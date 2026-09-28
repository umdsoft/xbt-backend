<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Support;

use App\Models\User;

/**
 * Who performs an outreach write: an advisor in the UI, Claude through an MCP
 * token (acting for the token owner), or the system itself.
 *
 * Every service method that writes takes an Actor, so the audit log always
 * knows the channel and the responsible person.
 */
final class Actor
{
    private function __construct(
        public readonly ?User $user,
        public readonly string $via,
        public readonly ?string $mcpTokenId,
        public readonly ?string $ip,
    ) {}

    public static function ui(User $user, ?string $ip = null): self
    {
        return new self($user, Stage::VIA_UI, null, $ip);
    }

    public static function mcp(User $user, string $tokenId, ?string $ip = null): self
    {
        return new self($user, Stage::VIA_MCP, $tokenId, $ip);
    }

    public static function system(): self
    {
        return new self(null, Stage::VIA_SYSTEM, null, null);
    }

    /** Audit `actor` column: user | claude | system. */
    public function kind(): string
    {
        return match ($this->via) {
            Stage::VIA_UI => 'user',
            Stage::VIA_MCP => 'claude',
            default => 'system',
        };
    }

    public function userId(): ?string
    {
        return $this->user?->id !== null ? (string) $this->user->id : null;
    }
}
