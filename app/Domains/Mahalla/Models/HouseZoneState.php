<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Honadon ZONA HOLATI (joriy) — (honadon, zona) juftligi uchun bitta qator.
 * Hisobotlar uchun tez "haqiqat manbai". Holat FAQAT o'zgarish tasdiqlanganda
 * yangilanadi (rasm yuklash o'zi holatni o'zgartirmaydi).
 */
class HouseZoneState extends Model
{
    use HasUuids;

    protected $connection = 'mahalla';

    protected $table = 'house_zone_states';

    protected $fillable = [
        'house_id', 'zone', 'status', 'progress_percent',
        'last_photo_id', 'last_observation_id', 'last_observed_at', 'last_changed_at',
    ];

    protected $casts = [
        'progress_percent' => 'integer',
        'last_observed_at' => 'datetime',
        'last_changed_at' => 'datetime',
    ];

    public function house(): BelongsTo
    {
        return $this->belongsTo(House::class);
    }

    public function lastPhoto(): BelongsTo
    {
        return $this->belongsTo(HousePhoto::class, 'last_photo_id');
    }
}
