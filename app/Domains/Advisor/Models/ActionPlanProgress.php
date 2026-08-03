<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Models;

use App\Domains\Mahalla\Models\Master\District;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Band bajarilishi — advisor.action_plan_progress (band × tuman kesimi).
 * district_id null = viloyat darajasidagi band bajarilishi.
 *
 * status: not_started | in_progress | completed.
 */
class ActionPlanProgress extends Model
{
    use HasUuids;

    protected $connection = 'advisor';

    protected $table = 'action_plan_progress';

    protected $fillable = [
        'item_id', 'district_id', 'status', 'report', 'progress_percent', 'updated_by',
    ];

    protected $casts = [
        'progress_percent' => 'integer',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(ActionPlanItem::class, 'item_id');
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class, 'district_id');
    }
}
