<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Models;

use App\Domains\Ayollar\Concerns\ResolvesClientRef;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Xonadon — oila darajasidagi ma'lumot, bir marta kiritiladi.
 *
 * Ayollar unga bog'lanadi: manzil, uy-joy turi va ta'mir ehtiyoji har ayolda
 * takrorlanmasin. Aks holda bitta xonadondagi 4 ayolning manzili bir-biridan
 * farq qilib qolishi mumkin edi.
 */
class Household extends Model
{
    use HasUuids;
    use ResolvesClientRef;

    protected $connection = 'ayollar';

    protected $table = 'households';

    protected $fillable = [
        'mahalla_id', 'district_id', 'address', 'residence_type', 'housing_type',
        'repair_need', 'in_social_registry', 'lat', 'lng', 'created_by', 'client_uuid',
        // Kadastr bog'lanishi — manzil endi ro'yxatdan tanlanadi.
        'street_id', 'building_id', 'house_number', 'cadastre', 'family_label',
    ];

    protected function casts(): array
    {
        return [
            'in_social_registry' => 'boolean',
            'lat' => 'float',
            'lng' => 'float',
        ];
    }

    /** @return HasMany<Woman, $this> */
    public function women(): HasMany
    {
        return $this->hasMany(Woman::class);
    }
}
