<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Models;

use App\Domains\Ayollar\Concerns\ResolvesClientRef;
use App\Domains\Ayollar\Services\PiiCipher;
use App\Support\Text\PersonName;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Ayol — reyestrning asosiy yozuvi.
 *
 * MAXFIYLIK: `pinfl`, `passport`, `phone` XOM SAQLANMAYDI. Ular
 * `PiiCipher` (AES-256-GCM) bilan shifrlanadi va `$hidden` da — ya'ni
 * `toArray()`/JSON javobga TASODIFAN ham tushmaydi. To'liq qiymatni
 * ko'rish uchun `revealPinfl()` ataylab chaqirilishi kerak va u
 * jurnalga yozadi.
 *
 * NEGA `$hidden` YETARLI EMAS EDI: `$hidden` faqat serializatsiyani
 * to'sadi, `$woman->pinfl` esa baribir ishlaydi. Shuning uchun accessor
 * MASKALANGAN qiymat qaytaradi — xom qiymatga yetish uchun boshqa
 * metod nomini yozish kerak, ya'ni tasodifan sizib chiqmaydi.
 */
class Woman extends Model
{
    use HasUuids;
    use ResolvesClientRef;
    use SoftDeletes;

    protected $connection = 'ayollar';

    protected $table = 'women';

    protected $fillable = [
        'household_id', 'mahalla_id', 'district_id', 'full_name', 'full_name_norm',
        'birth_date', 'age_group', 'consent_signed_at', 'consent_signature_path',
        'created_by', 'updated_by', 'client_uuid',
    ];

    /**
     * Shifrlangan ustunlar HECH QACHON javobga tushmaydi.
     *
     * `pinfl_hash` ham yashirin: u qidiruv kaliti, tashqariga chiqsa
     * dublikatni oflayn aniqlash mumkin bo'lardi.
     */
    protected $hidden = [
        'pinfl_encrypted', 'passport_encrypted', 'phone_encrypted', 'pinfl_hash',
    ];

    /**
     * Ro'yxatlarda ko'rsatiladigan F.I.Sh.
     *
     * SAQLANGAN QIYMAT O'ZGARMAYDI — `full_name` faol qanday yozgan
     * bo'lsa, shundayligicha qoladi (hujjatdagi asl shakl). Bu esa
     * faqat KO'RSATISH uchun: bitta jadvalda «АБДУЛЛАЕВА САНОБАР»,
     * «Salayeva Halima» va «bobojonova soxiba» yonma-yon turganda
     * ro'yxatni o'qib ham, saralab ham bo'lmaydi.
     *
     * Alohida maydon, `full_name` ning ustiga yozilmaydi: klient
     * qaysi birini ko'rsatishni o'zi hal qiladi va asl yozuv
     * kerak bo'lganda qo'lda qoladi.
     */
    protected $appends = ['full_name_display'];

    protected function fullNameDisplay(): Attribute
    {
        return Attribute::make(
            get: fn (): string => PersonName::standard($this->attributes['full_name'] ?? null),
        );
    }

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'consent_signed_at' => 'datetime',
        ];
    }

    // ---------------------------------------------------------------
    // PII — yozish shifrlaydi, o'qish MASKALAYDI
    // ---------------------------------------------------------------

    protected function pinfl(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->cipher()->mask($this->cipher()->decrypt($this->pinfl_encrypted)),
            set: fn (?string $v): array => [
                'pinfl_encrypted' => $this->cipher()->encrypt($v),
                'pinfl_hash' => $v === null || $v === '' ? null : $this->cipher()->hash($v),
            ],
        );
    }

    protected function passport(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->cipher()->mask($this->cipher()->decrypt($this->passport_encrypted), 3),
            set: fn (?string $v): array => ['passport_encrypted' => $this->cipher()->encrypt($v)],
        );
    }

    protected function phone(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->cipher()->mask($this->cipher()->decrypt($this->phone_encrypted)),
            set: fn (?string $v): array => ['phone_encrypted' => $this->cipher()->encrypt($v)],
        );
    }

    /**
     * Xom PII — FAQAT ataylab, jurnal bilan.
     *
     * Chaqiruvchi `SensitiveAccessService` orqali o'tishi shart; bu metod
     * o'zi jurnal yozmaydi, chunki u kim so'raganini bilmaydi. Nomi
     * ataylab «noqulay» — kod ko'rigida darhol ko'zga tashlanadi.
     */
    public function revealRawPii(string $field): ?string
    {
        return match ($field) {
            'pinfl' => $this->cipher()->decrypt($this->pinfl_encrypted),
            'passport' => $this->cipher()->decrypt($this->passport_encrypted),
            'phone' => $this->cipher()->decrypt($this->phone_encrypted),
            default => null,
        };
    }

    private function cipher(): PiiCipher
    {
        return app(PiiCipher::class);
    }

    // ---------------------------------------------------------------

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @return HasMany<Anketa, $this> */
    public function anketas(): HasMany
    {
        return $this->hasMany(Anketa::class);
    }

    /** Rozilik imzosi qo'yilganmi — anketa saqlashning qattiq sharti. */
    public function hasConsent(): bool
    {
        return $this->consent_signed_at !== null;
    }
}
