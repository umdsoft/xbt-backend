<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Pechat snapshot — vektor SVG + PDF yo'li (queue job natijasi).
 */
class EventSnapshot extends Model
{
    use HasUuids;

    protected $connection = 'hr';

    protected $fillable = [
        'uuid', 'event_id', 'svg_content', 'pdf_path', 'sheet_format',
        'printed_by', 'printed_at',
    ];

    protected function casts(): array
    {
        return [
            'printed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (EventSnapshot $s) => $s->uuid ??= (string) Str::uuid());
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
