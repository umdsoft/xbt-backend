<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Oylik ijro grafigi (Yanvar-Dekabr) — reja va amaldagi qiymat (mln so'm). */
class ObjectMonthlyPlan extends QurilishModel
{
    protected $table = 'object_monthly_plan';

    protected $guarded = [];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'planned_amount' => 'decimal:3',
        'actual_amount' => 'decimal:3',
    ];

    public function object(): BelongsTo
    {
        return $this->belongsTo(ConstructionObject::class, 'object_id');
    }
}
