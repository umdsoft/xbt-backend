<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Models\Master;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * MASTER geo — viloyat.
 */
class Region extends Model
{
    use HasUuids;

    protected $connection = 'master';

    protected $table = 'regions';

    protected $fillable = ['name_cyr', 'name_lat', 'code', 'sort_order'];

    public function districts(): HasMany
    {
        return $this->hasMany(District::class);
    }
}
