<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Ishga joylashtirish arizasi va uning 3 tomonlama tasdiqlash holati.
 *
 * HOLATLAR: `yuborildi` -> `tuman_soliq_tasdiq` -> `rasman_band`.
 * Istalgan bosqichda `qaytarildi` ga tushishi mumkin.
 */
class EmploymentCase extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $connection = 'yoshlar';

    protected $table = 'employment_cases';

    public const STATUS_SUBMITTED = 'yuborildi';

    public const STATUS_TAX_DISTRICT = 'tuman_soliq_tasdiq';

    public const STATUS_CONFIRMED = 'rasman_band';

    public const STATUS_RETURNED = 'qaytarildi';

    /** @var array<int, string> */
    public const STATUSES = [
        self::STATUS_SUBMITTED,
        self::STATUS_TAX_DISTRICT,
        self::STATUS_CONFIRMED,
        self::STATUS_RETURNED,
    ];

    /** Ochiq (hali yakunlanmagan) arizalar — bittadan ko'p bo'lmaydi. */
    public const OPEN_STATUSES = [self::STATUS_SUBMITTED, self::STATUS_TAX_DISTRICT];

    protected $fillable = [
        'youth_id', 'district_id', 'employer_name', 'employer_inn', 'position',
        'salary', 'start_date', 'contract_number', 'document_path', 'status',
        'submitted_by', 'submitted_org_id', 'submitted_at',
        'tax_district_by', 'tax_district_at', 'tax_province_by', 'tax_province_at',
        'reject_reason',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'submitted_at' => 'datetime',
            'tax_district_at' => 'datetime',
            'tax_province_at' => 'datetime',
            'salary' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Youth, EmploymentCase> */
    public function youth(): BelongsTo
    {
        return $this->belongsTo(Youth::class, 'youth_id');
    }

    /** Zanjirning navbatdagi bosqichi — kim harakat qilishi kerak. */
    public function nextStage(): ?string
    {
        return match ($this->status) {
            self::STATUS_SUBMITTED => 'tax_district',
            self::STATUS_TAX_DISTRICT => 'tax_province',
            default => null,
        };
    }
}
