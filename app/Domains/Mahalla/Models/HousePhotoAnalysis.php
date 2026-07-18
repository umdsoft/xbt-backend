<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HONADON — AI (Claude Vision) tahlili. Auto-tasdiq/flag qarori shu yerda.
 */
class HousePhotoAnalysis extends Model
{
    protected $connection = 'mahalla';

    use HasUuids;

    protected $fillable = [
        'house_photo_id', 'baseline_photo_id',
        'zone', 'prev_status', 'suggested_status', 'is_change',
        'same_house', 'confidence', 'cheating_suspected',
        'changes', 'daily_work', 'progress_percent',
        'decision', 'decision_reason', 'raw_response',
        'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'is_change' => 'boolean',
        'same_house' => 'boolean',
        'confidence' => 'float',
        'cheating_suspected' => 'boolean',
        'changes' => 'array',
        'progress_percent' => 'integer',
        'raw_response' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function photo(): BelongsTo
    {
        return $this->belongsTo(HousePhoto::class, 'house_photo_id');
    }

    public function baselinePhoto(): BelongsTo
    {
        return $this->belongsTo(HousePhoto::class, 'baseline_photo_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
