<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Yoshning hal etilishi kerak bo'lgan masalasi (TZ 5.3). */
class YouthCase extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $connection = 'yoshlar';

    protected $table = 'youth_cases';

    /** @var array<int, string> */
    public const CATEGORIES = [
        'bandlik', 'talim', 'uy_joy', 'sogliq', 'huquqiy', 'moliyaviy', 'psixologik', 'oilaviy',
    ];

    /** @var array<int, string> */
    public const SOURCES = ['yosh', 'mahalla', 'murojaat', 'otaliq'];

    /** @var array<int, string> */
    public const STATUSES = ['royxatda', 'biriktirildi', 'jarayonda', 'hal_etildi', 'eskalatsiya'];

    /** @var array<int, string> Yakunlangan holatlar — SLA endi ahamiyatsiz. */
    public const CLOSED_STATUSES = ['hal_etildi'];

    protected $fillable = [
        'youth_id', 'district_id', 'category', 'source', 'source_ref',
        'title', 'description', 'status', 'assigned_org_id', 'assigned_staff_id',
        'sla_deadline', 'resolved_at', 'resolution_note', 'created_by', 'created_by_org_id',
    ];

    /** @var array<int, string> */
    protected $appends = ['sla_state', 'days_left'];

    protected function casts(): array
    {
        return ['sla_deadline' => 'date', 'resolved_at' => 'datetime'];
    }

    /** @return BelongsTo<Youth, YouthCase> */
    public function youth(): BelongsTo
    {
        return $this->belongsTo(Youth::class, 'youth_id');
    }

    public function getDaysLeftAttribute(): int
    {
        if ($this->sla_deadline === null) {
            return 0;
        }

        return (int) CarbonImmutable::today()->diffInDays(
            CarbonImmutable::parse($this->sla_deadline)->startOfDay(),
            false,
        );
    }

    /** Topshiriqdagi kabi svetofor — bir xil qoida, bir xil tushuncha. */
    public function getSlaStateAttribute(): string
    {
        if (in_array($this->status, self::CLOSED_STATUSES, true)) {
            return 'done';
        }

        if ($this->sla_deadline === null) {
            return 'none';
        }

        $days = $this->days_left;

        if ($days < 0) {
            return 'red';
        }

        return $days < 3 ? 'amber' : 'green';
    }

    /**
     * @param  Builder<YouthCase>  $query
     * @return Builder<YouthCase>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', self::CLOSED_STATUSES);
    }
}
