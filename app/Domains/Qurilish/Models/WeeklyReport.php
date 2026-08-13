<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Haftalik ijro hisoboti — qurilish davom etayotgan obyekt uchun.
 *
 * Holat lug'ati bosqich moderatsiyasi bilan BIR XIL: hisobot ham
 * tasdiqlanmaguncha rasmiy hisoblanmaydi, arxivda esa u tasdiq belgisi
 * bilan turadi — keyin «kim nima yozgan edi» degan savol tug'ilmasin.
 */
class WeeklyReport extends QurilishModel
{
    protected $table = 'object_weekly_reports';

    protected $guarded = [];

    /** Bosqich holatlarining haftalik hisobotga tegishli qismi. */
    public const STATUSES = [
        'qoralama',
        'tasdiqlash_kutilmoqda',
        'korib_chiqilmoqda',
        'tasdiqlangan',
        'rad_etilgan',
    ];

    public const EDITABLE_STATUSES = ['qoralama', 'rad_etilgan'];

    public const PENDING_STATUSES = ['tasdiqlash_kutilmoqda', 'korib_chiqilmoqda'];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'year' => 'integer',
        'week_no' => 'integer',
        'progress_pct' => 'float',
        'week_progress_pct' => 'float',
        'disbursed_amount' => 'float',
        'workers_count' => 'integer',
        'equipment_count' => 'integer',
    ];

    public function object(): BelongsTo
    {
        return $this->belongsTo(ConstructionObject::class, 'object_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(ObjectMedia::class, 'weekly_report_id');
    }
}
