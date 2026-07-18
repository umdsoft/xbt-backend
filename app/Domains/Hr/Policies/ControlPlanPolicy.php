<?php

declare(strict_types=1);

namespace App\Domains\Hr\Policies;

use App\Domains\Hr\Models\ControlPlan;
use App\Domains\Hr\Models\HrProfile;
use App\Domains\Hr\Policies\Concerns\ChecksTenantAccess;

class ControlPlanPolicy
{
    use ChecksTenantAccess;

    public function viewAny(HrProfile $user): bool
    {
        return $user->hasPermissionTo('tadbirlar.view');
    }

    public function view(HrProfile $user, ControlPlan $plan): bool
    {
        return $user->hasPermissionTo('tadbirlar.view') && $this->sameTenant($user, $plan);
    }

    public function create(HrProfile $user): bool
    {
        return $user->hasPermissionTo('tadbirlar.create');
    }

    public function update(HrProfile $user, ControlPlan $plan): bool
    {
        if (! $user->hasPermissionTo('tadbirlar.update') || ! $this->sameTenant($user, $plan)) {
            return false;
        }

        return $plan->created_by === $user->id
            || $this->isCrossTenantAdmin($user)
            || $user->hasRole('tuman-admin');
    }

    public function delete(HrProfile $user, ControlPlan $plan): bool
    {
        if (! $user->hasPermissionTo('tadbirlar.delete') || ! $this->sameTenant($user, $plan)) {
            return false;
        }

        return $plan->created_by === $user->id
            || $this->isCrossTenantAdmin($user)
            || $user->hasRole('tuman-admin');
    }
}
