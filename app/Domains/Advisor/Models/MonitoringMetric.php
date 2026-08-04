<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Svod ustuni (advisor.monitoring_metrics) — og'irlikли ko'rsatkич.
 * UMUMIY TAYYORLIK = Σ(value × weight) / Σ(weight).
 */
class MonitoringMetric extends Model
{
    use HasUuids;

    protected $connection = 'advisor';

    protected $table = 'monitoring_metrics';

    protected $fillable = ['sheet_id', 'name', 'unit', 'weight', 'sort_order'];

    protected $casts = [
        'weight' => 'float',
        'sort_order' => 'integer',
    ];
}
