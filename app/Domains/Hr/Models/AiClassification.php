<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiClassification extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $connection = 'hr';

    protected $fillable = [
        'appeal_id', 'model_name',
        'category_predicted', 'sub_category_predicted',
        'priority_predicted', 'confidence',
        'similar_appeals', 'reasoning', 'raw_response', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'similar_appeals' => 'array',
            'raw_response' => 'array',
            'confidence' => 'decimal:4',
            'created_at' => 'datetime',
        ];
    }

    public function appeal(): BelongsTo
    {
        return $this->belongsTo(CitizenAppeal::class, 'appeal_id');
    }
}
