<?php

declare(strict_types=1);

/*
 * Outreach CRM (foreign IT investors) — docs/outreach/PLAN-crm.md §5.
 * All limits are enforced server-side; the MCP client cannot change them.
 */
return [
    'mcp' => [
        // Public path of the MCP endpoint (under the /api prefix).
        'path' => env('OUTREACH_MCP_PATH', 'mcp/outreach'),

        // Personal MCP tokens expire after this many days.
        'token_ttl_days' => (int) env('OUTREACH_MCP_TOKEN_TTL_DAYS', 90),

        // Every MCP request, per token.
        'rate_per_minute' => (int) env('OUTREACH_MCP_RATE_PER_MINUTE', 120),

        // Write tools only, per token owner.
        'writes_per_minute' => (int) env('OUTREACH_MCP_WRITES_PER_MINUTE', 60),
        'daily_write_cap' => (int) env('OUTREACH_MCP_DAILY_WRITE_CAP', 3000),
    ],

    /*
     * Automatic sending of approved letters — docs/outreach/PLAN-send.md.
     */
    'send' => [
        // Letters per mailbox per day by warm-up week (index 0 = first week);
        // after the ramp each mailbox uses its own daily_cap_max.
        'warmup' => array_map('intval', explode(',', (string) env('OUTREACH_SEND_WARMUP', '10,20,30'))),
    ],
];
