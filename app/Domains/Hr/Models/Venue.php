<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Tadbir obyekti (zal). GLOBAL — tenant'ga bog'lanmaydi (butun viloyat uchun).
 * Geometriya: viewbox_json + stage_json; sektorlar/qatorlar alohida jadvallarda.
 */
class Venue extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $connection = 'hr';

    protected $fillable = [
        'uuid', 'name', 'slug', 'unit', 'viewbox_json', 'stage_json', 'floor_json',
        'capacity_cached', 'is_active', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'viewbox_json' => 'array',
            'stage_json' => 'array',
            'floor_json' => 'array',
            'capacity_cached' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (Venue $v) => $v->uuid ??= (string) Str::uuid());
    }

    /** @return HasMany<Sector, $this> */
    public function sectors(): HasMany
    {
        return $this->hasMany(Sector::class)->orderBy('sort_order');
    }

    /** @return HasMany<Event, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }
}
