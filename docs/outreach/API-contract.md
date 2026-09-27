# Outreach CRM — REST API contract (backend ↔ advisor SPA)

Shared contract for the backend (`platform`) and the frontend (`advisor`) built in
parallel. Prefix: `/api/advisor/outreach`, Sanctum SPA cookie auth, same as
other advisor routes. JSON everywhere. Dates ISO-8601.

## Permissions (from `GET /api/advisor/me` → `permissions`)

| Permission | viloyat (`*`) | tuman | bolinma |
|---|---|---|---|
| `outreach.view` | ✓ | ✓ (own leads only) | — |
| `outreach.manage` | ✓ | ✓ (own leads only) | — |
| `outreach.all` (see every lead, owner filter, reassign owner, reopen declined) | ✓ | — | — |
| `outreach.approve` (approvals page, single + bulk) | ✓ | — | — |
| `outreach.mcp` (MCP token page) | ✓ | — | — |

## Errors

- `422` `{ message, reason, context }` — business rule. `reason` codes:
  `invalid_input, invalid_domain, country_unknown, country_excluded, duplicate,
  contact_limit, transition_not_allowed, transition_precondition, irreversible,
  not_approvable`. Laravel validation errors also 422 `{ message, errors }`.
- `403` no permission, `404` not found **or another advisor's lead**.
- `duplicate` context: `{ accessible:false, owner_name }` or
  `{ accessible:true, company_id, company_name, stage, tier, owner_name }`.

## Enums

- stage: `found, verified, awaiting_approval, sent, replied, meeting_booked,
  meeting_done, visit_or_mou, resident_or_office, closed_declined,
  closed_unsubscribed, blocked_sanctions`
- tier: `A, B, C`; created_via: `ui, mcp` (show "Claude" for `mcp`)
- industry: `outsourcing, bpo_kpo, logistics_dispatch, agro_it, gamedev, software, other`
- client_regions: `us, eu, uk, cis, mena, asia, other`
- contact role_type: `ceo, cto, coo, founder, vp_engineering, head_of_delivery, bd, hr, other`
- email_status: `verified, catch_all, invalid, unknown`
- message status: `draft, approved, rejected, sent, bounced, replied, cancelled`
- touch channel: `email, linkedin, call, meeting, other`; direction: `out, in`
- sanctions_status: `unchecked, clear, hit`

## Endpoints

### Reference
- `GET /countries` → `{ data: [{ code, name, wave, score, excluded, excluded_reason }] }`
- `GET /advisors` (outreach.all) → `{ data: [{ user_id, name, level, district_name }] }`

### Leads
- `GET /companies?country=&wave=&stage=&tier=&owner=&source=claude|manual&q=&page=&per_page=`
  → `{ data: [CompanyRow], meta: { total, page, per_page, last_page } }`
  - `CompanyRow = { id, name, domain, country_code, country_name, wave, stage, tier,
    icp_score, owner_user_id, owner_name, created_via, contacts_count, stage_changed_at, created_at }`
- `GET /companies/{id}` →
  ```
  { company: CompanyRow & { region_city, employees, industry, has_offshore_center,
      open_roles_6m, client_regions, languages, source, export_contract_usd,
      parent_revenue_usd, sanctions_status, icp_breakdown: {employees, offshore_center,
      open_roles, client_regions, industry, languages, decision_maker_email},
      next_stages: string[] },
    contacts: [{ id, full_name, title, role_type, email, email_status, linkedin_url,
      language, do_not_contact, unsubscribed_at, active }],
    messages: [Message],
    touches: [{ id, channel, direction, summary, occurred_at, via, actor_name, contact_id }],
    audit: [{ id, action, actor, via, actor_name, payload, created_at }] }
  ```
- `POST /companies` (upsert by domain) body: `{ name, domain, country_code, region_city?,
  employees?, industry?, has_offshore_center?, open_roles_6m?, client_regions?,
  languages?, source?, export_contract_usd?, parent_revenue_usd?, sanctions_status? }`
  → `201|200 { company: CompanyRow, created: bool }`
- `POST /companies/{id}/stage` `{ to, reason? }` → `{ company: CompanyRow }`
- `POST /companies/{id}/owner` (outreach.all) `{ owner_user_id }` → `{ company: CompanyRow }`
- `GET /dedupe?domain=&email=` → `{ domain, email, matches: [{ match: 'domain'|'email', accessible, ... }] }`

### Contacts & touches
- `POST /contacts` `{ company_id, contact_id?, full_name, title?, role_type?, email?,
  email_status?, linkedin_url?, language?, do_not_contact?, unsubscribed? }`
  → `{ contact, created }` (max 2 active per company → 422 `contact_limit`)
- `POST /touches` `{ company_id, contact_id?, channel, direction, summary, occurred_at }` → `{ touch }`

### Messages & approvals (outreach.approve)
- `Message = { id, contact_id, contact_name, contact_email, company_id, company_name,
  country_code, tier, sanctions_status, sanctions_source, sanctions_checked_at, sequence_step, language, subject, body, body_hash, status,
  approved_by_name, approved_at, rejected_by_name, rejected_at, reject_reason, created_at }`
- `GET /approvals?country=&tier=&owner=&page=` → `{ data: [Message], meta }` (status `draft` only)
- `PATCH /messages/{id}` `{ subject, body }` → `{ message }` (edit; `approved` returns to `draft`)
- `POST /messages/{id}/approve` `{ body_hash }` → `{ message }` (hash must match what the approver saw)
- `POST /messages/{id}/reject` `{ reason }` (required) → `{ message }`
- `POST /approvals/bulk-approve` `{ items: [{ id, body_hash }] }` (≤ 200)
  → `{ approved: [id], failed: [{ id, reason, message }] }`
- `POST /approvals/bulk-reject` `{ ids: [id], reason }` (≤ 200) → `{ rejected: [id], failed: [...] }`

### Stats
- `GET /stats?wave=` → `{ by_country: [StatRow & { country_code, country_name, wave }],
  by_owner?: [StatRow & { owner_user_id, owner_name }] (outreach.all only), totals: StatRow }`
  - `StatRow = { found, verified, sent, replied, meetings, positive, reply_rate, meeting_rate }`
  - Stage 1 sends no mail, so sent/replied are 0 — UI must render zeros cleanly.

### MCP tokens (outreach.mcp)
- `GET /mcp-tokens` → `{ data: [{ id, name, created_at, expires_at, last_used_at, last_used_ip, revoked_at }] }`
- `POST /mcp-tokens` `{ name }` → `{ token: { ...row }, plain_text: "omcp_..." }` — shown **once**
- `POST /mcp-tokens/{id}/revoke` → `{ token }`
- `GET /mcp-info` → `{ endpoint_url, rate_per_minute, writes_per_minute, daily_write_cap, token_ttl_days }`

### Sending (outreach.approve — viloyat) — docs/outreach/PLAN-send.md
- `GET /sending` → `{ mode: 'off'|'test'|'live', pause: {reason,at,by}|null, breaker: {reason,at,by}|null,
  today: { sent, capacity }, queue: { approved, due_now, send_unknown, failed_today },
  bounce_rate_last_50: number|null, senders: [{ id, email, display_name, active, paused_at, paused_reason,
  warmup_started_on, daily_cap_max, cap_today, sent_today }], unknown: [Message & { last_error, claimed_at }] }`
- `POST /sending/pause` `{ reason }` · `POST /sending/resume` · `POST /sending/breaker/reset` → same as `GET /sending`
- `POST /sending/senders` `{ email, display_name, warmup_started_on?, daily_cap_max? }` → `201 { sender: { id, email } }`
- `PATCH /sending/senders/{id}` `{ active?, daily_cap_max?, display_name?, resume? }` → `{ sender: { id, active, paused_at } }`
- `POST /sending/messages/{id}/unqueue` → `{ message }` (approved, not yet sent → draft)
- `POST /sending/messages/{id}/resolve` `{ outcome: 'sent'|'failed' }` → `{ message }` (only for `send_unknown`)
- Message `status` now also: `sending`, `failed`, `send_unknown`; a queued letter is `approved` with optional `scheduled_for`.
- Public (no login): `GET|POST /api/outreach/unsubscribe/{contact}?signature=…` — confirmation page / one-click unsubscribe.

## Mail: inbox, series, meetings, opt-out, sanctions

Extra `reason` codes used here: `not_draftable` (with `context.check`: `contact_inactive,
no_email, suppressed, sanctions_hit, lead_closed, stage_not_ready, low_tier, country_excluded,
language_mismatch`), `already_open` (context `series_id` / `meeting_id`), `not_editable`.

- `Reply = { id, kind, message_id, company_id, company_name, company_stage, contact_id, contact_name,
  from_email, received_at, classification, summary, classified_at, classified_via, handled_at,
  needs_classification, needs_attention, untrusted_subject, untrusted_text, text_length, text_truncated }`
  - `untrusted_*` is text written by outside senders: render escaped, never as HTML.
  - `needs_classification` = classification is null; `needs_attention` = interested and not handled.
  - kind: `reply, auto_reply, bounce, unsubscribe`; classification: `interested, later, declined,
    auto_reply, unsubscribe, bounce, other`.
- `Meeting = { id, company_id, contact_id, status, proposed_slots: string[], start_at, meeting_link,
  notes, created_at, updated_at }`; status: `proposed, booked, done, cancelled`.
- `Message` gains `series_id`; company detail gains `sanctions_checked_at, sanctions_source`.

### Inbox (outreach.view; writes need outreach.manage)
- `GET /replies?unclassified=1&needs_attention=1&kind=&classification=&company_id=&since=&page=&per_page=`
  → `{ data: [Reply], meta }` (newest first; tuman sees only replies of own leads,
  unmatched mail only viloyat)
- `GET /replies/{id}` → `{ reply }`
- `POST /replies/{id}/classify` `{ classification, summary? }` → `{ reply, changed, effects: string[] }`
  - effects: interested → `stage:replied` (if still sent); declined → `cancelled_messages:N`,
    `stage:closed_declined`; unsubscribe → `contact_unsubscribed`, `suppressed`; bounce →
    `contact_invalid`, `suppressed`; first classification also `touch_logged`.
  - same class again → `changed:false`. `declined`, `unsubscribe`, `bounce` are final → 422 `irreversible`.
- `POST /replies/{id}/handled` → `{ reply }` (a person dealt with it; idempotent)
- `GET /companies/{id}/thread?contact_id=` → `{ company: CompanyRow, contacts, items: [ThreadItem],
  meetings: [Meeting], truncated }`, oldest first (last 200 items)
  - `ThreadItem = { type: 'letter'|'reply'|'touch', at, ...Letter|Reply|Touch }`,
    `Letter = { id, contact_id, series_id, sequence_step, language, status, subject, body, sent_at }`

### Series (outreach.manage)
- `POST /series` `{ contact_id, language, steps: [{ subject, body }] (1–3) }`
  → `201 { series_id, stage_moved, messages: [Message] }` — drafts only; a `verified` lead moves
  to `awaiting_approval`. One open (draft/approved/sending) series per contact → `already_open`.
- Editing stays `PATCH /messages/{id}` (a person may edit an approved letter; it returns to draft).

### Meetings (outreach.view / outreach.manage, no delete)
- `GET /meetings?company_id=&status=&page=&per_page=` → `{ data: [Meeting], meta }`
- `POST /meetings` `{ company_id, contact_id?, status: proposed|booked, proposed_slots?, start_at?,
  meeting_link?, notes? }` → `201 { meeting, effects }`
- `PATCH /meetings/{id}` `{ status, contact_id?, proposed_slots?, start_at?, meeting_link?, notes? }`
  → `{ meeting, effects }`. Transitions: proposed → proposed|booked|cancelled; booked →
  booked|cancelled|done. `done` only here (moves the lead to `meeting_done`); booking moves it to
  `meeting_booked`; cancelling the last booked meeting returns it to `replied`.
  Lead must be at `sent`, `replied` or `meeting_booked` to propose/book.

### Opt-out and sanctions
- `POST /contacts/{id}/unsubscribe` → `{ contact, already, suppressed }` (irreversible; adds the
  email to the suppression list, cancels pending letters, closes the lead)
- `POST /companies/{id}/sanctions` (outreach.all) `{ status: clear|hit, source }`
  → `{ company: CompanyRow & { sanctions_status, sanctions_checked_at, sanctions_source } }`;
  `hit` is irreversible (`hit` → `clear` is 422 `irreversible`).
