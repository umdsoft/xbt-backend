<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CitizenFeedback extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $connection = 'hr';

    protected $table = 'citizen_feedback';

    protected $fillable = [
        'appeal_id', 'rating', 'body', 'sentiment_score', 'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'rating' => 'integer',
            'sentiment_score' => 'decimal:3',
        ];
    }

    public function appeal(): BelongsTo
    {
        return $this->belongsTo(CitizenAppeal::class, 'appeal_id');
    }
}
