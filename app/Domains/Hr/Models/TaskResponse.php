<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Топшириқ бўйича берилган жавоб / тасдиқлаш ҳодисаси (EDO timeline бандлари).
 * Ҳар бир жавобга файллар (item_documents.task_response_id) бириктирилади.
 */
class TaskResponse extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $connection = 'hr';

    protected $fillable = [
        'control_plan_item_id', 'author_id', 'author_name', 'author_org', 'type', 'body',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ControlPlanItem::class, 'control_plan_item_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(HrProfile::class, 'author_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ItemDocument::class, 'task_response_id');
    }
}
