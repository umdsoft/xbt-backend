<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Chora-tadbir jurnal yozuvining TASDIQLOVCHI fayli (advisor.action_plan_entry_files).
 * pdf/rasm/Word/Excel — maxfiy diskda; faqat vakolatli route orqali ochiladi.
 */
class ActionPlanEntryFile extends Model
{
    use HasUuids;

    protected $connection = 'advisor';

    protected $table = 'action_plan_entry_files';

    protected $fillable = ['entry_id', 'path', 'original_name', 'mime', 'size_bytes', 'uploaded_by'];

    protected $casts = ['size_bytes' => 'integer'];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(ActionPlanEntry::class, 'entry_id');
    }
}
