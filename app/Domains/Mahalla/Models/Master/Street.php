<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Models\Master;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MASTER geo — ko'cha. Nomi yagona statik ma'lumot (master).
 */
class Street extends Model
{
    use HasUuids;

    protected $connection = 'master';

    protected $table = 'streets';

    protected $fillable = ['mahalla_id', 'name', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function mahalla(): BelongsTo
    {
        return $this->belongsTo(Mahalla::class);
    }
}
