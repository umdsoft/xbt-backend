<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Http\Controllers;

use App\Domains\Advisor\Outreach\Http\OutreachPresenter;
use App\Domains\Advisor\Outreach\Models\McpToken;
use App\Domains\Advisor\Outreach\Services\McpTokenService;
use App\Domains\Advisor\Outreach\Services\OutreachGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The viloyat advisor's personal MCP tokens (UI only). */
class McpTokenController extends OutreachController
{
    public function __construct(
        OutreachGate $gate,
        OutreachPresenter $present,
        private readonly McpTokenService $tokens,
    ) {
        parent::__construct($gate, $present);
    }

    public function index(Request $request): JsonResponse
    {
        $actor = $this->authorizeTo($request, OutreachGate::MCP);

        return response()->json([
            'data' => McpToken::query()->where('user_id', $actor->userId())
                ->orderByDesc('created_at')->get()->map(fn (McpToken $t) => $this->row($t))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);

        $issued = $this->tokens->issue($this->actor($request), $data['name']);

        return response()->json(['token' => $this->row($issued['token']), 'plain_text' => $issued['plain_text']], 201)
            ->header('Cache-Control', 'no-store');
    }

    public function revoke(Request $request, string $id): JsonResponse
    {
        return response()->json(['token' => $this->row($this->tokens->revoke($this->actor($request), $id))]);
    }

    public function info(Request $request): JsonResponse
    {
        $this->authorizeTo($request, OutreachGate::MCP);

        return response()->json([
            'endpoint_url' => url('api/'.config('outreach.mcp.path')),
            'rate_per_minute' => (int) config('outreach.mcp.rate_per_minute'),
            'writes_per_minute' => (int) config('outreach.mcp.writes_per_minute'),
            'daily_write_cap' => (int) config('outreach.mcp.daily_write_cap'),
            'token_ttl_days' => (int) config('outreach.mcp.token_ttl_days'),
        ]);
    }

    /** @return array<string, mixed> */
    private function row(McpToken $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'created_at' => $t->created_at?->toIso8601String(),
            'expires_at' => $t->expires_at?->toIso8601String(),
            'last_used_at' => $t->last_used_at?->toIso8601String(),
            'last_used_ip' => $t->last_used_ip,
            'revoked_at' => $t->revoked_at?->toIso8601String(),
        ];
    }
}
