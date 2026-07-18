<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class District extends Model
{
    use HasFactory;
    use HasUuids;

    protected $connection = 'hr';

    protected $fillable = [
        'region_id',
        'name_cyr',
        'name_lat',
        'code',
        'is_city',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_city' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function mahallas(): HasMany
    {
        return $this->hasMany(Mahalla::class)->orderBy('sort_order');
    }
}
