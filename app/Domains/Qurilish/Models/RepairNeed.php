<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Ta'mirtalab obyekt — kelgusi yil/dasturlar uchun reyestr (loyihaning 2-maqsadi).
 *
 * `promoted_object_id` pipeline'ni yopadi: yozuv real loyihaga aylanganda
 * bog'lanish saqlanadi, ya'ni «bu obyekt qachon ta'mirtalab deb qayd etilgan
 * edi» degan tarix yo'qolmaydi.
 *
 * Manbadagi `ПАСПОРТ` varag'i shu jadvaldan JONLI hisoblanadi.
 */
class RepairNeed extends QurilishModel
{
    use SoftDeletes;

    protected $table = 'repair_needs';

    protected $guarded = [];

    /** @var array<int, string> */
    public const STATUSES = ['yigilgan', 'korib_chiqilmoqda', 'dasturga_kiritildi', 'rad_etildi'];

    protected $casts = [
        'estimated_amount' => 'decimal:3',
        'target_year' => 'integer',
        'funding_source_known' => 'boolean',
        'priority' => 'integer',
    ];

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'department_org_id');
    }

    public function promotedObject(): BelongsTo
    {
        return $this->belongsTo(ConstructionObject::class, 'promoted_object_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(RepairNeedFile::class, 'repair_need_id');
    }
}
