<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADVISOR — OUTREACH: countries are researched and recorded by Claude through
 * MCP (owner decision 2026-09-28), so each row keeps the reasoning behind its
 * wave/score and where it came from. Exclusion stays a human decision.
 *
 * Forward-only, idempotent, PostgreSQL only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $schema = Schema::connection('advisor');

        $schema->table('outreach_countries', function (Blueprint $t) use ($schema) {
            if (! $schema->hasColumn('outreach_countries', 'notes')) {
                $t->text('notes')->nullable();                 // why this wave/score (Claude's research summary)
            }
            if (! $schema->hasColumn('outreach_countries', 'source')) {
                $t->string('source', 500)->nullable();         // data sources used
            }
            if (! $schema->hasColumn('outreach_countries', 'updated_via')) {
                $t->string('updated_via', 8)->nullable();      // ui | mcp
            }
        });
    }

    public function down(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        Schema::connection('advisor')->table('outreach_countries', function (Blueprint $t) {
            $t->dropColumn(['notes', 'source', 'updated_via']);
        });
    }
};
