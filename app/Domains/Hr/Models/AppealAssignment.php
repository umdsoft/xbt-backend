<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppealAssignment extends Model
{
    use HasUuids;

    protected $connection = 'hr';

    protected $fillable = [
        'appeal_id', 'assignee_type', 'assignee_id',
        'assigned_by', 'reason', 'status',
        'assigned_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function appeal(): BelongsTo
    {
        return $this->belongsTo(CitizenAppeal::class, 'appeal_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(HrProfile::class, 'assigned_by');
    }

    /** Polymorphic-like resolver — `assignee_type` ga qarab to'g'ri model qaytaradi. */
    public function resolveAssignee(): ?Model
    {
        return match ($this->assignee_type) {
            'council' => MahallaCouncil::with('mahalla')->find($this->assignee_id),
            'department' => Department::find($this->assignee_id),
            'user' => HrProfile::find($this->assignee_id),
            default => null,
        };
    }
}
