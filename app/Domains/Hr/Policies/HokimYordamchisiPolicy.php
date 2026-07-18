<?php

declare(strict_types=1);

namespace App\Domains\Hr\Policies;

use App\Domains\Hr\Models\HokimYordamchisi;
use App\Domains\Hr\Models\HrProfile;
use App\Domains\Hr\Policies\Concerns\ChecksTenantAccess;

class HokimYordamchisiPolicy
{
    use ChecksTenantAccess;

    public function viewAny(HrProfile $user): bool
    {
        return $user->hasPermissionTo('hokim-yordamchilari.view');
    }

    public function view(HrProfile $user, HokimYordamchisi $hy): bool
    {
        return $user->hasPermissionTo('hokim-yordamchilari.view') && $this->sameTenant($user, $hy);
    }

    public function create(HrProfile $user): bool
    {
        return $user->hasPermissionTo('hokim-yordamchilari.create');
    }

    public function update(HrProfile $user, HokimYordamchisi $hy): bool
    {
        return $user->hasPermissionTo('hokim-yordamchilari.update') && $this->sameTenant($user, $hy);
    }

    public function delete(HrProfile $user, HokimYordamchisi $hy): bool
    {
        return $user->hasPermissionTo('hokim-yordamchilari.delete') && $this->sameTenant($user, $hy);
    }
}
