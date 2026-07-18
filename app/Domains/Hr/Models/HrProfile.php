<?php

declare(strict_types=1);

namespace App\Domains\Hr\Models;

use App\Domains\Hr\Observers\UserObserver;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

/**
 * KBT foydalanuvchisining HR PROFILI (public.users) — bir xil id markaziy
 * auth.users bilan. Identifikatsiya (login/parol) markazda (App\Models\User);
 * bu model esa HR domeniga xos: spatie rollar/ruxsatlar, bo'lim/lavozim/tashkilot
 * va tenant (hokimlik) hal qilinishini olib boradi.
 *
 * Spatie pivotlari (model_has_roles) `model_type = 'App\Models\User'` bilan
 * saqlangani uchun getMorphClass() shuni qaytaradi (mavjud ma'lumotga mos).
 */
#[ObservedBy(UserObserver::class)]
class HrProfile extends Model implements AuthorizableContract
{
    use Authorizable;
    use HasRoles;
    use HasUuids;
    use LogsActivity;
    use SoftDeletes;

    protected $connection = 'hr';

    protected $table = 'users';

    /** Spatie rollar guard'i — mavjud rollar `web` guard'da. */
    protected string $guard_name = 'web';

    protected $fillable = [
        'name', 'login', 'email', 'password',
        'department_id', 'position_id', 'organization_id',
    ];

    protected $hidden = [
        'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Spatie pivotlari markaziy identifikatsiya morph turida saqlangan.
     */
    public function getMorphClass(): string
    {
        return 'App\Models\User';
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /** Ташкилот фойдаланувчиси (tashkilot-admin / tashkilot-xodimi) учун. */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Foydalanuvchi tegishli bo'lgan tenant (top-level hokimlik) ID si.
     * - Ташкилот фойдаланувчиси → organization.hokimlik_id
     * - Department parent_id IS NULL → o'zi tenant
     * - Department parent_id IS NOT NULL → parent tenant
     */
    public function getHokimlikIdAttribute(): ?string
    {
        if ($this->organization_id !== null) {
            return $this->organization?->hokimlik_id;
        }

        $dept = $this->department;
        if (! $dept) {
            return null;
        }

        return $dept->rootId();
    }

    public function isFromTenant(?string $hokimlikId): bool
    {
        return $hokimlikId !== null && $this->hokimlik_id === $hokimlikId;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'department_id'])
            ->logOnlyDirty();
    }
}
