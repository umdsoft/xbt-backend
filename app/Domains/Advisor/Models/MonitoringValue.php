<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * (Entry × ustun) foiz qiymati (advisor.monitoring_values) — 0..100.
 */
class MonitoringValue extends Model
{
    use HasUuids;

    protected $connection = 'advisor';

    protected $table = 'monitoring_values';

    protected $fillable = ['entry_id', 'metric_id', 'value'];

    protected $casts = [
        'value' => 'float',
    ];
}
