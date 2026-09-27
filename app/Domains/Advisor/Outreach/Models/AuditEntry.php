<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit record. Written only through AuditLogger; the database
 * rejects UPDATE and DELETE on this table.
 *
 * actor: user | claude | system. via: ui | mcp | system.
 */
class AuditEntry extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $connection = 'advisor';

    protected $table = 'outreach_audit_log';

    protected $fillable = [
        'actor', 'actor_user_id', 'via', 'mcp_token_id', 'action', 'entity', 'entity_id',
        'payload_json', 'ip',
    ];

    protected $casts = ['payload_json' => 'array'];
}
