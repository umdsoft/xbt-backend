<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Sektor — obyekt ichidagi burilgan o'rindiq bloki. O'rindiq koordinatasi
 * runtime formula bilan (anchor + rotation + lokal grid). Alohida `seats` yo'q.
 */
class Sector extends Model
{
    use HasUuids;

    protected $connection = 'hr';

    protected $fillable = [
        'uuid', 'venue_id', 'code', 'label', 'anchor_x', 'anchor_y', 'rotation',
        'row_pitch', 'seat_pitch', 'polygon_json', 'tier', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'anchor_x' => 'float',
            'anchor_y' => 'float',
            'rotation' => 'float',
            'row_pitch' => 'float',
            'seat_pitch' => 'float',
            'polygon_json' => 'array',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (Sector $s) => $s->uuid ??= (string) Str::uuid());
    }

    /** @return BelongsTo<Venue, $this> */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /** @return HasMany<SeatRow, $this> */
    public function seatRows(): HasMany
    {
        return $this->hasMany(SeatRow::class)->orderBy('row_index');
    }
}
