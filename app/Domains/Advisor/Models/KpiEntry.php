<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * KPI kiritilgan qiymati (advisor.kpi_entries) — ijro (spec §7). district_id null
 * = viloyat darajasi. source: manual (qo'lda) | auto (derivatsiya). status:
 * draft | submitted | approved. Ijro% = value / target (KpiService hisoblaydi).
 */
class KpiEntry extends Model
{
    use HasUuids;

    protected $connection = 'advisor';

    protected $table = 'kpi_entries';

    protected $fillable = [
        'kpi_id', 'district_id', 'period', 'value', 'note',
        'source', 'status', 'entered_by', 'approved_by',
    ];

    protected $casts = [
        'value' => 'decimal:2',
    ];

    public function kpi(): BelongsTo
    {
        return $this->belongsTo(Kpi::class, 'kpi_id');
    }
}
