<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ikki vertikal yagona reyestrda; farq `type` da.
 * Zanjir (F3 bandlik) rolga emas, `type` + `sector_id` juftligiga bog'lanadi —
 * shuning uchun yangi tasdiqlovchi tashkilot kodsiz qo'shiladi.
 */
class Organization extends Model
{
    use HasUuids;

    protected $connection = 'yoshlar';

    protected $table = 'organizations';

    public const TYPE_VILOYAT_YOSHLAR = 'viloyat_yoshlar';

    public const TYPE_TUMAN_YOSHLAR = 'tuman_yoshlar';

    public const TYPE_VILOYAT_SEKTOR = 'viloyat_sektor';

    public const TYPE_TUMAN_SEKTOR = 'tuman_sektor';

    /** @var array<int, string> */
    public const TYPES = [
        self::TYPE_VILOYAT_YOSHLAR,
        self::TYPE_TUMAN_YOSHLAR,
        self::TYPE_VILOYAT_SEKTOR,
        self::TYPE_TUMAN_SEKTOR,
    ];

    /** Rol -> shu rol biriktirilishi mumkin bo'lgan tashkilot turi. */
    public const ROLE_TYPE = [
        'yoshlar_boshqarma' => self::TYPE_VILOYAT_YOSHLAR,
        'yoshlar_bolim' => self::TYPE_TUMAN_YOSHLAR,
        'sektor_boshqarma' => self::TYPE_VILOYAT_SEKTOR,
        'sektor_bolim' => self::TYPE_TUMAN_SEKTOR,
    ];

    protected $fillable = [
        'type', 'parent_id', 'district_id', 'sector_id',
        'name_cyr', 'name_lat', 'short_name', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<Organization, Organization> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<Organization> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return BelongsTo<Sector, Organization> */
    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class, 'sector_id');
    }

    public function isDistrictLevel(): bool
    {
        return in_array($this->type, [self::TYPE_TUMAN_YOSHLAR, self::TYPE_TUMAN_SEKTOR], true);
    }
}
