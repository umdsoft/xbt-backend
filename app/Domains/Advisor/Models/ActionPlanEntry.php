<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Chora-tadbir bajarilishi JURNAL yozuvi (advisor.action_plan_entries).
 * Tuman ma'lumot qo'shadi (arxiv); davriy topshiriq uchun bir nechta yozuv.
 * Har yozuvda kamida bitta TASDIQLOVCHI fayl bo'ladi (files()).
 */
class ActionPlanEntry extends Model
{
    use HasUuids;

    protected $connection = 'advisor';

    protected $table = 'action_plan_entries';

    protected $fillable = ['item_id', 'district_id', 'report', 'progress_percent', 'occurred_at', 'created_by'];

    protected $casts = [
        'occurred_at' => 'date',
        'progress_percent' => 'integer',
    ];

    /** Tasdiqlovchi fayllar (dalil). */
    public function files(): HasMany
    {
        return $this->hasMany(ActionPlanEntryFile::class, 'entry_id');
    }
}
