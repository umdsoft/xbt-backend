<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tuman maslahatchisining hisoboti (body + dalil). Bir target -> N hisobot
 * (qaytarilsa qayta yuboriladi); QA/tasdiq oxirgi hisobot ustida (spec §5).
 *
 * status: pending | qa_checked | approved | returned.
 * ai_evaluation — kelajak (AI avto-baho) uchun bo'sh joy (spec §14).
 */
class TaskReport extends Model
{
    use HasUuids;

    protected $connection = 'advisor';

    protected $table = 'task_reports';

    protected $fillable = [
        'task_target_id', 'advisor_id', 'body', 'submitted_at',
        'status', 'evidence_index', 'ai_evaluation',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'evidence_index' => 'integer',
        'ai_evaluation' => 'array',
    ];

    public function target(): BelongsTo
    {
        return $this->belongsTo(TaskTarget::class, 'task_target_id');
    }

    /** @return HasMany<ReportFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(ReportFile::class, 'report_id');
    }

    /** @return HasMany<TaskReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(TaskReview::class, 'report_id');
    }
}
