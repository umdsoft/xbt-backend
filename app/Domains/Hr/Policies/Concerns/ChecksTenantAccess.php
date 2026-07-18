<?php

declare(strict_types=1);

namespace App\Domains\Hr\Policies\Concerns;

use App\Domains\Hr\Models\HrProfile;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant darajasidagi access tekshiruvi (HR domeni).
 * Policy klasslarda takrorlanuvchi `canAccessTenant` logikasini birlashtiradi.
 */
trait ChecksTenantAccess
{
    /**
     * Cross-tenant rollar (super-admin, viloyat-admin) barcha tenantlarni ko'radi.
     * Boshqalar — faqat o'z hokimligi.
     */
    protected function sameTenant(HrProfile $user, Model $model): bool
    {
        if ($user->hasRole('super-admin') || $user->hasRole('viloyat-admin')) {
            return true;
        }

        $column = defined($model::class.'::TENANT_COLUMN')
            ? $model::TENANT_COLUMN
            : 'hokimlik_id';

        return $model->{$column} !== null && $model->{$column} === $user->hokimlik_id;
    }

    protected function isCrossTenantAdmin(HrProfile $user): bool
    {
        return $user->hasRole('super-admin') || $user->hasRole('viloyat-admin');
    }
}
