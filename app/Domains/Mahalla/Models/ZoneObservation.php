<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * KUZATUV — bir honadon zonasiga bir tashrif, N ta RAKURS rasmi bilan.
 * O'zgarish aniqlash birligi: AI joriy kuzatuvni (barcha rakurslar) OLDINGI
 * kuzatuv bilan solishtiradi. Rasm yuklash o'zi o'zgarish emas.
 */
class ZoneObservation extends Model
{
    use HasUuids;

    protected $connection = 'mahalla';

    protected $table = 'zone_observations';

    protected $fillable = [
        'house_id', 'zone', 'user_id', 'observed_at',
        'lat', 'lng', 'gps_accuracy_m', 'distance_m', 'is_on_site', 'photo_count',
        'prev_observation_id', 'prev_status', 'status', 'suggested_status', 'is_change',
        'decision', 'decision_reason', 'confidence', 'ai_result',
        'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'observed_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'lat' => 'float',
        'lng' => 'float',
        'gps_accuracy_m' => 'float',
        'distance_m' => 'float',
        'is_on_site' => 'boolean',
        'is_change' => 'boolean',
        'confidence' => 'float',
        'photo_count' => 'integer',
        'ai_result' => 'array',
    ];

    public function house(): BelongsTo
    {
        return $this->belongsTo(House::class);
    }

    /** Kuzatuvning rakurs rasmlari. */
    public function photos(): HasMany
    {
        return $this->hasMany(HousePhoto::class, 'observation_id');
    }

    public function prevObservation(): BelongsTo
    {
        return $this->belongsTo(self::class, 'prev_observation_id');
    }
}
