<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Reja bandi (tadbir) — advisor.action_plan_items. xbt ControlPlanItem naqshi.
 *
 * scope: all_districts (har tuman bajaradi + bajarilishini kiritadi) |
 *        viloyat (faqat viloyat darajasi — bajarilishi district_id=null qatorда).
 */
class ActionPlanItem extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $connection = 'advisor';

    protected $table = 'action_plan_items';

    protected $fillable = [
        'plan_id', 'section_title', 'item_number', 'title', 'mechanism',
        'deadline_text', 'deadline', 'responsible_text', 'scope', 'sort_order',
    ];

    protected $casts = [
        'deadline' => 'date',
        'sort_order' => 'integer',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ActionPlan::class, 'plan_id');
    }

    /** @return HasMany<ActionPlanProgress, $this> */
    public function progress(): HasMany
    {
        return $this->hasMany(ActionPlanProgress::class, 'item_id');
    }
}
