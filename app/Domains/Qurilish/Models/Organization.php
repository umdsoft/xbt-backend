<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tashkilot — buyurtmachi/loyihachi/pudratchi/boshqarma YAGONA reyestrda.
 *
 * Rollar bayroq (bool) sifatida: bitta tashkilot bir vaqtda bir necha rolda
 * bo'lishi mumkin — masalan «Ҳудудий электр тармоқлари» АЖ manbada ham
 * buyurtmachi, ham loyihachi, ham pudratchi sifatida uchraydi.
 */
class Organization extends QurilishModel
{
    protected $table = 'organizations';

    protected $guarded = [];

    protected $casts = [
        'is_customer' => 'boolean',
        'is_designer' => 'boolean',
        'is_contractor' => 'boolean',
        'is_department' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function aliases(): HasMany
    {
        return $this->hasMany(OrganizationAlias::class);
    }
}
