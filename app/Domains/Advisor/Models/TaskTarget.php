<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Models;

use App\Domains\Mahalla\Models\Master\District;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Topshiriq nishoni — bitta tumanga tarqatilgan ijro birligi (spec §5).
 *
 * status: pending | reported | qa_checked | approved | returned | closed.
 */
class TaskTarget extends Model
{
    use HasUuids;

    protected $connection = 'advisor';

    protected $table = 'task_targets';

    protected $fillable = [
        'task_id', 'district_id', 'assigned_advisor_id', 'due_at', 'status',
    ];

    protected $casts = ['due_at' => 'datetime'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class, 'district_id');
    }

    /** @return HasMany<TaskReport, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(TaskReport::class, 'task_target_id');
    }
}
