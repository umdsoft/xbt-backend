<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Target or excluded country (docs/outreach/country-analysis.md).
 *
 * `excluded = true` means companies from this country are rejected on insert.
 * wave: 1 | 2 | investor.
 */
class Country extends Model
{
    protected $connection = 'advisor';

    protected $table = 'outreach_countries';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'code', 'name', 'wave', 'score', 'excluded', 'excluded_reason', 'default_language',
    ];

    protected $casts = [
        'score' => 'integer',
        'excluded' => 'boolean',
    ];

    /** @return HasMany<Company, $this> */
    public function companies(): HasMany
    {
        return $this->hasMany(Company::class, 'country_code', 'code');
    }
}
