<?php

declare(strict_types=1);

namespace App\Domains\Hr\Policies;

use App\Domains\Hr\Models\MahallaCouncil;
use App\Domains\Hr\Models\HrProfile;
use App\Domains\Hr\Policies\Concerns\ChecksTenantAccess;

class MahallaCouncilPolicy
{
    use ChecksTenantAccess;

    public function viewAny(HrProfile $user): bool
    {
        return $user->hasPermissionTo('councils.view');
    }

    public function view(HrProfile $user, MahallaCouncil $council): bool
    {
        return $user->hasPermissionTo('councils.view') && $this->sameTenant($user, $council);
    }

    public function create(HrProfile $user): bool
    {
        return $user->hasPermissionTo('councils.manage');
    }

    public function update(HrProfile $user, MahallaCouncil $council): bool
    {
        return $user->hasPermissionTo('councils.manage') && $this->sameTenant($user, $council);
    }

    public function delete(HrProfile $user, MahallaCouncil $council): bool
    {
        return $user->hasPermissionTo('councils.manage') && $this->sameTenant($user, $council);
    }
}
