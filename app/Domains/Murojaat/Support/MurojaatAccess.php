<?php

declare(strict_types=1);

namespace App\Domains\Murojaat\Support;

use App\Domains\Murojaat\Models\MurojaatProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * MurojAAT domeni RBAC — markaziy identifikatsiyadan rol/ruxsat (advisor AdvisorAccess naqshi).
 * Rol = user_system_access.role ('murojaat' tizimi); ruxsat = kod xaritasi.
 *
 * Rollar:
 *   murojaat_viloyat — barcha tuman + boshqaruv (super); `*`.
 *   murojaat_admin   — o'z tumani; ko'rish/import/eksport/boshqaruv (user/parol).
 *   murojaat_xodim   — o'z tumani; ko'rish/import/eksport.
 *   murojaat_viewer  — o'z tumani; faqat ko'rish.
 */
class MurojaatAccess
{
    public const SYSTEM_CODE = 'murojaat';

    /** @var array<int, string> */
    public const ROLES = ['murojaat_viloyat', 'murojaat_admin', 'murojaat_xodim', 'murojaat_viewer'];

    /** @var array<string, array<int, string>> */
    private const PERMISSIONS = [
        'murojaat_viloyat' => ['*'],
        'murojaat_admin' => ['murojaat.view', 'murojaat.import', 'murojaat.export', 'murojaat.manage'],
        'murojaat_xodim' => ['murojaat.view', 'murojaat.import', 'murojaat.export'],
        'murojaat_viewer' => ['murojaat.view'],
    ];

    /** @var array<string, ?string> */
    private array $roleCache = [];

    /** @var array<string, ?MurojaatProfile> */
    private array $profileCache = [];

    public function roleFor(User $user): ?string
    {
        if (! array_key_exists($user->id, $this->roleCache)) {
            $this->roleCache[$user->id] = DB::connection('auth')->table('user_system_access as usa')
                ->join('systems as s', 's.id', '=', 'usa.system_id')
                ->where('usa.user_id', $user->id)
                ->where('usa.is_active', true)
                ->where('s.code', self::SYSTEM_CODE)
                ->value('usa.role');
        }

        return $this->roleCache[$user->id];
    }

    public function isMurojaat(User $user): bool
    {
        return $this->roleFor($user) !== null;
    }

    public function can(User $user, string $permission): bool
    {
        $perms = $this->permissionsFor($user);

        return in_array('*', $perms, true) || in_array($permission, $perms, true);
    }

    /** @return array<int, string> */
    public function permissionsFor(User $user): array
    {
        $role = $this->roleFor($user);

        return $role === null ? [] : (self::PERMISSIONS[$role] ?? []);
    }

    public function profileFor(User $user): ?MurojaatProfile
    {
        if (! array_key_exists($user->id, $this->profileCache)) {
            $this->profileCache[$user->id] = MurojaatProfile::query()->where('user_id', $user->id)->first();
        }

        return $this->profileCache[$user->id];
    }

    public function scopeFor(User $user): MurojaatScope
    {
        $profile = $this->profileFor($user);

        return new MurojaatScope(
            $this->roleFor($user),
            $profile?->district_id,
            $profile?->id,
        );
    }
}
