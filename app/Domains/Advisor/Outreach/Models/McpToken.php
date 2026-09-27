<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * An MCP access token. Only the sha256 hash is stored; the plain token is shown
 * once at creation. Valid only on the MCP route, never on the advisor API.
 */
class McpToken extends Model
{
    use HasUuids;

    protected $connection = 'advisor';

    protected $table = 'outreach_mcp_tokens';

    protected $fillable = [
        'user_id', 'name', 'token_hash', 'last_used_at', 'last_used_ip', 'expires_at', 'revoked_at',
    ];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
