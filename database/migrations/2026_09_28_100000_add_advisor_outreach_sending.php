<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADVISOR — OUTREACH: automatic sending of approved letters
 * (docs/outreach/PLAN-send.md §3–§10).
 *
 *   outreach_senders       pool of sender mailboxes (state only; SMTP secrets stay in .env)
 *   outreach_suppressions  emails/domains that must never be mailed again
 *   outreach_send_log      every send attempt and every guard refusal
 *   outreach_send_state    global switches (pause, circuit breaker)
 *   outreach_messages      + series, claim and delivery columns
 *   outreach_countries     + IANA time zone for the recipient's business hours
 *
 * Same conventions as 2026_09_27_100000: PostgreSQL only, forward-only,
 * idempotent (existing tables/columns are left untouched).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $schema = Schema::connection('advisor');

        if (! $schema->hasTable('outreach_senders')) {
            $schema->create('outreach_senders', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('email')->unique();
                $t->string('mailer', 64);                      // Laravel mailer name (config/mail.php, from .env)
                $t->string('display_name', 120);
                $t->date('warmup_started_on');
                $t->smallInteger('daily_cap_max')->default(40);
                $t->boolean('active')->default(true);
                $t->timestampTz('paused_at')->nullable();
                $t->text('paused_reason')->nullable();
                $t->timestamps();
            });
        }

        if (! $schema->hasTable('outreach_suppressions')) {
            $schema->create('outreach_suppressions', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('email')->nullable()->unique();    // normalized
                $t->string('domain')->nullable()->unique();   // whole-domain block
                $t->string('reason', 16);                     // unsubscribed | hard_bounce | complaint | manual
                $t->uuid('source_message_id')->nullable();
                $t->timestampTz('created_at')->useCurrent();
            });
        }

        if (! $schema->hasTable('outreach_send_log')) {
            $schema->create('outreach_send_log', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->foreignUuid('message_id')->constrained('outreach_messages')->restrictOnDelete();
                $t->uuid('sender_id')->nullable()->index();
                $t->string('outcome', 16);                    // sent | deferred | failed | unknown | held | cancelled | reverted
                $t->string('reason', 48)->nullable();         // guard / error code
                $t->smallInteger('smtp_code')->nullable();
                $t->text('detail')->nullable();
                $t->timestampTz('created_at')->useCurrent();
                $t->index(['message_id', 'created_at']);
                $t->index(['outcome', 'created_at']);
            });
        }

        if (! $schema->hasTable('outreach_send_state')) {
            $schema->create('outreach_send_state', function (Blueprint $t) {
                $t->string('key', 48)->primary();
                $t->jsonb('value')->nullable();
                $t->timestampTz('updated_at')->nullable();
            });
        }

        $schema->table('outreach_messages', function (Blueprint $t) use ($schema) {
            $add = fn (string $column): bool => ! $schema->hasColumn('outreach_messages', $column);

            if ($add('series_id')) {
                $t->uuid('series_id')->nullable()->index();
            }
            if ($add('sender_id')) {
                $t->uuid('sender_id')->nullable()->index();
            }
            if ($add('claim_id')) {
                $t->uuid('claim_id')->nullable();
            }
            if ($add('claimed_at')) {
                $t->timestampTz('claimed_at')->nullable();
            }
            if ($add('send_attempts')) {
                $t->smallInteger('send_attempts')->default(0);
            }
            if ($add('last_error')) {
                $t->text('last_error')->nullable();
            }
            if ($add('message_id_header')) {
                $t->string('message_id_header')->nullable()->unique();
            }
        });

        if (! $schema->hasColumn('outreach_countries', 'timezone')) {
            $schema->table('outreach_countries', function (Blueprint $t) {
                $t->string('timezone', 64)->nullable();       // IANA, e.g. Europe/Berlin
            });
        }
    }

    public function down(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $schema = Schema::connection('advisor');
        $schema->dropIfExists('outreach_send_log');
        $schema->dropIfExists('outreach_send_state');
        $schema->dropIfExists('outreach_suppressions');
        $schema->dropIfExists('outreach_senders');

        $schema->table('outreach_messages', function (Blueprint $t) {
            $t->dropColumn(['series_id', 'sender_id', 'claim_id', 'claimed_at', 'send_attempts', 'last_error', 'message_id_header']);
        });
        $schema->table('outreach_countries', function (Blueprint $t) {
            $t->dropColumn('timezone');
        });
    }
};
