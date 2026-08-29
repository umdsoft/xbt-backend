<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Anketa — 31 savol, versiyalangan, toifasi AVTOMATIK aniqlanadi.
 *
 * `category` faqat `green`, `yellow` yoki `incomplete` bo'ladi. `red` YO'Q:
 * qizil alohida bo'lak emas, u `redFlags` orqali ustma-ust turadi. Agar
 * `category = 'red'` yozilsa, «yashil + sariq = jami» tengligi buzilardi.
 */
class Anketa extends Model
{
    use HasUuids;
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_SYNCED = 'synced';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_RETURNED = 'returned';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_COMPLETED, self::STATUS_SYNCED,
        self::STATUS_APPROVED, self::STATUS_RETURNED,
    ];

    /** Balansga KIRADIGAN holatlar. Qoralama va qaytarilgan hisobga olinmaydi. */
    public const COUNTABLE_STATUSES = [
        self::STATUS_COMPLETED, self::STATUS_SYNCED, self::STATUS_APPROVED,
    ];

    protected $connection = 'ayollar';

    protected $table = 'anketas';

    protected $fillable = [
        'woman_id', 'mahalla_id', 'district_id', 'reg_number', 'form_version',
        'age_group', 'answers', 'category', 'balance_row', 'resolution_trace',
        'status', 'device_id', 'filled_at', 'synced_at', 'gps_lat', 'gps_lng',
        'qr_token', 'qr_hmac', 'created_by', 'updated_by', 'client_uuid',
    ];

    /**
     * `qr_hmac` javobga TUSHMAYDI.
     *
     * U QR havolasining imzosi. Tashqariga chiqsa, istalgan token uchun
     * to'g'ri imzo yasash mumkin bo'lardi va soxta «haqiqiy hujjat»
     * sahifasi ochilardi.
     */
    protected $hidden = ['qr_hmac'];

    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'resolution_trace' => 'array',
            'filled_at' => 'datetime',
            'synced_at' => 'datetime',
            'gps_lat' => 'float',
            'gps_lng' => 'float',
        ];
    }

    /** @return BelongsTo<Woman, $this> */
    public function woman(): BelongsTo
    {
        return $this->belongsTo(Woman::class);
    }

    /** @return HasMany<AnketaRedFlag, $this> */
    public function redFlags(): HasMany
    {
        return $this->hasMany(AnketaRedFlag::class);
    }

    /** @param \Illuminate\Database\Eloquent\Builder<self> $q */
    public function scopeCountable($q)
    {
        return $q->whereIn('status', self::COUNTABLE_STATUSES)
            ->whereIn('category', ['green', 'yellow']);
    }

    /**
     * Anketa hali tahrirlanadimi.
     *
     * Balans yopilgandan keyin tahrir TAQIQ: aks holda tasdiqlangan
     * hisobot ostidan raqam o'zgarib, imzolar allaqachon yo'q ma'lumotni
     * tasdiqlagan bo'lib qolardi.
     */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_COMPLETED, self::STATUS_RETURNED], true);
    }
}
