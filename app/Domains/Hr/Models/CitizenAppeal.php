<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use App\Domains\Hr\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class CitizenAppeal extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use LogsActivity;
    use SoftDeletes;

    public const TENANT_COLUMN = 'hokimlik_id';

    protected $connection = 'hr';

    protected $fillable = [
        'uuid', 'hokimlik_id', 'mahalla_id', 'youth_meeting_id',
        'applicant_name', 'applicant_phone', 'applicant_jshshir',
        'applicant_birth_date', 'applicant_address',
        'body', 'voice_path', 'photo_paths', 'amount',
        'category_id', 'sub_category_id', 'priority', 'status', 'source',
        'ai_confidence', 'duplicate_of_id',
        'submitted_at', 'first_response_at', 'completed_at', 'sla_due_at',
        'created_by',
    ];

    /**
     * Maxfiy ustunlar — serializatsiyada (API payload) YASHIRIN.
     * `encrypted` cast ochiq matnni qaytargani uchun, $hidden bo'lmasa
     * fuqaro milliy IDsi (JSHSHIR) API javobida plaintext ketardi (PII sizishi).
     * Employee modeli bilan bir xil namuna. Bitta yozuvni ko'rsatish kerak
     * bo'lsa (masalan detail forma) — kontrollerda makeVisible() ishlatiladi.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'applicant_jshshir',
    ];

    protected function casts(): array
    {
        return [
            'photo_paths' => 'array',
            'amount' => 'decimal:2',
            'applicant_jshshir' => 'encrypted',
            'applicant_birth_date' => 'date',
            'submitted_at' => 'datetime',
            'first_response_at' => 'datetime',
            'completed_at' => 'datetime',
            'sla_due_at' => 'datetime',
            'ai_confidence' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CitizenAppeal $a) {
            $a->uuid ??= (string) Str::uuid();
            $a->submitted_at ??= now();
        });
    }

    // ===== Munosabatlar =====

    public function mahalla(): BelongsTo
    {
        return $this->belongsTo(Mahalla::class);
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(YouthMeeting::class, 'youth_meeting_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AppealCategory::class, 'category_id');
    }

    public function subCategory(): BelongsTo
    {
        return $this->belongsTo(AppealCategory::class, 'sub_category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(HrProfile::class, 'created_by');
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AppealAssignment::class, 'appeal_id')->orderByDesc('assigned_at');
    }

    public function activeAssignment(): HasOne
    {
        return $this->hasOne(AppealAssignment::class, 'appeal_id')->where('status', 'active')->latestOfMany();
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(CouncilDecision::class, 'appeal_id')->orderByDesc('decided_at');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AppealDocument::class, 'appeal_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(AppealComment::class, 'appeal_id')->latest();
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(AppealStatusHistory::class, 'appeal_id')->latest('changed_at');
    }

    public function aiClassifications(): HasMany
    {
        return $this->hasMany(AiClassification::class, 'appeal_id')->latest();
    }

    public function aiDrafts(): HasMany
    {
        return $this->hasMany(AiDraft::class, 'appeal_id')->latest();
    }

    public function feedback(): HasOne
    {
        return $this->hasOne(CitizenFeedback::class, 'appeal_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'priority', 'category_id', 'sub_category_id'])
            ->logOnlyDirty();
    }
}
