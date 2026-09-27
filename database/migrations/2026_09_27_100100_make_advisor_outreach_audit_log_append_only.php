<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADVISOR — OUTREACH: make the audit log append-only at the database level
 * (PLAN-crm.md §2). UPDATE, DELETE and TRUNCATE raise an error, so neither the
 * application nor a leaked MCP token can rewrite the audit trail.
 *
 * Idempotent: CREATE OR REPLACE + DROP TRIGGER IF EXISTS.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $db = DB::connection('advisor');

        $db->statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION advisor.outreach_audit_log_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'outreach_audit_log is append-only (% blocked)', TG_OP;
            END;
            $$
            SQL);

        $db->statement('DROP TRIGGER IF EXISTS outreach_audit_log_no_change ON advisor.outreach_audit_log');
        $db->statement(<<<'SQL'
            CREATE TRIGGER outreach_audit_log_no_change
            BEFORE UPDATE OR DELETE ON advisor.outreach_audit_log
            FOR EACH ROW EXECUTE FUNCTION advisor.outreach_audit_log_immutable()
            SQL);

        $db->statement('DROP TRIGGER IF EXISTS outreach_audit_log_no_truncate ON advisor.outreach_audit_log');
        $db->statement(<<<'SQL'
            CREATE TRIGGER outreach_audit_log_no_truncate
            BEFORE TRUNCATE ON advisor.outreach_audit_log
            FOR EACH STATEMENT EXECUTE FUNCTION advisor.outreach_audit_log_immutable()
            SQL);
    }

    public function down(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $db = DB::connection('advisor');
        $db->statement('DROP TRIGGER IF EXISTS outreach_audit_log_no_change ON advisor.outreach_audit_log');
        $db->statement('DROP TRIGGER IF EXISTS outreach_audit_log_no_truncate ON advisor.outreach_audit_log');
        $db->statement('DROP FUNCTION IF EXISTS advisor.outreach_audit_log_immutable()');
    }
};
