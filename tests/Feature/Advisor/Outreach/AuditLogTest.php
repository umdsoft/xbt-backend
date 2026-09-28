<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor\Outreach;

use App\Domains\Advisor\Outreach\Models\AuditEntry;
use App\Domains\Advisor\Outreach\Services\AuditLogger;
use App\Domains\Advisor\Outreach\Support\Actor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class AuditLogTest extends OutreachTestCase
{
    public function test_logger_records_actor_channel_and_payload(): void
    {
        $user = $this->viloyat();

        app(AuditLogger::class)->log(
            Actor::mcp($user, '00000000-0000-0000-0000-000000000001', '10.0.0.1'),
            'company.created', 'company', 'abc', ['domain' => 'acme.test'],
        );

        $entry = AuditEntry::query()->where('entity_id', 'abc')->firstOrFail();

        $this->assertSame('claude', $entry->actor);
        $this->assertSame('mcp', $entry->via);
        $this->assertSame($user->id, $entry->actor_user_id);
        $this->assertSame('00000000-0000-0000-0000-000000000001', $entry->mcp_token_id);
        $this->assertSame(['domain' => 'acme.test'], $entry->payload_json);
        $this->assertSame('10.0.0.1', $entry->ip);
    }

    public function test_audit_rows_cannot_be_updated(): void
    {
        app(AuditLogger::class)->log(Actor::system(), 'test.update', 'company', 'u1');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append-only');

        DB::connection('advisor')->table('outreach_audit_log')
            ->where('entity_id', 'u1')->update(['action' => 'tampered']);
    }

    public function test_audit_rows_cannot_be_deleted(): void
    {
        app(AuditLogger::class)->log(Actor::system(), 'test.delete', 'company', 'd1');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append-only');

        DB::connection('advisor')->table('outreach_audit_log')->where('entity_id', 'd1')->delete();
    }
}
