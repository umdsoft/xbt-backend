<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bosqich dalili — surat yoki video.
 *
 * Hujjatdan farqi: media versiyalanmaydi va uning qiymati OLINGAN SANASIDA.
 * Kechikib yuklangan surat ham, agar `taken_at` to'g'ri bo'lsa, dalil bo'lib
 * qolaveradi; shu sabab ro'yxat `taken_at` bo'yicha saralanadi.
 */
class ObjectMedia extends QurilishModel
{
    protected $table = 'object_media';

    protected $guarded = [];

    public const KINDS = ['photo', 'video'];

    protected $casts = [
        'taken_at' => 'date',
        'is_cover' => 'boolean',
        'size' => 'integer',
        'duration_sec' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
    ];

    public function object(): BelongsTo
    {
        return $this->belongsTo(ConstructionObject::class, 'object_id');
    }

    public function weeklyReport(): BelongsTo
    {
        return $this->belongsTo(WeeklyReport::class, 'weekly_report_id');
    }
}
