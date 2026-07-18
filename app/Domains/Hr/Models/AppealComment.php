<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppealComment extends Model
{
    use HasUuids;

    protected $connection = 'hr';

    protected $fillable = ['appeal_id', 'author_id', 'body', 'is_internal'];

    protected function casts(): array
    {
        return ['is_internal' => 'boolean'];
    }

    public function appeal(): BelongsTo
    {
        return $this->belongsTo(CitizenAppeal::class, 'appeal_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(HrProfile::class, 'author_id');
    }
}
