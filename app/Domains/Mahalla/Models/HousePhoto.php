<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * HONADON — rasm (baseline yoki kunlik). GPS + server vaqti + geofence.
 */
class HousePhoto extends Model
{
    protected $connection = 'mahalla';

    use HasUuids;

    protected $fillable = [
        'house_id', 'observation_id', 'zone', 'angle', 'type', 'image_path',
        'captured_lat', 'captured_lng', 'gps_accuracy_m', 'distance_m', 'geofence_ok',
        'taken_date', 'captured_at', 'uploaded_by', 'device_info',
    ];

    protected $casts = [
        'captured_lat' => 'float', 'captured_lng' => 'float',
        'gps_accuracy_m' => 'float', 'distance_m' => 'float', 'geofence_ok' => 'boolean',
        'taken_date' => 'date', 'captured_at' => 'datetime', 'device_info' => 'array',
    ];

    public function house(): BelongsTo
    {
        return $this->belongsTo(House::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function analysis(): HasOne
    {
        return $this->hasOne(HousePhotoAnalysis::class);
    }
}
