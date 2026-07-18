<?php

declare(strict_types=1);

namespace App\Domains\Hr\Support;

use App\Domains\Hr\Models\HrProfile;
use App\Domains\Hr\Support\Tenant\TenantContext;
use App\Models\User;

/**
 * HR domeni RBAC/tenant kirish nuqtasi — markaziy identifikatsiyani (auth.users)
 * HR profiliga (public.users, spatie rollari) bog'laydi.
 *
 * Request davomida bitta instance (scoped). Middleware `actor`ni va tenant
 * kontekstini o'rnatadi; controllerlar shu yerdan o'qiydi.
 */
class HrAccess
{
    private ?HrProfile $actor = null;

    private bool $resolved = false;

    public function __construct(private readonly TenantContext $context)
    {
    }

    /** Markaziy foydalanuvchidan HR profilini aniqlab, kontekstga yozadi. */
    public function resolveFor(User $user): ?HrProfile
    {
        $this->actor = HrProfile::query()->find($user->getKey());
        $this->resolved = true;

        return $this->actor;
    }

    /** Joriy HR aktyori (profil). Aniqlanmagan bo'lsa — null. */
    public function actorOrNull(): ?HrProfile
    {
        if (! $this->resolved) {
            $user = auth()->user();
            if ($user instanceof User) {
                return $this->resolveFor($user);
            }
        }

        return $this->actor;
    }

    /** Joriy HR aktyori — majburiy (bo'lmasa 403). */
    public function actor(): HrProfile
    {
        $actor = $this->actorOrNull();

        if ($actor === null) {
            abort(403, 'HR профили топилмади.');
        }

        return $actor;
    }

    public function context(): TenantContext
    {
        return $this->context;
    }

    /** Ruxsat tekshiruvi (super-admin — Gate::before orqali bypass). */
    public function can(string $permission): bool
    {
        return $this->actor()->can($permission);
    }
}
