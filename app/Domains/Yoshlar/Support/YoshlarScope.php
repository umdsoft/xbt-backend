<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Support;

use App\Domains\Yoshlar\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ko'rish doirasi — IKKI O'LCHOV:
 *   GEO (district) — reyestr uchun: yosh mahallaga tegishli, sektorga emas.
 *   ORG (subtree)  — topshiriq/case uchun (F2+).
 *
 * FAIL-CLOSED: faol `staff` yozuvi topilmasa BO'SH massiv qaytadi (`[]`),
 * `null` EMAS. `null` — «cheklovsiz» degani; noto'g'ri sozlangan hisob
 * hammasini ko'rib qolmasligi uchun farq ataylab qilingan.
 */
class YoshlarScope
{
    public function __construct(private readonly YoshlarAccess $access) {}

    /** @return array<int, string>|null null = butun viloyat */
    public function districtIds(User $user): ?array
    {
        $role = $this->access->roleFor($user);

        if (in_array($role, ['yoshlar_admin', 'yoshlar_hokim_orinbosari', 'yoshlar_boshqarma', 'sektor_boshqarma'], true)) {
            return null;
        }

        $districtId = $this->access->staffFor($user)?->organization?->district_id;

        return $districtId === null ? [] : [$districtId];
    }

    /** @return array<int, string>|null null = barcha tashkilot */
    public function orgIds(User $user): ?array
    {
        $role = $this->access->roleFor($user);

        if (in_array($role, ['yoshlar_admin', 'yoshlar_hokim_orinbosari', 'yoshlar_boshqarma'], true)) {
            return null;
        }

        $staff = $this->access->staffFor($user);
        if ($staff === null) {
            return [];
        }

        $ids = [$staff->org_id];

        if ($role === 'sektor_boshqarma') {
            $ids = array_merge($ids, Organization::query()
                ->where('parent_id', $staff->org_id)
                ->pluck('id')->all());
        }

        return $ids;
    }

    /**
     * Reyestr so'roviga geo doirani qo'llaydi.
     *
     * @param  Builder<\App\Domains\Yoshlar\Models\Youth>  $query
     * @return Builder<\App\Domains\Yoshlar\Models\Youth>
     */
    public function applyYouth(Builder $query, User $user): Builder
    {
        $districts = $this->districtIds($user);

        if ($districts === null) {
            return $query;
        }

        if ($districts === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('district_id', $districts);
    }

    /**
     * Topshiriq so'roviga doira qo'llaydi.
     *
     * Reyestrdan FARQI: topshiriq tashkilotga biriktiriladi, shuning uchun
     * asosiy o'lchov — ORG. Istisno `yoshlar_bolim`: u zanjirda qatnashmaydi,
     * lekin o'z TUMANIDAGI ijro holatini ko'radi (nazorat uchun) — unga geo
     * o'lchov qo'llanadi.
     *
     * @param  Builder<\App\Domains\Yoshlar\Models\Task>  $query
     * @return Builder<\App\Domains\Yoshlar\Models\Task>
     */
    public function applyTask(Builder $query, User $user): Builder
    {
        if ($this->access->roleFor($user) === 'yoshlar_bolim') {
            $districts = $this->districtIds($user);

            if ($districts === []) {
                return $query->whereRaw('1 = 0');
            }

            return $districts === null ? $query : $query->whereIn('district_id', $districts);
        }

        $orgIds = $this->orgIds($user);

        if ($orgIds === null) {
            return $query;
        }

        if ($orgIds === []) {
            return $query->whereRaw('1 = 0');
        }

        // BOSH IJROCHI YOKI HAMKOR.
        //
        // Ilgari faqat `assigned_org_id` tekshirilardi va hujjatda masʼul
        // deb koʻrsatilgan ikkinchi tashkilot topshiriqni umuman
        // koʻrmasdi — u faqat matn ichida qolardi.
        return $query->where(function (Builder $q) use ($orgIds) {
            $q->whereIn('assigned_org_id', $orgIds)
                ->orWhereExists(function ($sub) use ($orgIds) {
                    $sub->selectRaw('1')
                        ->from('yoshlar.task_co_executors as ce')
                        ->whereColumn('ce.task_id', 'tasks.id')
                        ->whereIn('ce.org_id', $orgIds);
                });
        });
    }

    /** Bitta yozuv tekshiruvi: shu tumanga tegishli amal qila oladimi. */
    public function canTouchDistrict(User $user, ?string $districtId): bool
    {
        $districts = $this->districtIds($user);

        if ($districts === null) {
            return true;
        }

        return $districtId !== null && in_array($districtId, $districts, true);
    }
}
