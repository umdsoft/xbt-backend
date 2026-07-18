<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use App\Domains\Hr\Enums\AssigneeType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Топшириқ масъули — полиморфик: ички ходим (user) ёки ташкилот (organization).
 */
class ItemResponsible extends Model
{
    use HasUuids;

    protected $connection = 'hr';

    protected $fillable = [
        'control_plan_item_id', 'assignee_type', 'assignee_id',
        'user_id', 'responsible_name', 'responsible_position', 'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'assignee_type' => AssigneeType::class,
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ControlPlanItem::class, 'control_plan_item_id');
    }

    /** Эски/тўғридан-тўғри user боғланиши (орқага мослик). */
    public function user(): BelongsTo
    {
        return $this->belongsTo(HrProfile::class);
    }

    /** assignee_type='organization' бўлганда ташкилот. */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'assignee_id');
    }

    /**
     * Полиморфик масъулни ҳал қилиш — assignee_type'га қараб тўғри моделни қайтаради.
     */
    public function resolveAssignee(): ?Model
    {
        return match ($this->assignee_type) {
            AssigneeType::USER => HrProfile::find($this->assignee_id ?? $this->user_id),
            AssigneeType::ORGANIZATION => Organization::withoutTenantScope()->find($this->assignee_id),
            default => $this->user_id ? HrProfile::find($this->user_id) : null,
        };
    }

    /** Масъул номи — модельдан ёки snapshot'дан. */
    public function displayName(): string
    {
        $assignee = $this->resolveAssignee();

        if ($assignee instanceof HrProfile) {
            return $assignee->name;
        }

        if ($assignee instanceof Organization) {
            return $assignee->name_cyr;
        }

        return $this->responsible_name ?? '—';
    }
}
