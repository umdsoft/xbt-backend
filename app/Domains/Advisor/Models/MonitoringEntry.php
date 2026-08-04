<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * (Svod × tuman) satri (advisor.monitoring_entries) — tasdiq oqimi bilan:
 * draft -> submitted (tuman yuboradi) -> confirmed (viloyat) | returned (izoh bilan).
 */
class MonitoringEntry extends Model
{
    use HasUuids;

    protected $connection = 'advisor';

    protected $table = 'monitoring_entries';

    protected $fillable = [
        'sheet_id', 'district_id', 'note', 'review_status',
        'submitted_at', 'submitted_by', 'confirmed_at', 'confirmed_by',
        'return_comment', 'updated_by',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    /** @return HasMany<MonitoringValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(MonitoringValue::class, 'entry_id');
    }
}
