<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Models\Master;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * MASTER geo — mahalla. `master` schema/ulanishida (yagona manba, read-mostly).
 */
class Mahalla extends Model
{
    use HasUuids;

    protected $connection = 'master';

    protected $table = 'mahallas';

    protected $fillable = ['district_id', 'name_cyr', 'name_lat', 'center_lat', 'center_lng', 'sort_order', 'is_active'];

    protected $casts = ['center_lat' => 'float', 'center_lng' => 'float', 'is_active' => 'boolean'];

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function streets(): HasMany
    {
        return $this->hasMany(Street::class);
    }
}
