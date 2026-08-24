<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * O'rindiq — DWG'dan aniq (x,y). Formula YO'Q. Guruh (K/O/Y), qator klasteri,
 * (moslashtirilgach) sektor va qator/o'rindiq yorlig'i.
 */
class Seat extends Model
{
    use HasUuids;

    protected $connection = 'hr';

    protected $fillable = [
        'uuid', 'venue_id', 'code', 'x', 'y', 'rotation', 'seat_group',
        'row_cluster_id', 'sector_id', 'row_label', 'seat_label', 'is_mirrored',
    ];

    protected function casts(): array
    {
        return [
            'x' => 'float',
            'y' => 'float',
            'rotation' => 'float',
            'is_mirrored' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (Seat $s) => $s->uuid ??= (string) Str::uuid());
    }

    /** @return BelongsTo<Venue, $this> */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
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
}
