<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * СВОД ЖАДВАЛ (advisor.monitoring_sheets) — bitta qaror/farmon ijrosi monitoringi.
 * Ustunlar monitoring_metrics'да; (tuman) satrlari monitoring_entries'да.
 */
class MonitoringSheet extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $connection = 'advisor';

    protected $table = 'monitoring_sheets';

    protected $fillable = [
        'title', 'basis', 'reference_no', 'reference_date', 'category',
        'as_of_date', 'completion_threshold', 'status', 'created_by',
    ];

    protected $casts = [
        'reference_date' => 'date',
        'as_of_date' => 'date',
        'completion_threshold' => 'integer',
    ];

    /** @return HasMany<MonitoringMetric, $this> */
    public function metrics(): HasMany
    {
        return $this->hasMany(MonitoringMetric::class, 'sheet_id')->orderBy('sort_order');
    }

    /** @return HasMany<MonitoringEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(MonitoringEntry::class, 'sheet_id');
    }
}
