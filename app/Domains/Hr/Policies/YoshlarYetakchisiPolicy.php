<?php

declare(strict_types=1);

namespace App\Domains\Hr\Policies;

use App\Domains\Hr\Models\HrProfile;
use App\Domains\Hr\Models\YoshlarYetakchisi;
use App\Domains\Hr\Policies\Concerns\ChecksTenantAccess;

class YoshlarYetakchisiPolicy
{
    use ChecksTenantAccess;

    public function viewAny(HrProfile $user): bool
    {
        return $user->hasPermissionTo('yoshlar.view');
    }

    public function view(HrProfile $user, YoshlarYetakchisi $yy): bool
    {
        return $user->hasPermissionTo('yoshlar.view') && $this->sameTenant($user, $yy);
    }

    public function create(HrProfile $user): bool
    {
        return $user->hasPermissionTo('yoshlar.create');
    }

    public function update(HrProfile $user, YoshlarYetakchisi $yy): bool
    {
        return $user->hasPermissionTo('yoshlar.update') && $this->sameTenant($user, $yy);
    }

    public function delete(HrProfile $user, YoshlarYetakchisi $yy): bool
    {
        return $user->hasPermissionTo('yoshlar.delete') && $this->sameTenant($user, $yy);
    }
}
