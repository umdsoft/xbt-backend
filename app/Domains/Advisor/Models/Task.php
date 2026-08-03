<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Topshiriq (yuqori tashkilotdan). Viloyat maslahatchisi yaratadi va bir yoki
 * bir nechta tumanga (task_targets) tarqatadi (spec §5).
 *
 * status: open | in_progress | closed.
 */
class Task extends Model
{
    use HasUuids;

    protected $connection = 'advisor';

    protected $table = 'tasks';

    protected $fillable = [
        'source', 'title', 'description', 'expected_result',
        'category_id', 'priority', 'deadline', 'created_by', 'status',
    ];

    protected $casts = ['deadline' => 'date'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(TaskCategory::class, 'category_id');
    }

    /** @return HasMany<TaskTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(TaskTarget::class, 'task_id');
    }
}
