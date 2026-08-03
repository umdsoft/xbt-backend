<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Models;

use App\Domains\Mahalla\Models\Master\District;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Loyiha (SI/raqamli) — mahalla `micro_projects` naqshi (spec §6).
 *
 * Tuman kesimida portfel: progress_percent bilan holat kuzatiladi; tarix
 * project_updates'da, dalillar project_files'da (maxfiy disk).
 *
 * status: planned | in_progress | done | paused.
 */
class Project extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $connection = 'advisor';

    protected $table = 'projects';

    protected $fillable = [
        'district_id', 'category_id', 'title', 'description',
        'planned_start', 'planned_end', 'actual_end',
        'status', 'progress_percent', 'created_by',
    ];

    protected $casts = [
        'planned_start' => 'date',
        'planned_end' => 'date',
        'actual_end' => 'date',
        'progress_percent' => 'integer',
    ];

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class, 'district_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TaskCategory::class, 'category_id');
    }

    /** @return HasMany<ProjectUpdate, $this> */
    public function updates(): HasMany
    {
        return $this->hasMany(ProjectUpdate::class, 'project_id');
    }

    /** @return HasMany<ProjectFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(ProjectFile::class, 'project_id');
    }
}
