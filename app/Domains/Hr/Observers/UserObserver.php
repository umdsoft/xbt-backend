<?php

declare(strict_types=1);

namespace App\Domains\Hr\Observers;

use App\Domains\Hr\Models\HrProfile;
use App\Domains\Hr\Services\Auth\CentralIdentitySync;

/**
 * KBT userdagi har o'zgarish markaziy auth.users'ga sinxronlanadi
 * (yaratish, parol/ism o'zgarishi, soft-delete, restore). pgsql'da faol.
 */
class UserObserver
{
    public function __construct(private readonly CentralIdentitySync $sync)
    {
    }

    public function saved(HrProfile $user): void
    {
        $this->sync->push($user);
    }

    public function deleted(HrProfile $user): void
    {
        if ($user->isForceDeleting()) {
            return; // forceDeleted() handler butunlay o'chiradi
        }

        $this->sync->push($user); // soft delete -> is_active=false
    }

    public function forceDeleted(HrProfile $user): void
    {
        $this->sync->remove($user);
    }

    public function restored(HrProfile $user): void
    {
        $this->sync->push($user);
    }
}
