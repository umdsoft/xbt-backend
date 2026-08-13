<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Qurilish/ta'mirlash obyekti — domen yadrosi.
 *
 * Nom `Object` EMAS: `object` PHP'da zaxiralangan so'z. Jadval — `objects`.
 *
 * `lifecycle='qoralama'` — boshqarma kiritgan, hali dasturga kirmagan
 * (kelajakdagi) obyekt: `program_id` null bo'lishi mumkin, dashboardga tushmaydi,
 * bosqich o'zgartirish taqiqlanadi. Dasturga kiritilganda `lifecycle='reja'`
 * bo'ladi — bitta jadval, ikki hayot bosqichi.
 */
class ConstructionObject extends QurilishModel
{
    use SoftDeletes;

    protected $table = 'objects';

    protected $guarded = [];

    /** 8 bosqich — TARTIB MUHIM: holat mashinasi shu ketma-ketlikka tayanadi. */
    public const STAGES = [
        'designer_selection',
        'design_estimate',
        'urban_planning',
        'complex_expertise',
        'tender',
        'contract',
        'execution',
        'handover',
    ];

    /** @var array<int, string> */
    public const LIFECYCLES = ['qoralama', 'reja', 'jarayonda', 'tugallangan', 'toxtatilgan'];

    /** @var array<int, string> */
    public const WORK_TYPES = [
        'yangi_qurish',
        'rekonstruksiya',
        'mukammal_tamirlash',
        'kapital_tamirlash',
        'joriy_tamirlash',
    ];

    protected $casts = [
        'limit_amount' => 'decimal:3',
        'tender_amount' => 'decimal:3',
        'contract_amount' => 'decimal:3',
        'disbursed_amount' => 'decimal:3',
        'financed_amount' => 'decimal:3',
        'deadline_date' => 'date',
        'deadline_year' => 'integer',
        'is_carryover' => 'boolean',
        'handover_planned' => 'boolean',
        'handover_done' => 'boolean',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'customer_org_id');
    }

    public function designer(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'designer_org_id');
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'contractor_org_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'department_org_id');
    }

    /**
     * Bosqichlar HAR DOIM biznes tartibida qaytadi, jadval tartibida emas.
     *
     * Tartib relationga biriktirilgan — chaqiruvchi uni qayta saralashi
     * shart emas. Aks holda SPA dagi «bosqichlar zanjiri» ba'zan aralash
     * chiziladi (PostgreSQL ORDER BY siz tartibni kafolatlamaydi) va bu
     * xato faqat ma'lumot yangilangandan keyin ko'rinadi.
     *
     * `STAGES` — kod ichidagi doimiy, snake_case identifikatorlar; SQL ga
     * qo'shilishi xavfsiz.
     */
    public function stages(): HasMany
    {
        $order = "'{".implode(',', self::STAGES)."}'::text[]";

        return $this->hasMany(ObjectStage::class, 'object_id')
            ->orderByRaw("array_position({$order}, stage_code)");
    }

    public function monthlyPlan(): HasMany
    {
        return $this->hasMany(ObjectMonthlyPlan::class, 'object_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ObjectDocument::class, 'object_id');
    }

    /**
     * Muddat buzilganmi — ustun sifatida SAQLANMAYDI, jonli hisoblanadi.
     *
     * Manbadagi `AR` («муддат бузилган объект») bayrog'i qo'lda qo'yilgan va
     * eskirib qoladi; sana bo'yicha hisoblash har doim haqiqatni ko'rsatadi.
     */
    public function getIsOverdueAttribute(): bool
    {
        return $this->deadline_date !== null
            && ! $this->handover_done
            && $this->deadline_date->isBefore(now()->startOfDay());
    }
}
