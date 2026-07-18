<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppealEmbedding extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $connection = 'hr';

    protected $fillable = [
        'appeal_id', 'model_name', 'qdrant_point_id',
        'collection_name', 'dimensions', 'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function appeal(): BelongsTo
    {
        return $this->belongsTo(CitizenAppeal::class, 'appeal_id');
    }
}
