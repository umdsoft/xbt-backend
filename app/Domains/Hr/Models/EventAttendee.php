<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Nomlangan mehmon — guruh o'rindig'iga biriktirilган ism (badge/davomat).
 */
class EventAttendee extends Model
{
    use HasUuids;

    protected $connection = 'hr';

    protected $fillable = [
        'uuid', 'event_id', 'event_group_id', 'seat_id', 'seat_number',
        'full_name', 'org', 'present', 'checked_in_at',
    ];

    protected function casts(): array
    {
        return [
            'seat_number' => 'integer',
            'present' => 'boolean',
            'checked_in_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (EventAttendee $a) => $a->uuid ??= (string) Str::uuid());
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<EventGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(EventGroup::class, 'event_group_id');
    }

    /** @return BelongsTo<Seat, $this> */
    public function seat(): BelongsTo
    {
        return $this->belongsTo(Seat::class);
    }
}
