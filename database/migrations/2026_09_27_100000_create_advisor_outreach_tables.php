<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADVISOR — OUTREACH CRM (docs/outreach/PLAN-crm.md §2).
 *
 * Tables for attracting foreign IT companies: countries, companies, contacts,
 * message drafts (approval queue), replies, meetings, touches, an append-only
 * audit log and scoped MCP tokens. All live in the `advisor` schema with the
 * `outreach_` prefix, so no new connection or search_path is needed.
 *
 * Conventions (same as other advisor migrations):
 *   - PostgreSQL only; other drivers skip.
 *   - Forward-only and idempotent: an existing table is left untouched.
 *   - Columns pointing at auth.users are plain uuid + index (no cross-schema FK).
 *   - Nothing here is ever hard-deleted, so intra-schema FKs restrict deletes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        DB::connection('advisor')->statement('CREATE SCHEMA IF NOT EXISTS advisor');

        $schema = Schema::connection('advisor');

        // Target / excluded countries. ISO 3166-1 alpha-2 code is the natural key.
        $this->create($schema, 'outreach_countries', function (Blueprint $t) {
            $t->char('code', 2)->primary();
            $t->string('name', 120);
            $t->string('wave', 10)->nullable();          // 1 | 2 | investor
            $t->smallInteger('score')->nullable();
            $t->boolean('excluded')->default(false);
            $t->text('excluded_reason')->nullable();
            $t->string('default_language', 5)->nullable();
            $t->timestamps();
        });

        $this->create($schema, 'outreach_companies', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('domain')->unique();               // normalized (lowercase, no scheme/www/path)
            $t->char('country_code', 2)->index();
            $t->string('region_city', 120)->nullable();   // HQ city abroad (informational)
            $t->uuid('owner_user_id')->index();           // auth.users.id — the responsible advisor
            $t->integer('employees')->nullable();
            $t->string('industry', 32)->nullable();
            $t->boolean('has_offshore_center')->nullable();
            $t->integer('open_roles_6m')->nullable();
            $t->jsonb('client_regions')->nullable();      // e.g. ["us","eu"]
            $t->jsonb('languages')->nullable();           // e.g. ["ru","en"]
            $t->string('source', 32)->nullable();         // apollo | linkedin | referral | ...
            $t->string('created_via', 8)->default('ui');  // ui | mcp
            $t->bigInteger('export_contract_usd')->nullable();
            $t->bigInteger('parent_revenue_usd')->nullable();
            $t->unsignedSmallInteger('icp_score')->default(0);
            $t->char('tier', 1)->default('C');            // A | B | C
            $t->string('sanctions_status', 10)->default('unchecked'); // clear | hit | unchecked
            $t->string('stage', 24)->default('found');
            $t->timestampTz('stage_changed_at')->nullable();
            $t->uuid('created_by')->nullable();           // auth.users.id
            $t->timestamps();

            $t->foreign('country_code')->references('code')->on('outreach_countries')->restrictOnDelete();
            $t->index(['country_code', 'stage']);
            $t->index('stage');
            $t->index('tier');
        });

        $this->create($schema, 'outreach_contacts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('company_id')->constrained('outreach_companies')->restrictOnDelete();
            $t->string('full_name', 200);
            $t->string('title', 200)->nullable();
            $t->string('role_type', 16)->nullable();      // ceo | coo | cto | delivery | expansion
            $t->string('email')->nullable()->unique();    // lowercase
            $t->string('email_status', 10)->default('unknown'); // verified | catch_all | invalid | unknown
            $t->timestampTz('verified_at')->nullable();
            $t->string('linkedin_url', 500)->nullable();
            $t->string('language', 5)->nullable();
            $t->timestampTz('unsubscribed_at')->nullable();
            $t->boolean('do_not_contact')->default(false);
            $t->uuid('created_by')->nullable();           // auth.users.id
            $t->timestamps();
        });

        // Message drafts — the approval queue reads this table.
        $this->create($schema, 'outreach_messages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('contact_id')->constrained('outreach_contacts')->restrictOnDelete();
            $t->unsignedSmallInteger('sequence_step');    // 1 | 2 | 3
            $t->string('language', 5)->nullable();
            $t->string('subject', 300);
            $t->text('body');
            $t->char('body_hash', 64)->nullable();        // sha256 at approval time
            // draft | approved | rejected | sent | bounced | replied | cancelled
            $t->string('status', 12)->default('draft');
            $t->uuid('approved_by_user_id')->nullable();  // auth.users.id
            $t->timestampTz('approved_at')->nullable();
            $t->uuid('rejected_by_user_id')->nullable();  // auth.users.id
            $t->timestampTz('rejected_at')->nullable();
            $t->text('reject_reason')->nullable();
            $t->timestampTz('scheduled_for')->nullable();
            $t->timestampTz('sent_at')->nullable();
            $t->string('smtp_message_id')->nullable();
            $t->uuid('created_by')->nullable();           // auth.users.id
            $t->timestamps();

            $t->index('status');
        });

        // Filled from stage 3 (mail MCP). Created now so the schema is complete.
        $this->create($schema, 'outreach_replies', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('message_id')->constrained('outreach_messages')->restrictOnDelete();
            $t->string('from_email');
            $t->timestampTz('received_at');
            // interested | later | declined | auto_reply | unsubscribe | bounce
            $t->string('classification', 16)->nullable();
            $t->text('summary')->nullable();
            $t->timestamps();

            $t->index('classification');
        });

        // Filled from stage 4 (calendar).
        $this->create($schema, 'outreach_meetings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('company_id')->constrained('outreach_companies')->restrictOnDelete();
            $t->foreignUuid('contact_id')->nullable()->constrained('outreach_contacts')->restrictOnDelete();
            $t->jsonb('proposed_slots')->nullable();
            $t->timestampTz('start_at')->nullable();
            $t->string('meeting_link', 500)->nullable();
            $t->string('status', 16)->default('proposed'); // proposed | booked | done | cancelled
            $t->text('notes')->nullable();
            $t->timestamps();

            $t->index('status');
        });

        // Every contact attempt / interaction (company card history, log_touch).
        $this->create($schema, 'outreach_touches', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('company_id')->constrained('outreach_companies')->restrictOnDelete();
            $t->foreignUuid('contact_id')->nullable()->constrained('outreach_contacts')->restrictOnDelete();
            $t->string('channel', 12);                    // email | linkedin | call | meeting | other
            $t->string('direction', 3);                   // out | in
            $t->text('summary');
            $t->timestampTz('occurred_at');
            $t->uuid('actor_user_id')->nullable();        // auth.users.id
            $t->string('via', 8);                         // ui | mcp
            $t->timestampTz('created_at')->useCurrent();

            $t->index(['company_id', 'occurred_at']);
        });

        // Append-only audit trail. UPDATE/DELETE are blocked by a trigger
        // (separate migration).
        $this->create($schema, 'outreach_audit_log', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('actor', 8);                       // user | claude | system
            $t->uuid('actor_user_id')->nullable();        // auth.users.id
            $t->string('via', 8);                         // ui | mcp | system
            $t->uuid('mcp_token_id')->nullable();
            $t->string('action', 64);
            $t->string('entity', 32);
            $t->string('entity_id', 64)->nullable();      // uuid or country code
            $t->jsonb('payload_json')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestampTz('created_at')->useCurrent();

            $t->index(['entity', 'entity_id']);
            $t->index('actor_user_id');
            $t->index('created_at');
        });

        // MCP tokens are separate from Sanctum: they only work on the MCP route,
        // so a leaked token cannot reach the rest of the advisor API.
        $this->create($schema, 'outreach_mcp_tokens', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->index();                 // auth.users.id
            $t->string('name', 100);
            $t->char('token_hash', 64)->unique();         // sha256 of the plain token
            $t->timestampTz('last_used_at')->nullable();
            $t->string('last_used_ip', 45)->nullable();
            $t->timestampTz('expires_at');
            $t->timestampTz('revoked_at')->nullable();
            $t->timestamps();
        });
    }

    /**
     * Creates the table only if it does not exist (forward-only, re-runnable).
     *
     * @param  Builder  $schema
     */
    private function create($schema, string $table, callable $definition): void
    {
        if ($schema->hasTable($table)) {
            return;
        }

        $schema->create($table, $definition);
    }

    public function down(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        foreach ([
            'outreach_mcp_tokens', 'outreach_audit_log', 'outreach_touches',
            'outreach_meetings', 'outreach_replies', 'outreach_messages',
            'outreach_contacts', 'outreach_companies', 'outreach_countries',
        ] as $table) {
            Schema::connection('advisor')->dropIfExists($table);
        }
    }
};
