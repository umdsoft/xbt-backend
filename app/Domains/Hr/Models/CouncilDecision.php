<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CouncilDecision extends Model
{
    use HasUuids;

    protected $connection = 'hr';

    protected $fillable = [
        'appeal_id', 'council_id', 'meeting_date',
        'decision_type', 'decision_text', 'voting_result',
        'decided_by', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'meeting_date' => 'date',
            'decided_at' => 'datetime',
            'voting_result' => 'array',
        ];
    }

    public function appeal(): BelongsTo
    {
        return $this->belongsTo(CitizenAppeal::class, 'appeal_id');
    }

    public function council(): BelongsTo
    {
        return $this->belongsTo(MahallaCouncil::class, 'council_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(HrProfile::class, 'decided_by');
    }
}
