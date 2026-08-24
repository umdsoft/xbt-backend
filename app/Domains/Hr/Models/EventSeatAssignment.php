<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Per-SEAT biriktirish — bir tadbir + bir o'rindiq = bitta yozuv.
 * "Bo'sh" = QATOR YO'Q. status: reserved | occupied | blocked.
 */
class EventSeatAssignment extends Model
{
    use HasUuids;

    protected $connection = 'hr';

    protected $fillable = [
        'uuid', 'event_id', 'seat_id', 'status', 'event_group_id',
        'guest_name', 'guest_position', 'guest_org', 'hr_person_id',
        'phone', 'note', 'assigned_by', 'assigned_at',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (EventSeatAssignment $a) => $a->uuid ??= (string) Str::uuid());
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<Seat, $this> */
    public function seat(): BelongsTo
    {
        return $this->belongsTo(Seat::class);
    }

    /** @return BelongsTo<EventGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(EventGroup::class, 'event_group_id');
    }
}
