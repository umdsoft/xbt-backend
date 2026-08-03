<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * KPI maqsadli qiymati (advisor.kpi_targets) — reja (spec §7). district_id null
 * = viloyat darajasi. Ijro% = kpi_entries.value / target.
 */
class KpiTarget extends Model
{
    use HasUuids;

    protected $connection = 'advisor';

    protected $table = 'kpi_targets';

    protected $fillable = ['kpi_id', 'district_id', 'period', 'target'];

    protected $casts = [
        'target' => 'decimal:2',
    ];

    public function kpi(): BelongsTo
    {
        return $this->belongsTo(Kpi::class, 'kpi_id');
    }
}
