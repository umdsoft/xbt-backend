<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/** Davlat dasturi (ПҚ-393, Drayver, Tashabbusli byudjet, ПҚ-298, DXSh...). */
class Program extends QurilishModel
{
    protected $table = 'programs';

    protected $guarded = [];

    protected $casts = [
        'year' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function objects(): HasMany
    {
        return $this->hasMany(ConstructionObject::class);
    }
}
