<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tadbir audit jurnali — kim, qanday amal, payload. Faqat created_at.
 */
class EventAuditLog extends Model
{
    use HasUuids;

    protected $connection = 'hr';

    public const UPDATED_AT = null;

    protected $fillable = [
        'event_id', 'user_id', 'action', 'payload_json', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
