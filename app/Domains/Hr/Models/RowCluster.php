<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Qator klasteri — DWG'dan connected-components (600mm) bilan. angle 45/90/135.
 * Belgilash (allocation) shu klasterga ishora qiladi (koordinata emas).
 */
class RowCluster extends Model
{
    use HasUuids;

    protected $connection = 'hr';

    protected $fillable = [
        'uuid', 'venue_id', 'code', 'angle', 'seat_count', 'centroid_x', 'centroid_y',
    ];

    protected function casts(): array
    {
        return [
            'angle' => 'float',
            'seat_count' => 'integer',
            'centroid_x' => 'float',
            'centroid_y' => 'float',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (RowCluster $r) => $r->uuid ??= (string) Str::uuid());
    }

    /** @return BelongsTo<Venue, $this> */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /** @return HasMany<Seat, $this> */
    public function seats(): HasMany
    {
        return $this->hasMany(Seat::class);
    }
}
