<?php

declare(strict_types=1);

namespace App\Domains\Hr\Policies;

use App\Domains\Hr\Models\Organization;
use App\Domains\Hr\Models\HrProfile;
use App\Domains\Hr\Policies\Concerns\ChecksTenantAccess;

/**
 * Tashkilot ruxsatlari.
 *
 * Kotibyat mudiri faqat O'Z kompleksi yoki o'zi yaratgan tashkilotlarni boshqaradi.
 * Tuman/viloyat-admin va super-admin — tenant ichida keng huquq.
 */
class OrganizationPolicy
{
    use ChecksTenantAccess;

    public function viewAny(HrProfile $user): bool
    {
        return $user->hasPermissionTo('tashkilotlar.view');
    }

    public function view(HrProfile $user, Organization $organization): bool
    {
        return $user->hasPermissionTo('tashkilotlar.view')
            && $this->sameTenant($user, $organization);
    }

    public function create(HrProfile $user): bool
    {
        return $user->hasPermissionTo('tashkilotlar.create');
    }

    public function update(HrProfile $user, Organization $organization): bool
    {
        if (! $user->hasPermissionTo('tashkilotlar.update') || ! $this->sameTenant($user, $organization)) {
            return false;
        }

        return $this->ownsOrManages($user, $organization);
    }

    public function delete(HrProfile $user, Organization $organization): bool
    {
        if (! $user->hasPermissionTo('tashkilotlar.delete') || ! $this->sameTenant($user, $organization)) {
            return false;
        }

        return $this->ownsOrManages($user, $organization);
    }

    /** Tashkilot uchun foydalanuvchi (admin/jamoa) yaratish/boshqarish. */
    public function manageUsers(HrProfile $user, Organization $organization): bool
    {
        if (! $user->hasPermissionTo('tashkilot.manage-users') || ! $this->sameTenant($user, $organization)) {
            return false;
        }

        return $this->ownsOrManages($user, $organization);
    }

    /**
     * Foydalanuvchi tashkilot egasimi (yaratgan), bir kompleksdami yoki tenant-admin'mi.
     */
    private function ownsOrManages(HrProfile $user, Organization $organization): bool
    {
        if ($this->isCrossTenantAdmin($user) || $user->hasRole('tuman-admin')) {
            return true;
        }

        // O'zi yaratgan
        if ($organization->created_by === $user->id) {
            return true;
        }

        // Bir kompleks (kotibyat mudirining department_id = kompleks)
        return $organization->kompleks_id !== null
            && $organization->kompleks_id === $user->department_id;
    }
}
