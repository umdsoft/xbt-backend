<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Tadbir guruhi — rang bilan belgilangan mehmonlar toifasi (tuman/tashkilot).
 */
class EventGroup extends Model
{
    use HasUuids;

    protected $connection = 'hr';

    protected $fillable = [
        'uuid', 'event_id', 'name', 'color', 'expected_count',
        'org_id', 'district_id', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'expected_count' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (EventGroup $g) => $g->uuid ??= (string) Str::uuid());
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return HasMany<EventAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(EventAllocation::class);
    }
}
