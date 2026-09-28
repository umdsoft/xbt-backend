<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Services;

use App\Domains\Advisor\Outreach\Models\AuditEntry;
use App\Domains\Advisor\Outreach\Support\Actor;

/**
 * Writes the append-only outreach audit trail (CLAUDE.md rule 3).
 *
 * Call it inside the same transaction as the write it describes, so a failed
 * write never leaves an audit record behind and vice versa.
 */
final class AuditLogger
{
    /** @param  array<string, mixed>  $payload */
    public function log(Actor $actor, string $action, string $entity, ?string $entityId, array $payload = []): void
    {
        AuditEntry::query()->create([
            'actor' => $actor->kind(),
            'actor_user_id' => $actor->userId(),
            'via' => $actor->via,
            'mcp_token_id' => $actor->mcpTokenId,
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId,
            'payload_json' => $payload === [] ? null : $payload,
            'ip' => $actor->ip,
        ]);
    }
}
