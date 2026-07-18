<?php

declare(strict_types=1);

namespace App\Domains\Hr\Policies;

use App\Domains\Hr\Models\HrProfile;
use App\Domains\Hr\Support\Authorization\RoleHierarchy;

/**
 * Foydalanuvchilar bo'limi (System → Users) uchun avtorizatsiya.
 *
 * Uch qatlam:
 *  1. Ruxsat (permission): user.view / user.create / user.update / user.delete
 *  2. Tenant izolyatsiyasi: cross-tenant bo'lmagan admin faqat o'z hokimligi
 *     foydalanuvchilarini boshqaradi
 *  3. Privilegiya iyerarxiyasi: o'zidan yuqori yoki teng darajadagi
 *     foydalanuvchini boshqarib bo'lmaydi (RoleHierarchy)
 *
 * Eslatma: super-admin AppServiceProvider dagi Gate::before orqali barcha
 * tekshiruvlarni chetlab o'tadi.
 */
class UserPolicy
{
    public function viewAny(HrProfile $user): bool
    {
        return $user->hasPermissionTo('user.view');
    }

    public function view(HrProfile $user, HrProfile $target): bool
    {
        return $user->hasPermissionTo('user.view') && $this->canManage($user, $target);
    }

    public function create(HrProfile $user): bool
    {
        return $user->hasPermissionTo('user.create');
    }

    public function update(HrProfile $user, HrProfile $target): bool
    {
        return $user->hasPermissionTo('user.update') && $this->canManage($user, $target);
    }

    public function delete(HrProfile $user, HrProfile $target): bool
    {
        // O'zini o'zi o'chira olmaydi (controllerda ham qo'shimcha tekshiriladi).
        if ($user->id === $target->id) {
            return false;
        }

        return $user->hasPermissionTo('user.delete') && $this->canManage($user, $target);
    }

    /**
     * Cross-tenant (super/viloyat) — barcha tenantlar; boshqalar — o'z hokimligi.
     * Ustiga rol darajasi: faqat o'zidan qat'iy past darajadagini boshqaradi.
     */
    private function canManage(HrProfile $actor, HrProfile $target): bool
    {
        if (! $this->sameTenant($actor, $target)) {
            return false;
        }

        return RoleHierarchy::canManage($actor, $target);
    }

    private function sameTenant(HrProfile $actor, HrProfile $target): bool
    {
        if ($actor->hasRole('super-admin') || $actor->hasRole('viloyat-admin')) {
            return true;
        }

        $actorTenant = $actor->hokimlik_id;

        return $actorTenant !== null && $actorTenant === $target->hokimlik_id;
    }
}
