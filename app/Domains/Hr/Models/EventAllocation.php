<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Belgilash — sektor yoki qator-klasterni guruhga biriktirish (koordinata SAQLAMAYDI).
 * row_cluster_id = NULL → butun sektor guruhga tegishli.
 */
class EventAllocation extends Model
{
    use HasUuids;

    protected $connection = 'hr';

    protected $fillable = [
        'uuid', 'event_id', 'sector_id', 'row_cluster_id', 'event_group_id',
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

    /** @return BelongsTo<RowCluster, $this> */
    public function rowCluster(): BelongsTo
    {
        return $this->belongsTo(RowCluster::class);
    }

    /** @return BelongsTo<EventGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(EventGroup::class, 'event_group_id');
    }
}
