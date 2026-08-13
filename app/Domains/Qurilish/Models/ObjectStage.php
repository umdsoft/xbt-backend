<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Obyekt bosqichi — 8 qator/obyekt, holat mashinasi qatlami.
 *
 * Manba xlsx bu ma'lumotni 40 ta bayroq-ustunga yoygan; bu yerda u
 * (bosqich, holat) juftligiga normallashtiriladi — yangi bosqich qo'shish
 * sxema o'zgarishini talab qilmaydi.
 */
class ObjectStage extends QurilishModel
{
    protected $table = 'object_stages';

    protected $guarded = [];

    /** @var array<int, string> */
    public const STATUSES = [
        'talab_etilmaydi',
        'boshlanmagan',
        'jarayonda',
        'yakunlangan',
        'etiroz_bilan_qaytarilgan',
    ];

    /** Bosqich yakunlangan hisoblanadigan holatlar (voronka uchun). */
    public const DONE_STATUSES = ['yakunlangan', 'talab_etilmaydi'];

    protected $casts = [
        'started_at' => 'date',
        'completed_at' => 'date',
    ];

    public function object(): BelongsTo
    {
        return $this->belongsTo(ConstructionObject::class, 'object_id');
    }
}
