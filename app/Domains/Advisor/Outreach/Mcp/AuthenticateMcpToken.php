<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Mcp;

use App\Domains\Advisor\Outreach\Services\McpTokenService;
use App\Domains\Advisor\Outreach\Support\Actor;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer authentication for the outreach MCP endpoint. Accepts ONLY outreach
 * MCP tokens (not Sanctum tokens, not session cookies), so a leaked MCP token
 * opens nothing but these tools, and advisor API credentials open nothing here.
 */
final class AuthenticateMcpToken
{
    public function __construct(
        private readonly McpTokenService $tokens,
        private readonly McpSession $session,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $resolved = $this->tokens->verify($request->bearerToken(), $request->ip());

        if ($resolved === null) {
            // Picked up by the server log shipper / SOC alerting.
            Log::warning('outreach.mcp.auth_failed', [
                'ip' => $request->ip(),
                'has_token' => $request->bearerToken() !== null,
                'user_agent' => substr((string) $request->userAgent(), 0, 200),
            ]);

            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        [$token, $user] = $resolved;

        Auth::setUser($user);
        $request->attributes->set('outreach_mcp_token_id', $token->id);
        $this->session->start(Actor::mcp($user, $token->id, $request->ip()));

        return $next($request);
    }
}
