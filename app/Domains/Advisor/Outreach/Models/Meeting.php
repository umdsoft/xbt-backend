<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Models;

use App\Domains\Advisor\Outreach\Models\Concerns\WritesTimezoneOffset;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An online meeting with a company (filled from stage 4).
 *
 * status: proposed | booked | done | cancelled.
 */
class Meeting extends Model
{
    use HasUuids;
    use WritesTimezoneOffset;

    protected $connection = 'advisor';

    protected $table = 'outreach_meetings';

    protected $fillable = [
        'company_id', 'contact_id', 'proposed_slots', 'start_at', 'meeting_link', 'status', 'notes',
    ];

    protected $casts = [
        'proposed_slots' => 'array',
        'start_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }
}
