<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Qator — sektordagi o'rindiqlar chizig'i. Faqat seat_count; koordinata
 * runtime'da. `row_index` = TZ'dagi `index` (SQL-xavfsiz nom).
 */
class SeatRow extends Model
{
    use HasUuids;

    protected $connection = 'hr';

    protected $fillable = [
        'uuid', 'sector_id', 'row_index', 'seat_count', 'seat_start', 'seat_labels_json',
    ];

    protected function casts(): array
    {
        return [
            'row_index' => 'integer',
            'seat_count' => 'integer',
            'seat_start' => 'integer',
            'seat_labels_json' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (SeatRow $r) => $r->uuid ??= (string) Str::uuid());
    }

    /** @return BelongsTo<Sector, $this> */
    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }
}
