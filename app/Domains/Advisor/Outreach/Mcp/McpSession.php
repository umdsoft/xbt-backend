<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Mcp;

use App\Domains\Advisor\Outreach\Support\Actor;
use RuntimeException;

/**
 * The authenticated MCP caller for the current request. Filled by
 * AuthenticateMcpToken; tools read the Actor from here. A tool running without
 * it (misconfigured route) fails closed.
 */
final class McpSession
{
    private ?Actor $actor = null;

    public function start(Actor $actor): void
    {
        $this->actor = $actor;
    }

    public function actor(): Actor
    {
        return $this->actor ?? throw new RuntimeException('MCP call without an authenticated token.');
    }
}
