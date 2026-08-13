<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Soha — obyekt tarmoq turi (umumta'lim maktabi, ichki yo'l, irrigatsiya...).
 *
 * Boshqarma AYNAN shu orqali biriktiriladi: manba xlsx'da boshqarma ustuni yo'q,
 * shuning uchun `default_department_org_id` import vaqtida obyektga ko'chiriladi.
 */
class Sector extends QurilishModel
{
    protected $table = 'sectors';

    protected $guarded = [];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function defaultDepartment(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'default_department_org_id');
    }

    public function objects(): HasMany
    {
        return $this->hasMany(ConstructionObject::class);
    }
}
