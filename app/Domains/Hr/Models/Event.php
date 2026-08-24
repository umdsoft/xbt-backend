<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use App\Domains\Hr\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Tadbir — obyektда bir sanadagi o'tirish rejasi. TENANT = hokimlik_id.
 */
class Event extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use LogsActivity;
    use SoftDeletes;

    public const TENANT_COLUMN = 'hokimlik_id';

    protected $connection = 'hr';

    protected $fillable = [
        'uuid', 'venue_id', 'hokimlik_id', 'title', 'event_date',
        'start_time', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (Event $e) => $e->uuid ??= (string) Str::uuid());
    }

    /** @return BelongsTo<Venue, $this> */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /** @return HasMany<EventGroup, $this> */
    public function groups(): HasMany
    {
        return $this->hasMany(EventGroup::class)->orderBy('sort_order');
    }

    /** @return HasMany<EventAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(EventAllocation::class);
    }

    /** @return HasMany<EventSnapshot, $this> */
    public function snapshots(): HasMany
    {
        return $this->hasMany(EventSnapshot::class);
    }

    /** @return BelongsTo<HrProfile, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(HrProfile::class, 'created_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'event_date', 'status', 'venue_id'])
            ->logOnlyDirty();
    }
}
