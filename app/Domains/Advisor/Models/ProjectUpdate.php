<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Loyiha progress tarixi — bitta yangilanish yozuvi (spec §6).
 * Ixtiyoriy progress_percent bilan; berilsa loyiha progressi yangilanadi.
 */
class ProjectUpdate extends Model
{
    use HasUuids;

    protected $connection = 'advisor';

    protected $table = 'project_updates';

    protected $fillable = [
        'project_id', 'user_id', 'body', 'progress_percent', 'occurred_at',
    ];

    protected $casts = [
        'progress_percent' => 'integer',
        'occurred_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
