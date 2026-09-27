<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Models;

use App\Domains\Advisor\Outreach\Models\Concerns\WritesTimezoneOffset;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One interaction with a company (company card history, `log_touch` tool).
 *
 * channel: email | linkedin | call | meeting | other. direction: out | in.
 * via: ui | mcp. Touches are never edited, so there is no updated_at.
 */
class Touch extends Model
{
    use HasUuids;
    use WritesTimezoneOffset;

    public const UPDATED_AT = null;

    public const CHANNELS = ['email', 'linkedin', 'call', 'meeting', 'other'];

    public const DIRECTIONS = ['out', 'in'];

    protected $connection = 'advisor';

    protected $table = 'outreach_touches';

    protected $fillable = [
        'company_id', 'contact_id', 'channel', 'direction', 'summary', 'occurred_at',
        'actor_user_id', 'via',
    ];

    protected $casts = ['occurred_at' => 'datetime'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }
}
