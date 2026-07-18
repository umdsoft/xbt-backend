<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Models\Master;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MASTER kadastr binosi (butun viloyat yadrosi). Monitoring uchun manba: deputat
 * ko'chasidagi turar binolar worklistni tashkil qiladi. `geom` (PostGIS) bu yerda
 * o'qilmaydi — so'rovlarda aniq ustunlar tanlanadi (lat/lng yetarli).
 */
class Building extends Model
{
    use HasUuids;

    protected $connection = 'master';

    protected $table = 'buildings';

    public $timestamps = true;

    protected $guarded = ['id'];

    protected $hidden = ['geom'];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'area' => 'float',
        'total_area' => 'float',
        'living_area' => 'float',
    ];

    public function mahalla(): BelongsTo
    {
        return $this->belongsTo(Mahalla::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function street(): BelongsTo
    {
        return $this->belongsTo(Street::class);
    }
}
