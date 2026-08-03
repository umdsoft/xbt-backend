<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * KPI katalog yozuvi (advisor.kpis) — yo'riqnoma IX bo'limidan seed qilinadi
 * (spec §7). `code` barqaror kalit; `scope` = viloyat|tuman.
 */
class Kpi extends Model
{
    use HasUuids;

    protected $connection = 'advisor';

    protected $table = 'kpis';

    protected $fillable = ['code', 'name', 'unit', 'scope', 'sort', 'active'];

    protected $casts = [
        'sort' => 'integer',
        'active' => 'boolean',
    ];

    /** @return HasMany<KpiEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(KpiEntry::class, 'kpi_id');
    }

    /** @return HasMany<KpiTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(KpiTarget::class, 'kpi_id');
    }
}
