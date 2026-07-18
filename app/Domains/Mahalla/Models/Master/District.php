<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Models\Master;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * MASTER geo — tuman/shahar. Pilot va scoping shu daraja bo'yicha.
 */
class District extends Model
{
    use HasUuids;

    protected $connection = 'master';

    protected $table = 'districts';

    protected $fillable = ['region_id', 'name_cyr', 'name_lat', 'code', 'sort_order'];

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function mahallas(): HasMany
    {
        return $this->hasMany(Mahalla::class);
    }
}
