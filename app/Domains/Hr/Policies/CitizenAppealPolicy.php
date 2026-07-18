<?php

declare(strict_types=1);

namespace App\Domains\Hr\Policies;

use App\Domains\Hr\Models\CitizenAppeal;
use App\Domains\Hr\Models\CouncilMember;
use App\Domains\Hr\Models\HrProfile;
use App\Domains\Hr\Policies\Concerns\ChecksTenantAccess;

class CitizenAppealPolicy
{
    use ChecksTenantAccess;

    public function viewAny(HrProfile $user): bool
    {
        return $user->hasPermissionTo('appeals.view');
    }

    public function view(HrProfile $user, CitizenAppeal $appeal): bool
    {
        if (! $user->hasPermissionTo('appeals.view') || ! $this->sameTenant($user, $appeal)) {
            return false;
        }

        // Tenant ichida — hamma murojaatni ko'rmaydi
        if ($user->hasPermissionTo('appeals.view-all') || $this->isCrossTenantAdmin($user)) {
            return true;
        }

        // Mahalla yettiligi a'zosi — faqat o'z mahallasi
        if ($user->hasRole('mahalla-yettiligi')) {
            // user.department_id = mahalla bog'liq emas — boshqacha tekshiruv
            // Hozircha: agar appeal mahalla'ga assigned bo'lsa va user shu council'da bo'lsa
            return $this->isCouncilMember($user, $appeal);
        }

        return true; // tenant ichida boshqa rollar — ko'radi
    }

    public function create(HrProfile $user): bool
    {
        return $user->hasPermissionTo('appeals.create');
    }

    public function update(HrProfile $user, CitizenAppeal $appeal): bool
    {
        return $user->hasPermissionTo('appeals.update') && $this->sameTenant($user, $appeal);
    }

    public function delete(HrProfile $user, CitizenAppeal $appeal): bool
    {
        return $user->hasPermissionTo('appeals.delete') && $this->sameTenant($user, $appeal);
    }

    public function assign(HrProfile $user, CitizenAppeal $appeal): bool
    {
        return $user->hasPermissionTo('appeals.assign') && $this->sameTenant($user, $appeal);
    }

    public function decide(HrProfile $user, CitizenAppeal $appeal): bool
    {
        if (! $user->hasPermissionTo('appeals.decide') || ! $this->sameTenant($user, $appeal)) {
            return false;
        }

        // Faqat appeal'ga biriktirilgan yettilik a'zolari qaror chiqaradi
        if ($this->isCrossTenantAdmin($user) || $user->hasRole('tuman-admin')) {
            return true;
        }

        return $this->isCouncilMember($user, $appeal);
    }

    private function isCouncilMember(HrProfile $user, CitizenAppeal $appeal): bool
    {
        // Active assignment'ga biriktirilgan council'ning a'zosi
        $assignment = $appeal->activeAssignment;
        if (! $assignment || $assignment->assignee_type !== 'council') {
            return false;
        }

        return CouncilMember::query()
            ->where('council_id', $assignment->assignee_id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->exists();
    }
}
