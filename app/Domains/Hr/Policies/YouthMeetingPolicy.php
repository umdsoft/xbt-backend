<?php

declare(strict_types=1);

namespace App\Domains\Hr\Policies;

use App\Domains\Hr\Models\HrProfile;
use App\Domains\Hr\Models\YouthMeeting;
use App\Domains\Hr\Policies\Concerns\ChecksTenantAccess;

class YouthMeetingPolicy
{
    use ChecksTenantAccess;

    public function viewAny(HrProfile $user): bool
    {
        return $user->hasPermissionTo('meetings.view');
    }

    public function view(HrProfile $user, YouthMeeting $meeting): bool
    {
        return $user->hasPermissionTo('meetings.view') && $this->sameTenant($user, $meeting);
    }

    public function create(HrProfile $user): bool
    {
        return $user->hasPermissionTo('meetings.create');
    }

    public function update(HrProfile $user, YouthMeeting $meeting): bool
    {
        return $user->hasPermissionTo('meetings.update') && $this->sameTenant($user, $meeting);
    }

    public function delete(HrProfile $user, YouthMeeting $meeting): bool
    {
        return $user->hasPermissionTo('meetings.delete') && $this->sameTenant($user, $meeting);
    }
}
