<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Support;

use App\Domains\Qurilish\Models\ConstructionObject;
use App\Domains\Qurilish\Models\RepairNeed;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Rolga qarab so'rovni cheklaydi (IDOR himoyasi).
 *
 *   hokimlik/prokuratura/admin — cheklovsiz (viloyat).
 *   buyurtmachi — objects.customer_org_id = profil tashkiloti.
 *   boshqarma   — objects.department_org_id = profil tashkiloti.
 *
 * Profil yoki tashkilot yo'q bo'lsa — BO'SH natija (fail-closed), cheklovsiz EMAS.
 * Bu ataylab: noto'g'ri sozlangan hisob hech narsa ko'rmasin, hammasini emas.
 */
class QurilishScope
{
    public function __construct(private readonly QurilishAccess $access) {}

    /**
     * `objects` jadvali ustidagi so'rovga scope qo'llaydi.
     *
     * @param  Builder<ConstructionObject>  $query
     * @return Builder<ConstructionObject>
     */
    public function apply(Builder $query, User $user): Builder
    {
        if ($this->access->seesEverything($user)) {
            return $query;
        }

        $role = $this->access->roleFor($user);
        $orgId = $this->access->profileFor($user)?->organization_id;

        if ($orgId === null) {
            return $query->whereRaw('1 = 0');
        }

        return match ($role) {
            'qurilish_buyurtmachi' => $query->where('customer_org_id', $orgId),
            'qurilish_boshqarma' => $query->where('department_org_id', $orgId),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * Ta'mirtalab reyestri uchun scope — reyestrni faqat boshqarma yuritadi,
     * shuning uchun yagona ustun: `department_org_id`.
     *
     * @param  Builder<RepairNeed>  $query
     * @return Builder<RepairNeed>
     */
    public function applyRepair(Builder $query, User $user): Builder
    {
        if ($this->access->seesEverything($user)) {
            return $query;
        }

        $orgId = $this->access->profileFor($user)?->organization_id;

        return $orgId === null
            ? $query->whereRaw('1 = 0')
            : $query->where('department_org_id', $orgId);
    }

    /** Foydalanuvchi shu obyektni ko'ra oladimi (bitta yozuv tekshiruvi). */
    public function owns(User $user, ?string $customerOrgId = null, ?string $departmentOrgId = null): bool
    {
        if ($this->access->seesEverything($user)) {
            return true;
        }

        $orgId = $this->access->profileFor($user)?->organization_id;
        if ($orgId === null) {
            return false;
        }

        return match ($this->access->roleFor($user)) {
            'qurilish_buyurtmachi' => $customerOrgId === $orgId,
            'qurilish_boshqarma' => $departmentOrgId === $orgId,
            default => false,
        };
    }
}
