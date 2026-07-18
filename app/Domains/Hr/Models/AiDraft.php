<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiDraft extends Model
{
    use HasUuids;

    protected $connection = 'hr';

    protected $fillable = [
        'appeal_id', 'draft_text', 'model_name',
        'based_on_cases', 'approved_by', 'approved_at', 'was_modified',
    ];

    protected function casts(): array
    {
        return [
            'based_on_cases' => 'array',
            'approved_at' => 'datetime',
            'was_modified' => 'boolean',
        ];
    }

    public function appeal(): BelongsTo
    {
        return $this->belongsTo(CitizenAppeal::class, 'appeal_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(HrProfile::class, 'approved_by');
    }
}
