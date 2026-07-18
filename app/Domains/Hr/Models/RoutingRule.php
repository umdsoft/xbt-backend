<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoutingRule extends Model
{
    use HasUuids;

    protected $connection = 'hr';

    protected $fillable = [
        'hokimlik_id', 'category_id', 'conditions',
        'assignee_type', 'assignee_id',
        'sla_hours_override', 'escalation_after_hours', 'escalation_to',
        'priority', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'escalation_to' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function hokimlik(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'hokimlik_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AppealCategory::class, 'category_id');
    }
}
