<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Import normalizatsiyasi: xom tashkilot nomi -> kanonik yozuv.
 *
 * Manbada bitta tashkilot 5-6 xil yozilgan (tirnoqli/tirnoqsiz, MCHJ va
 * «MAS'ULIYATI CHEKLANGAN JAMIYAT», kirill/lotin, ichki qator uzilishi).
 * `alias_norm` — solishtirish uchun tozalangan shakl.
 */
class OrganizationAlias extends QurilishModel
{
    protected $table = 'organization_aliases';

    public $timestamps = false;

    protected $guarded = [];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
