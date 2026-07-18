<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemDocument extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $connection = 'hr';

    protected $fillable = [
        'control_plan_item_id', 'task_response_id', 'file_path', 'original_name',
        'file_size', 'mime_type', 'description', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'file_size' => 'integer',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ControlPlanItem::class, 'control_plan_item_id');
    }

    public function response(): BelongsTo
    {
        return $this->belongsTo(TaskResponse::class, 'task_response_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(HrProfile::class, 'uploaded_by');
    }
}
