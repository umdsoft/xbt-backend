<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use App\Domains\Hr\Enums\ReviewStatus;
use App\Domains\Hr\Enums\TaskSource;
use App\Domains\Hr\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Умумий топшириқ: назорат режа ичида (control_plan_id) ёки мустақил (source=standalone).
 * Тенант бўйича hokimlik_id орқали изоляция қилинади.
 */
class ControlPlanItem extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use LogsActivity;

    public const TENANT_COLUMN = 'hokimlik_id';

    protected $connection = 'hr';

    protected $fillable = [
        'control_plan_id', 'source', 'hokimlik_id', 'kompleks_id', 'created_by',
        'title', 'item_number', 'section_title', 'task_description',
        'implementation', 'funding_source', 'link', 'deadline',
        'execution_status', 'execution_report', 'sort_order',
        'review_status', 'submitted_at', 'reviewed_at', 'reviewed_by', 'review_comment',
        'control_removed_at', 'control_removed_by', 'control_removal_reason',
    ];

    protected function casts(): array
    {
        return [
            'deadline' => 'date',
            'source' => TaskSource::class,
            'review_status' => ReviewStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'control_removed_at' => 'datetime',
        ];
    }

    /** Топшириқ назоратда турганми (ҳали ечилмаган). */
    public function isUnderControl(): bool
    {
        return $this->control_removed_at === null;
    }

    /** Тасдиқ кутаяптими (ташкилот юборган, ҳали кўрилмаган). */
    public function isAwaitingApproval(): bool
    {
        return $this->review_status === ReviewStatus::SUBMITTED;
    }

    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(HrProfile::class, 'control_removed_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(HrProfile::class, 'reviewed_by');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ControlPlan::class, 'control_plan_id');
    }

    public function kompleks(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'kompleks_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(HrProfile::class, 'created_by');
    }

    public function responsibles(): HasMany
    {
        // Asosiy ijrochi (is_primary) har doim birinchi
        return $this->hasMany(ItemResponsible::class)->orderByDesc('is_primary');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ItemDocument::class);
    }

    /** Берилган жавоблар / тасдиқлаш ҳодисалари — энг янгиси биринчи. */
    public function responses(): HasMany
    {
        return $this->hasMany(TaskResponse::class)->orderByDesc('created_at');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['execution_status', 'execution_report'])
            ->logOnlyDirty()
            ->setDescriptionForEvent(function (string $eventName) {
                $labels = [
                    'not_started' => 'Бажарилмаган',
                    'in_progress' => 'Бажарилмоқда',
                    'completed' => 'Бажарилган',
                    'overdue' => 'Муддати ўтган',
                ];
                $status = $labels[$this->execution_status] ?? $this->execution_status;

                return "Ҳолат: {$status}";
            });
    }
}
