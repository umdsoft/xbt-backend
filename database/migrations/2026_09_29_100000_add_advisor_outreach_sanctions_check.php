<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADVISOR — OUTREACH: when and against what a company was screened for
 * sanctions (OFAC SDN / EU consolidated list).
 *
 * Owner decision 2026-09-27 (PLAN-send.md §11): only companies checked as
 * `clear` are mailed. The status already lives in `sanctions_status`; these
 * columns record the last check so a person can see how fresh it is:
 *
 *   sanctions_checked_at  last time a check result was recorded
 *   sanctions_source      short note: which lists, which date, match details
 *
 * Same conventions as 2026_09_27_100000: PostgreSQL only, forward-only,
 * idempotent (existing columns are left untouched).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $schema = Schema::connection('advisor');

        $schema->table('outreach_companies', function (Blueprint $t) use ($schema) {
            if (! $schema->hasColumn('outreach_companies', 'sanctions_checked_at')) {
                $t->timestampTz('sanctions_checked_at')->nullable();
            }
            if (! $schema->hasColumn('outreach_companies', 'sanctions_source')) {
                $t->string('sanctions_source', 500)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        Schema::connection('advisor')->table('outreach_companies', function (Blueprint $t) {
            $t->dropColumn(['sanctions_checked_at', 'sanctions_source']);
        });
    }
};
