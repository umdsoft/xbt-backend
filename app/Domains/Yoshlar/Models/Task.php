<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Topshiriq — protokol bandi yoki mustaqil ish.
 *
 * MUDDAT HOLATI SAQLANMAYDI: `deadline_state` har so'rovda hisoblanadi.
 * Saqlansa, uni har kuni tunda yangilash kerak bo'lardi va cron ishlamay
 * qolsa, ma'lumot jimgina eskirardi — muddat buzilgani ko'rinmay qolardi.
 */
class Task extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $connection = 'yoshlar';

    protected $table = 'tasks';

    /** @var array<int, string> */
    public const STATUSES = [
        'belgilandi',
        'ijroda',
        'tasdiq_kutilmoqda',
        'tasdiqlandi',
        'qaytarildi',
    ];

    /** @var array<int, string> */
    public const PRIORITIES = ['past', 'orta', 'yuqori'];

    /** Muddatgacha shu kundan kam qolsa — «sariq». */
    public const AMBER_DAYS = 3;

    protected $fillable = [
        'protocol_id', 'title', 'description', 'assigned_org_id', 'district_id',
        'deadline', 'priority', 'status', 'progress', 'created_by',

        // Hujjat bandi maydonlari (migratsiyada nega kerakligi yozilgan).
        'section_title', 'item_number', 'sort_order',
        'mechanism', 'steps', 'deadline_text', 'responsible_text',
        'applicant_youth_id', 'applicant_name',
        'target_value', 'target_unit', 'target_done',
    ];

    /** @var array<int, string> */
    protected $appends = ['deadline_state', 'days_left', 'is_overdue', 'target_percent'];

    protected function casts(): array
    {
        return [
            'deadline' => 'date',
            'progress' => 'integer',
            'steps' => 'array',
            'sort_order' => 'integer',
            'target_value' => 'integer',
            'target_done' => 'integer',
        ];
    }

    /** @return BelongsTo<Protocol, Task> */
    public function protocol(): BelongsTo
    {
        return $this->belongsTo(Protocol::class, 'protocol_id');
    }

    /**
     * Murojaatchi — yoʻl xaritasida taklif kiritgan yosh.
     *
     * @return BelongsTo<Youth, Task>
     */
    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Youth::class, 'applicant_youth_id');
    }

    /**
     * Ekranda koʻrsatiladigan murojaatchi nomi.
     *
     * Reyestrdagi yozuv USTUN turadi: hujjatda ism xato yozilgan boʻlishi
     * mumkin, reyestrdagisi esa tasdiqlangan. Bogʻlanish yoʻq boʻlsa —
     * hujjatdagi matn.
     */
    public function getApplicantLabelAttribute(): ?string
    {
        return $this->applicant?->full_name ?? $this->applicant_name;
    }

    /**
     * Oʻlchanadigan maqsad bajarilishi. Maqsad belgilanmagan boʻlsa `null`
     * — nol EMAS: «maqsad yoʻq» va «maqsad bor, lekin hech nima
     * qilinmagan» ekranda bir xil koʻrinmasligi kerak.
     */
    public function getTargetPercentAttribute(): ?int
    {
        if ($this->target_value === null || $this->target_value <= 0) {
            return null;
        }

        return (int) round(($this->target_done / $this->target_value) * 100);
    }

    /** @return BelongsTo<Organization, Task> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'assigned_org_id');
    }

    /** @return HasMany<TaskUpdate> */
    public function updates(): HasMany
    {
        return $this->hasMany(TaskUpdate::class, 'task_id')->orderByDesc('submitted_at');
    }

    /** Ochiq (tasdiq kutayotgan) yuborish — bittadan ko'p bo'lmaydi. */
    public function openUpdate(): ?TaskUpdate
    {
        return TaskUpdate::query()
            ->where('task_id', $this->id)
            ->whereIn('review_stage', TaskUpdate::OPEN_STAGES)
            ->first();
    }

    public function getDaysLeftAttribute(): int
    {
        if ($this->deadline === null) {
            return 0;
        }

        return (int) CarbonImmutable::today()->diffInDays(
            CarbonImmutable::parse($this->deadline)->startOfDay(),
            false,
        );
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->status !== 'tasdiqlandi' && $this->days_left < 0;
    }

    /**
     * Svetofor: yashil (vaqt bor), sariq (3 kundan kam), qizil (muddati o'tgan),
     * kulrang (tasdiqlangan — muddat endi ahamiyatsiz).
     */
    public function getDeadlineStateAttribute(): string
    {
        if ($this->status === 'tasdiqlandi') {
            return 'done';
        }

        $days = $this->days_left;

        if ($days < 0) {
            return 'red';
        }

        return $days < self::AMBER_DAYS ? 'amber' : 'green';
    }

    /**
     * Muddati o'tgan, lekin hali tasdiqlanmagan topshiriqlar.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', '!=', 'tasdiqlandi')
            ->whereDate('deadline', '<', CarbonImmutable::today()->toDateString());
    }
}
