<?php

declare(strict_types=1);

namespace App\Domains\Hr\Policies;

use App\Domains\Hr\Models\HrProfile;
use App\Domains\Hr\Policies\Concerns\ChecksTenantAccess;
use Illuminate\Database\Eloquent\Model;

/**
 * "Yetakchi" resurslari (hokim yordamchisi / yoshlar yetakchisi) uchun umumiy policy.
 *
 * Ikkala policy faqat ruxsat prefiksi bilan farq qilardi (DRY) — subklass
 * `prefix()` beradi (masalan 'hokim-yordamchilari' yoki 'yoshlar').
 */
abstract class LeaderPolicy
{
    use ChecksTenantAccess;

    /** Ruxsat prefiksi (`{prefix}.view/create/update/delete`). */
    abstract protected function prefix(): string;

    public function viewAny(HrProfile $user): bool
    {
        return $user->hasPermissionTo($this->prefix().'.view');
    }

    public function view(HrProfile $user, Model $model): bool
    {
        return $user->hasPermissionTo($this->prefix().'.view') && $this->sameTenant($user, $model);
    }

    public function create(HrProfile $user): bool
    {
        return $user->hasPermissionTo($this->prefix().'.create');
    }

    public function update(HrProfile $user, Model $model): bool
    {
        return $user->hasPermissionTo($this->prefix().'.update') && $this->sameTenant($user, $model);
    }

    public function delete(HrProfile $user, Model $model): bool
    {
        return $user->hasPermissionTo($this->prefix().'.delete') && $this->sameTenant($user, $model);
    }
}
