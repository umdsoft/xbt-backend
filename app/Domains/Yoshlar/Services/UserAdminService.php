<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Services;

use App\Domains\Yoshlar\Models\Organization;
use App\Domains\Yoshlar\Models\Staff;
use App\Domains\Yoshlar\Support\YoshlarAccess;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Hisob ochish. Ikkita yozuv bog'lanishi kerak: `yoshlar.staff` (doira)
 * va `auth.user_system_access` (kirish huquqi).
 *
 * TARTIB MUHIM: avval staff, keyin kirish huquqi. Teskarisi bo'lsa, profilsiz-u
 * kira oladigan hisob qolib ketardi va u fail-closed tuzoqqa tushib «hech narsa
 * ko'rmaydigan» foydalanuvchiga aylanardi.
 */
class UserAdminService
{
    /** @return array{user_id: string, password: string} */
    public function create(
        string $login,
        string $name,
        string $role,
        ?string $orgId,
        ?string $position,
        bool $canPatronage,
    ): array {
        $login = trim($login);
        $name = trim($name);

        if ($login === '' || $name === '') {
            throw ValidationException::withMessages(['login' => 'Login va ism bo‘sh bo‘lishi mumkin emas.']);
        }

        if (! in_array($role, YoshlarAccess::ROLES, true)) {
            throw ValidationException::withMessages(['role' => "«{$role}» — noto‘g‘ri rol."]);
        }

        // `withTrashed()` — users.login UNIQUE cheklovi soft-delete qatorlarni ham
        // hisobga oladi; tekshirmasak INSERT tushunarsiz xato bilan qulaydi.
        if (User::withTrashed()->where('login', $login)->exists()) {
            throw ValidationException::withMessages(['login' => "«{$login}» logini allaqachon band."]);
        }

        $org = $this->resolveOrganization($role, $orgId);

        $systemId = DB::connection('auth')->table('systems')
            ->where('code', YoshlarAccess::SYSTEM_CODE)->value('id');

        if ($systemId === null) {
            throw ValidationException::withMessages([
                'role' => '«yoshlar» tizimi auth.systems jadvalida topilmadi (SystemsSeeder ishga tushirilmagan).',
            ]);
        }

        $password = Str::password(20);

        $user = User::query()->create([
            'login' => $login,
            'name' => $name,
            'password' => bcrypt($password),
            'is_active' => true,
        ]);

        if ($org !== null) {
            Staff::query()->create([
                'user_id' => $user->id,
                'org_id' => $org->id,
                'position' => $position,
                'can_patronage' => $canPatronage,
                'is_active' => true,
            ]);
        }

        DB::connection('auth')->table('user_system_access')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'system_id' => $systemId,
            'role' => $role,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['user_id' => $user->id, 'password' => $password];
    }

    public function setActive(User $user, bool $active): void
    {
        $user->update(['is_active' => $active]);

        DB::connection('auth')->table('user_system_access')
            ->where('user_id', $user->id)->update(['is_active' => $active, 'updated_at' => now()]);

        Staff::query()->where('user_id', $user->id)->update(['is_active' => $active]);
    }

    private function resolveOrganization(string $role, ?string $orgId): ?Organization
    {
        if ($orgId === null || $orgId === '') {
            if (! in_array($role, YoshlarAccess::ORG_OPTIONAL_ROLES, true)) {
                throw ValidationException::withMessages([
                    'org_id' => "«{$role}» roli uchun tashkilot majburiy: usiz foydalanuvchi hech narsa ko‘rmaydi.",
                ]);
            }

            return null;
        }

        $org = Organization::query()
            ->where(function ($q) use ($orgId) {
                $q->where('name_lat', $orgId)->orWhere('name_cyr', $orgId);
                if (Str::isUuid($orgId)) {
                    $q->orWhere('id', $orgId);
                }
            })
            ->first();

        if ($org === null) {
            throw ValidationException::withMessages(['org_id' => 'Tashkilot topilmadi.']);
        }

        $expected = Organization::ROLE_TYPE[$role] ?? null;

        if ($expected !== null && $org->type !== $expected) {
            throw ValidationException::withMessages([
                'org_id' => "«{$role}» roli «{$expected}» turidagi tashkilotga biriktiriladi, «{$org->type}» ga emas.",
            ]);
        }

        return $org;
    }
}
