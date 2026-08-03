<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sifat-tekshiruv / tasdiq / qaytarish audit izi (spec §5).
 * action: qa_check | approve | return.
 */
class TaskReview extends Model
{
    use HasUuids;

    protected $connection = 'advisor';

    protected $table = 'task_reviews';

    protected $fillable = ['report_id', 'reviewer_id', 'action', 'comment'];

    public function report(): BelongsTo
    {
        return $this->belongsTo(TaskReport::class, 'report_id');
    }
}
