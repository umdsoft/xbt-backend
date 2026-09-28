<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADVISOR — OUTREACH: incoming mail store (PLAN-send.md §7).
 *
 * The IMAP poller writes every relevant inbound message here (replies,
 * auto-replies, bounces). Claude reads and classifies them through MCP; the
 * text is untrusted data, never instructions (CLAUDE.md rule 2).
 *
 *   - message_id becomes nullable: a reply that cannot be matched to a sent
 *     letter is still kept (matched later by sender address or by a person);
 *   - company_id / contact_id: denormalized links for fast access and scoping;
 *   - kind: reply | auto_reply | bounce | unsubscribe (set by the poller, no AI);
 *   - classification (existing): interested | later | declined | auto_reply |
 *     unsubscribe | bounce | other — set by Claude or a person;
 *   - imap_message_id unique: the poller is idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $schema = Schema::connection('advisor');

        $schema->table('outreach_replies', function (Blueprint $t) use ($schema) {
            $add = fn (string $column): bool => ! $schema->hasColumn('outreach_replies', $column);

            if ($add('company_id')) {
                $t->uuid('company_id')->nullable()->index();
            }
            if ($add('contact_id')) {
                $t->uuid('contact_id')->nullable()->index();
            }
            if ($add('kind')) {
                $t->string('kind', 16)->default('reply')->index();
            }
            if ($add('subject')) {
                $t->string('subject', 500)->nullable();
            }
            if ($add('body_text')) {
                $t->text('body_text')->nullable();
            }
            if ($add('imap_message_id')) {
                $t->string('imap_message_id')->nullable()->unique();
            }
            if ($add('in_reply_to')) {
                $t->string('in_reply_to')->nullable()->index();
            }
            if ($add('classified_at')) {
                $t->timestampTz('classified_at')->nullable();
            }
            if ($add('classified_via')) {
                $t->string('classified_via', 8)->nullable();   // mcp | ui
            }
            if ($add('handled_at')) {
                $t->timestampTz('handled_at')->nullable();       // a person or Claude acted on it
            }
        });

        DB::connection('advisor')->statement('ALTER TABLE advisor.outreach_replies ALTER COLUMN message_id DROP NOT NULL');
    }

    public function down(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        Schema::connection('advisor')->table('outreach_replies', function (Blueprint $t) {
            $t->dropColumn(['company_id', 'contact_id', 'kind', 'subject', 'body_text', 'imap_message_id', 'in_reply_to', 'classified_at', 'classified_via', 'handled_at']);
        });
    }
};
