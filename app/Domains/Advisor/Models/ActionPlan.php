<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Yillik chora-tadbirlar rejasi (advisor.action_plans). Yiliga bitta faol reja;
 * bandlar action_plan_items'da, bajarilishi band×tuman kesimida.
 *
 * status: draft | active | closed.
 */
class ActionPlan extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $connection = 'advisor';

    protected $table = 'action_plans';

    protected $fillable = [
        'year', 'title', 'status', 'created_by',
        'document_path', 'document_name', 'document_mime', 'document_size',
    ];

    protected $casts = [
        'year' => 'integer',
    ];

    /** @return HasMany<ActionPlanItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ActionPlanItem::class, 'plan_id')->orderBy('sort_order');
    }
}
