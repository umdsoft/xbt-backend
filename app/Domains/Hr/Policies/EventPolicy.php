<?php

declare(strict_types=1);

namespace App\Domains\Hr\Policies;

use App\Domains\Hr\Models\Event;
use App\Domains\Hr\Models\HrProfile;
use App\Domains\Hr\Policies\Concerns\ChecksTenantAccess;

/**
 * Tadbir (o'rindiq sxemasi) policy'si — ruxsat + tenant (hokimlik).
 * Ko'rish = seating.view; yaratish/o'zgartirish = seating.mark.
 */
class EventPolicy
{
    use ChecksTenantAccess;

    public function viewAny(HrProfile $user): bool
    {
        return $user->hasPermissionTo('seating.view');
    }

    public function view(HrProfile $user, Event $event): bool
    {
        return $user->hasPermissionTo('seating.view') && $this->sameTenant($user, $event);
    }

    public function create(HrProfile $user): bool
    {
        return $user->hasPermissionTo('seating.mark');
    }

    public function update(HrProfile $user, Event $event): bool
    {
        return $user->hasPermissionTo('seating.mark') && $this->sameTenant($user, $event);
    }

    public function delete(HrProfile $user, Event $event): bool
    {
        return $user->hasPermissionTo('seating.mark') && $this->sameTenant($user, $event);
    }
}
