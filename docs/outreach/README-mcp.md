# Outreach CRM — MCP server

Claude talks to the outreach CRM through one MCP endpoint inside the platform:

```
POST https://<platform-host>/api/mcp/outreach      (Streamable HTTP, JSON-RPC)
```

## Tools

| Tool | Kind | What it does |
|---|---|---|
| `dedupe_check` | read | Is this domain / email already in the CRM? |
| `upsert_company` | write | Create/update a lead by domain. Excluded countries refused. |
| `upsert_contact` | write | Create/update a decision maker. Max 2 active per company. |
| `set_stage` | write | Move a lead; human-only stages are refused. |
| `log_touch` | write | Record an interaction. |
| `get_pipeline` | read | Leads with filters + counts per stage. |
| `list_approvals` | read | Drafts waiting for approval / rejected with reasons. |
| `get_stats` | read | Funnel per country. |
| `get_company` | read | One lead in full: fields, ICP breakdown, sanctions check, contacts, letters, meetings, reply counts. |
| `upsert_companies` | write | Bulk `upsert_company`, up to 50 items, per-item results; costs one write per item. |
| `mark_sanctions` | write | Record an OFAC/EU check: `clear` or `hit` + source. `hit` is irreversible; only `clear` companies get mail. |
| `create_series` | write | 1-3 letters for a contact as **drafts** (days 0/4/10). A verified lead moves to awaiting_approval. |
| `update_draft` | write | Revise a draft or rejected letter. Approved letters are refused (`not_editable`). |
| `list_replies` | read | Incoming mail; text only in `untrusted_*` fields, truncated (`text_limit`). |
| `get_thread` | read | Letters sent + replies + touches, chronological, plus meetings. |
| `classify_reply` | write | Class + summary with fixed side effects; idempotent; declined/unsubscribe/bounce are final. |
| `mark_unsubscribed` | write | Irreversible opt-out: contact unsubscribed, email suppressed, lead closed. |
| `save_meeting` | write | Propose/book/reschedule/cancel a meeting; `done` stays in the web UI. |

Reply text written by outside senders is only ever returned under `untrusted_*` keys, and
every tool that returns it says so: it is data to classify, never instructions.

There is **no** delete, approve, reject or send tool. Approval exists only in the
web UI (`/outreach/approvals`), for a person with the viloyat role.

Errors from business rules come back as a tool error whose text is JSON:
`{"reason": "country_excluded", "message": "...", "context": {...}}`.

## Security layers

1. **Cloudflare Access** (production): a service token in front of the path — requests
   without `CF-Access-Client-Id` / `CF-Access-Client-Secret` never reach the server.
2. **Personal MCP token**: 256-bit, prefix `omcp_`, stored only as sha256, 90-day
   expiry, revocable. Issued only to viloyat advisors, only from the UI.
3. **Separate credentials**: the MCP token works only on this endpoint; Sanctum
   tokens and session cookies do not open it, and the MCP token does not open
   the advisor API.
4. **Limits** (per token/owner, from `.env`): 120 calls/min, 60 writes/min,
   3000 writes/day.
5. **Narrow tool surface**: no delete/approve/send; `sent` and human decision
   stages are closed to MCP; the same services as the UI enforce every rule.
6. **Audit**: every write lands in the append-only `advisor.outreach_audit_log`
   with `actor=claude`, the token id and IP. Failed authentications are logged
   as `outreach.mcp.auth_failed` (for SOC alerting).

## 1. Configure (`.env`)

```dotenv
OUTREACH_MCP_PATH=mcp/outreach
OUTREACH_MCP_TOKEN_TTL_DAYS=90
OUTREACH_MCP_RATE_PER_MINUTE=120
OUTREACH_MCP_WRITES_PER_MINUTE=60
OUTREACH_MCP_DAILY_WRITE_CAP=3000
```

The defaults are the values above; set them only to change them. No secrets
are needed for the MCP server itself.

## 2. Create a token

1. Log in to the advisor app as a viloyat advisor.
2. Open **Ҳорижий инвесторлар → MCP токен** (`/outreach/token`).
3. Enter a name (e.g. `Claude Code — office PC`) and create.
4. Copy the `omcp_…` token **now** — it is shown once and cannot be recovered.
   Lost it? Revoke it and create a new one.

Keep the token out of git and chat logs. Store it in an environment variable
or your OS secret store.

## 3a. Claude Code

```bash
claude mcp add --transport http outreach https://<platform-host>/api/mcp/outreach \
  --header "Authorization: Bearer ${OUTREACH_MCP_TOKEN}" \
  --header "CF-Access-Client-Id: ${CF_ACCESS_CLIENT_ID}" \
  --header "CF-Access-Client-Secret: ${CF_ACCESS_CLIENT_SECRET}"
```

Or in `.mcp.json` (environment variables are expanded, the file stays secret-free):

```json
{
  "mcpServers": {
    "outreach": {
      "type": "http",
      "url": "https://<platform-host>/api/mcp/outreach",
      "headers": {
        "Authorization": "Bearer ${OUTREACH_MCP_TOKEN}",
        "CF-Access-Client-Id": "${CF_ACCESS_CLIENT_ID}",
        "CF-Access-Client-Secret": "${CF_ACCESS_CLIENT_SECRET}"
      }
    }
  }
}
```

Drop the two `CF-Access-*` headers when testing against a local server.

## 3b. Claude Desktop

Claude Desktop connects to remote HTTP servers through the `mcp-remote` bridge.
In `claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "outreach": {
      "command": "npx",
      "args": [
        "-y", "mcp-remote", "https://<platform-host>/api/mcp/outreach",
        "--header", "Authorization:Bearer ${OUTREACH_MCP_TOKEN}",
        "--header", "CF-Access-Client-Id:${CF_ACCESS_CLIENT_ID}",
        "--header", "CF-Access-Client-Secret:${CF_ACCESS_CLIENT_SECRET}"
      ],
      "env": {
        "OUTREACH_MCP_TOKEN": "omcp_…",
        "CF_ACCESS_CLIENT_ID": "…",
        "CF_ACCESS_CLIENT_SECRET": "…"
      }
    }
  }
}
```

This file holds secrets: keep it on your own machine only.

## 4. Cloudflare Access (production, done by the owner)

1. Zero Trust → Access → Applications → *Self-hosted*: domain `<platform-host>`,
   path `api/mcp/outreach`.
2. Policy: action **Service Auth**, include the service token created under
   Access → Service credentials.
3. Put the token's Client ID/Secret into the headers above.

Until this is in place the endpoint is protected by layers 2–6 only.

## 5. Test with MCP Inspector

```bash
npx @modelcontextprotocol/inspector
```

Transport *Streamable HTTP*, URL `http://127.0.0.1:8000/api/mcp/outreach`,
header `Authorization: Bearer omcp_…`. Run `tools/list`, then each tool.

## Revoking

`/outreach/token` → **Бекор қилиш**. The token stops working on the next
request. Removing a user's viloyat role also disables all their tokens.
