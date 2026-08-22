<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Models;

use App\Domains\Yoshlar\Support\Translit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Yoshlar reyestri — barcha modul suyanadigan jadval.
 *
 * PII: `pinfl`/`passport_*` shifrlangan cast bilan saqlanadi VA `$hidden` —
 * `encrypted` cast ochiq matnni qaytargani uchun, `$hidden` bo'lmasa ro'yxat
 * javobida ham PINFL brauzerga ketardi. Bitta yozuvni ochish kerak bo'lganda
 * `PiiGuard` ishlatiladi (jurnal bilan birga).
 */
class Youth extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $connection = 'yoshlar';

    protected $table = 'youth';

    /** @var array<int, string> */
    public const EDUCATION_STATUSES = ['maktab', 'kollej', 'otm', 'bitiruvchi', 'oqimaydi'];

    /** @var array<int, string> */
    public const EMPLOYMENT_STATUSES = ['band', 'band_emas', 'oqiydi', 'tadbirkor', 'migratsiya'];

    /** @var array<int, string> */
    public const REGISTRY_STATUSES = ['active', 'archived_age', 'moved', 'deceased'];

    /** @var array<int, string> */
    public const VERIFICATION_STATUSES = ['pending', 'verified', 'rejected'];

    public const MIN_AGE = 14;

    public const MAX_AGE = 30;

    protected $fillable = [
        'last_name', 'first_name', 'middle_name', 'birth_date', 'gender',
        'district_id', 'mahalla_id', 'address', 'phone',
        'pinfl', 'passport_series', 'passport_number',
        'education_status', 'education_place', 'employment_status', 'workplace',
        'is_neet', 'is_graduate_unemployed', 'in_patronage', 'has_open_case',
        'in_youth_book', 'is_entrepreneur',
        'registry_status', 'verification_status', 'verified_by', 'verified_at', 'reject_reason',
        'created_by_org_id', 'created_by', 'updated_by',
    ];

    /** @var array<int, string> */
    protected $hidden = ['pinfl', 'pinfl_hash', 'passport_series', 'passport_number'];

    /** @var array<int, string> */
    protected $appends = ['age', 'full_name'];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'verified_at' => 'datetime',
            'pinfl' => 'encrypted',
            'passport_series' => 'encrypted',
            'passport_number' => 'encrypted',
            'is_neet' => 'boolean',
            'is_graduate_unemployed' => 'boolean',
            'in_patronage' => 'boolean',
            'has_open_case' => 'boolean',
            'in_youth_book' => 'boolean',
            'is_entrepreneur' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Youth $youth): void {
            // PINFL shifrlangani uchun (har safar boshqa shifrmatn) DB unique
            // indeks uni ushlay olmaydi — deterministik HMAC hash saqlaymiz.
            $plain = $youth->pinfl;
            $youth->attributes['pinfl_hash'] = ($plain !== null && $plain !== '')
                ? self::hashPinfl((string) $plain)
                : null;

            $youth->attributes['full_name_norm'] = Translit::normalize(
                trim($youth->last_name.' '.$youth->first_name.' '.($youth->middle_name ?? '')),
            );
        });
    }

    /** Deterministik HMAC-SHA256 — unikallik tekshiruvi uchun (HR naqshi). */
    public static function hashPinfl(string $plain): string
    {
        return hash_hmac('sha256', trim($plain), (string) config('app.key'));
    }

    /**
     * Yosh oralig'i bo'yicha filtr.
     *
     * `age()` PostgreSQL'da STABLE (immutable emas) — generated ustunga ham,
     * indeksga ham yaramaydi. Shuning uchun filtr SANA oralig'iga aylantiriladi:
     * `birth_date` indeksi to'liq ishlaydi.
     *
     * @param  Builder<Youth>  $query
     * @return Builder<Youth>
     */
    public function scopeAgeBetween(Builder $query, int $min, int $max): Builder
    {
        $today = CarbonImmutable::today();

        return $query
            ->where('birth_date', '<=', $today->subYears($min)->toDateString())
            ->where('birth_date', '>', $today->subYears($max + 1)->toDateString());
    }

    /**
     * @param  Builder<Youth>  $query
     * @return Builder<Youth>
     */
    public function scopeVisibleInRegistry(Builder $query): Builder
    {
        return $query->where('verification_status', 'verified');
    }

    public function getAgeAttribute(): int
    {
        return $this->birth_date === null ? 0 : (int) $this->birth_date->diffInYears(now());
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->last_name.' '.$this->first_name.' '.($this->middle_name ?? ''));
    }
}
