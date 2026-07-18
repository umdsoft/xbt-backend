<?php

declare(strict_types=1);

namespace App\Domains\Hr\Services\Auth;

use App\Domains\Hr\Models\HrProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * KBT User <-> markaziy `auth.users` sinxronizatsiyasi (bir xil id).
 * Faqat PostgreSQL (dev/prod) muhitida ishlaydi; SQLite testlarda inert.
 * Identifikatsiya (login/parol/is_active) markazda; KBT ma'lumoti KBT'da.
 */
class CentralIdentitySync
{
    public const SYSTEM_CODE = 'xbt';

    public function enabled(): bool
    {
        return config('database.default') === 'pgsql';
    }

    /**
     * KBT userni auth.users'ga surish + xbt tizimiga ruxsat.
     */
    public function push(HrProfile $user): void
    {
        if (! $this->enabled()) {
            return;
        }

        $auth = DB::connection('auth');

        $auth->table('users')->updateOrInsert(
            ['id' => $user->id],
            [
                'login' => $user->login,
                'password' => $user->password,
                'name' => $user->name,
                'is_active' => $user->deleted_at === null,
                'deleted_at' => $user->deleted_at,
                'created_at' => $user->created_at ?? now(),
                'updated_at' => now(),
            ],
        );

        $this->ensureAccess($user, self::SYSTEM_CODE);
    }

    /**
     * Userni markaziy identifikatsiyadan butunlay o'chirish (force delete).
     */
    public function remove(HrProfile $user): void
    {
        if (! $this->enabled()) {
            return;
        }

        $auth = DB::connection('auth');
        $auth->table('user_system_access')->where('user_id', $user->id)->delete();
        $auth->table('users')->where('id', $user->id)->delete();
    }

    /**
     * Userga tizimga ruxsat (mavjud bo'lsa faqat rol/holatni yangilaydi — id qayta yaratilmaydi).
     */
    public function ensureAccess(HrProfile $user, string $systemCode): void
    {
        if (! $this->enabled()) {
            return;
        }

        $auth = DB::connection('auth');
        $systemId = $auth->table('systems')->where('code', $systemCode)->value('id');
        if ($systemId === null) {
            return;
        }

        $role = $user->getRoleNames()->first();
        $existing = $auth->table('user_system_access')
            ->where('user_id', $user->id)
            ->where('system_id', $systemId)
            ->first();

        if ($existing === null) {
            $auth->table('user_system_access')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'system_id' => $systemId,
                'role' => $role,
                'is_active' => $user->deleted_at === null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $auth->table('user_system_access')->where('id', $existing->id)->update([
                'role' => $role ?? $existing->role,
                'is_active' => $user->deleted_at === null,
                'updated_at' => now(),
            ]);
        }
    }
}
