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

    /**
     * XNP TZ v2.0 (2.3) — 7 holatli moderatsiya sikli + `talab_etilmaydi`.
     *
     * `talab_etilmaydi` TZ'da yo'q, lekin manbadagi 517 obyektda kompleks
     * ekspertiza talab etilmaydi — bu haqiqiy ma'lumot, tashlab bo'lmaydi.
     *
     * @var array<int, string>
     */
    public const STATUSES = [
        'kutilmoqda',              // oldingi bosqich yopilmagan
        'ochilgan',                // boshlash mumkin, hech narsa kiritilmagan
        'qoralama',                // kiritilmoqda, hali yuborilmagan
        'tasdiqlash_kutilmoqda',   // yuborildi, moderator javobi kutilmoqda
        'korib_chiqilmoqda',       // moderator ochib ko'rmoqda
        'tasdiqlangan',            // YOPIQ — keyingi bosqich ochiladi
        'rad_etilgan',             // sabab bilan qaytarildi
        'talab_etilmaydi',         // bu obyektga bu bosqich kerak emas
    ];

    /** Bosqich YOPIQ hisoblanadigan holatlar — keyingisini ochadi. */
    public const DONE_STATUSES = ['tasdiqlangan', 'talab_etilmaydi'];

    /** Moderator ko'rib chiqishi kutilayotgan holatlar (tasdiqlash navbati). */
    public const PENDING_STATUSES = ['tasdiqlash_kutilmoqda', 'korib_chiqilmoqda'];

    /** Buyurtmachi tahrirlashi mumkin bo'lgan holatlar. */
    public const EDITABLE_STATUSES = ['ochilgan', 'qoralama', 'rad_etilgan'];

    /**
     * Bizning 8 mayda bosqich -> TZ v2.0 rasmiy 6 bosqichi.
     * Mayda bosqichlar manbadagi Excel ustunlaridan; TZ guruhi rasmiy timeline uchun.
     *
     * @var array<string, int>
     */
    public const TZ_STAGE = [
        'designer_selection' => 2,
        'design_estimate' => 3,
        'urban_planning' => 3,
        'complex_expertise' => 3,
        'tender' => 4,
        'contract' => 4,
        'execution' => 5,
        'handover' => 6,
    ];

    /** @var array<int, string> TZ rasmiy bosqich nomlari (kirill). */
    public const TZ_STAGE_NAMES = [
        1 => 'Объект яратиш',
        2 => 'Лойиҳачи тендери',
        3 => 'Лойиҳа ҳужжатлари ва экспертиза',
        4 => 'Пудратчи тендери ва шартнома',
        5 => 'Кундалик бажарилиш',
        6 => 'Фойдаланишга қабул',
    ];

    protected $casts = [
        'started_at' => 'date',
        'completed_at' => 'date',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'malumot' => 'array',
        'tz_stage' => 'integer',
    ];

    public function object(): BelongsTo
    {
        return $this->belongsTo(ConstructionObject::class, 'object_id');
    }
}
