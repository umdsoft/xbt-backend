<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Models;

use App\Domains\Mahalla\Models\Master\District;
use App\Domains\Mahalla\Models\Master\Mahalla;
use App\Domains\Mahalla\Models\Master\Street;
use App\Domains\Mahalla\Support\MahallaScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * HONADON (operatsion) — asosiy birlik. Geo (mahalla/ko'cha/tuman) MASTER'dan.
 * Kadastr + koordinata anti-cheating "haqiqat manbai".
 */
class House extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $connection = 'mahalla';

    protected $fillable = [
        'building_id', 'district_id', 'mahalla_id', 'street_id',
        'cadastral_number', 'lat', 'lng', 'address', 'owner_name',
        'status', 'progress_percent', 'last_photo_date',
    ];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'progress_percent' => 'integer',
        'last_photo_date' => 'date',
    ];

    // Cross-schema/connection munosabatlar (master modellari o'z ulanishida)
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function mahalla(): BelongsTo
    {
        return $this->belongsTo(Mahalla::class);
    }

    public function street(): BelongsTo
    {
        return $this->belongsTo(Street::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(HousePhoto::class);
    }

    public function zoneStates(): HasMany
    {
        return $this->hasMany(HouseZoneState::class);
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Mahalla\Models\Master\Building::class);
    }

    public function baselinePhoto(): HasMany
    {
        return $this->hasMany(HousePhoto::class)->where('type', 'baseline');
    }

    /**
     * Geo ko'lam bo'yicha honadonlarni filtrlash (RBAC). Bir xil ko'cha-scope:
     * admin — hammasi; boshqa har bir user FAQAT biriktirilgan ko'chalari
     * honadonlarini ko'radi. Biriktiruv bo'lmasa — hech narsa (bo'sh whereIn).
     * District/mahalla filtrlari ixtiyoriy (endi ishlatilmaydi, buzilmaydi).
     *
     * @param  Builder<House>  $query
     */
    public function scopeVisibleTo(Builder $query, MahallaScope $scope): Builder
    {
        if ($scope->isAdmin) {
            return $query;
        }

        return $query->whereIn('street_id', $scope->streetIds);
    }
}
