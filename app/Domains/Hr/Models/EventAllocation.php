<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Belgilash — sektor yoki qatorni guruhga biriktirish.
 * seat_row_id = NULL → butun sektor guruhга tegishli.
 */
class EventAllocation extends Model
{
    use HasUuids;

    protected $connection = 'hr';

    protected $fillable = [
        'uuid', 'event_id', 'sector_id', 'seat_row_id', 'event_group_id',
    ];

    protected static function booted(): void
    {
        static::creating(fn (EventAllocation $a) => $a->uuid ??= (string) Str::uuid());
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<Sector, $this> */
    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    /** @return BelongsTo<SeatRow, $this> */
    public function seatRow(): BelongsTo
    {
        return $this->belongsTo(SeatRow::class);
    }

    /** @return BelongsTo<EventGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(EventGroup::class, 'event_group_id');
    }
}
