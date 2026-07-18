<?php

declare(strict_types=1);

namespace App\Domains\Hr\Policies;

use App\Domains\Hr\Models\Employee;
use App\Domains\Hr\Models\HrProfile;
use App\Domains\Hr\Policies\Concerns\ChecksTenantAccess;

class EmployeePolicy
{
    use ChecksTenantAccess;

    public function viewAny(HrProfile $user): bool
    {
        return $user->hasPermissionTo('kadrlar.view');
    }

    public function view(HrProfile $user, Employee $employee): bool
    {
        return $user->hasPermissionTo('kadrlar.view') && $this->sameTenant($user, $employee);
    }

    public function create(HrProfile $user): bool
    {
        return $user->hasPermissionTo('kadrlar.create');
    }

    public function update(HrProfile $user, Employee $employee): bool
    {
        return $user->hasPermissionTo('kadrlar.update') && $this->sameTenant($user, $employee);
    }

    public function delete(HrProfile $user, Employee $employee): bool
    {
        return $user->hasPermissionTo('kadrlar.delete') && $this->sameTenant($user, $employee);
    }

    public function restore(HrProfile $user, Employee $employee): bool
    {
        return $user->hasPermissionTo('kadrlar.delete') && $this->sameTenant($user, $employee);
    }

    public function forceDelete(HrProfile $user, Employee $employee): bool
    {
        return $user->hasPermissionTo('kadrlar.delete') && $this->sameTenant($user, $employee);
    }

    public function export(HrProfile $user): bool
    {
        return $user->hasPermissionTo('kadrlar.export');
    }
}
