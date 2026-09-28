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
        // off  — nothing is sent (default; safe for every environment)
        // test — every letter goes to OUTREACH_SEND_TEST_RECIPIENT instead of the real address
        // live — real recipients; requires the sender identity below
        'mode' => env('OUTREACH_SEND_MODE', 'off'),
        'test_recipient' => env('OUTREACH_SEND_TEST_RECIPIENT'),

        // Letters per mailbox per day by warm-up week (index 0 = first week);
        // after the ramp each mailbox uses its own daily_cap_max.
        'warmup' => array_map('intval', explode(',', (string) env('OUTREACH_SEND_WARMUP', '10,20,30'))),

        // Daily counters reset at midnight in this zone.
        'cap_timezone' => env('OUTREACH_SEND_CAP_TIMEZONE', 'Asia/Tashkent'),

        // Recipient's business hours (recipient country's time zone), Mon–Fri.
        'window_start_hour' => (int) env('OUTREACH_SEND_WINDOW_START', 9),
        'window_end_hour' => (int) env('OUTREACH_SEND_WINDOW_END', 16),
        'default_recipient_timezone' => env('OUTREACH_SEND_DEFAULT_TZ', 'Europe/Berlin'),

        // Random pause between two letters from the same mailbox, seconds.
        'spacing_min' => (int) env('OUTREACH_SEND_SPACING_MIN', 180),
        'spacing_max' => (int) env('OUTREACH_SEND_SPACING_MAX', 420),

        // At most this many letters per recipient domain per day (all mailboxes).
        'per_domain_daily' => (int) env('OUTREACH_SEND_PER_DOMAIN_DAILY', 2),

        // Follow-ups: days after the FIRST letter was actually sent (SPEC §4.3: 0/4/10).
        'follow_up_days' => [2 => 4, 3 => 10],

        // Temporary SMTP failures: retry up to max_attempts, backing off.
        'max_attempts' => 3,
        'retry_minutes' => 30,

        // Letters sent per scheduler run (runs every minute).
        'batch' => (int) env('OUTREACH_SEND_BATCH', 10),

        // Circuit breaker: this many consecutive failed attempts stops everything.
        'breaker_consecutive_failures' => 5,

        // Preflight (outreach:send-preflight): the public IP letters leave from,
        // the name Mailcow introduces itself with, and the DKIM selector.
        'egress_ip' => env('OUTREACH_EGRESS_IP', '89.249.62.68'),
        'helo_name' => env('OUTREACH_HELO_NAME', 'mail.digital-xorazm.uz'),
        'dkim_selector' => env('OUTREACH_DKIM_SELECTOR', 'dkim'),
        'test_from' => env('OUTREACH_PREFLIGHT_FROM'),

        // Envelope sender for bounces (a Mailcow mailbox read by the poller).
        'bounce_address' => env('OUTREACH_BOUNCE_ADDRESS'),

        // Real sender identity printed under every letter (SPEC §4.3). Required in live mode.
        'identity' => [
            'name' => env('OUTREACH_SENDER_NAME'),
            'title' => env('OUTREACH_SENDER_TITLE'),
            'organization' => env('OUTREACH_SENDER_ORG'),
            'address' => env('OUTREACH_SENDER_ADDRESS'),
        ],
    ],

    /*
     * Central outreach inbox on Mailcow: pool mailboxes forward replies and
     * bounces here; `outreach:poll-inbox` reads it every 5 minutes.
     * Empty host = poller disabled.
     */
    'inbox' => [
        'host' => env('OUTREACH_INBOX_HOST'),
        'port' => (int) env('OUTREACH_INBOX_PORT', 993),
        'encryption' => env('OUTREACH_INBOX_ENCRYPTION', 'ssl'),
        'username' => env('OUTREACH_INBOX_USERNAME'),
        'password' => env('OUTREACH_INBOX_PASSWORD'),
        'folder' => env('OUTREACH_INBOX_FOLDER', 'INBOX'),
        'validate_cert' => (bool) env('OUTREACH_INBOX_VALIDATE_CERT', true),
    ],
];
