<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Hr\Models\Department;
use App\Domains\Hr\Models\HrProfile;
use App\Domains\Hr\Models\Position;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Boshlang'ich SUPER-ADMIN — fresh deploy'da tizimga kirishning yagona nuqtasi.
 *
 * Yagona `admin` identifikatsiyasi 3 joyda bir xil UUID bilan bog'lanadi:
 *   1) auth.users                — markaziy identifikatsiya (login/parol)
 *   2) public.users (HrProfile)  — HR profil + spatie super-admin roli
 *   3) auth.user_system_access   — xbt (super-admin) + mahalla (admin) ruxsati
 *
 * Idempotent: login bo'yicha firstOrCreate; mavjud (dev) `admin` — o'zgartirilmaydi,
 * dubl yaratilmaydi (mavjud UUID ishlatiladi). Faqat PostgreSQL.
 *
 * Parol faqat env(ADMIN_SEED_PASSWORD)'dan (production'da majburiy — kodda parol yo'q).
 */
class SuperAdminSeeder extends Seeder
{
    private const LOGIN = 'admin';

    public function run(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $password = $this->resolvePassword();

        // 1) Markaziy identifikatsiya (auth.users) — login bo'yicha idempotent.
        //    Mavjud bo'lsa parol/holatga tegmaydi; mavjud UUID qaytadi.
        $user = User::firstOrCreate(
            ['login' => self::LOGIN],
            [
                'name' => 'Super Admin',
                'password' => $password, // 'hashed' cast — avtomatik hash
                'is_active' => true,
            ],
        );

        // 2) HR profil (public.users) — bir xil UUID. Dept/lavozim bo'lsa biriktiriladi
        //    (fresh deploy'da bo'sh bo'lishi mumkin → nullable).
        $hokimlikId = Department::query()->whereNull('parent_id')->orderBy('created_at')->value('id');
        $positionId = Position::query()->where('role_name', 'super-admin')->value('id')
            ?? Position::query()->orderBy('created_at')->value('id');

        HrProfile::firstOrCreate(
            ['id' => $user->id],
            [
                'name' => 'Super Admin',
                'login' => self::LOGIN,
                'email' => 'admin@kbt.uz',
                'password' => $password, // 'hashed' cast (legacy — kirish auth.users'da)
                'department_id' => $hokimlikId,
                'position_id' => $positionId,
            ],
        );

        // 3) Tizim ruxsatlari — xbt (super-admin) + mahalla (admin). Idempotent.
        $this->grantAccess($user->id, 'xbt', 'super-admin');
        $this->grantAccess($user->id, 'mahalla', 'admin');

        // 4) Spatie super-admin roli (HR domeni). getMorphClass() = App\Models\User
        //    → mavjud pivotga mos; assignRole idempotent (dubl yo'q).
        $profile = HrProfile::query()->findOrFail($user->id);
        if (! $profile->hasRole('super-admin')) {
            $profile->assignRole('super-admin');
        }
    }

    /**
     * Foydalanuvchiga tizim ruxsati (auth.user_system_access) — mavjud bo'lmasa insert.
     * Mavjud grant o'zgartirilmaydi (dev no-op).
     */
    private function grantAccess(string $userId, string $systemCode, string $role): void
    {
        $auth = DB::connection('auth');

        $systemId = $auth->table('systems')->where('code', $systemCode)->value('id');
        if ($systemId === null) {
            return; // SystemsSeeder oldin ishlashi kerak.
        }

        $exists = $auth->table('user_system_access')
            ->where('user_id', $userId)
            ->where('system_id', $systemId)
            ->exists();

        if ($exists) {
            return;
        }

        $auth->table('user_system_access')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'system_id' => $systemId,
            'role' => $role,
            'is_active' => true,
            'granted_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Boshlang'ich admin paroli — faqat .env'dan (ADMIN_SEED_PASSWORD).
     * Kodga yozilgan parol xavfsizlik teshigi — production'da .env'da bo'lmasa seed to'xtaydi.
     */
    private function resolvePassword(): string
    {
        $password = (string) env('ADMIN_SEED_PASSWORD', '');

        if ($password !== '') {
            return $password;
        }

        if (app()->environment('production')) {
            throw new RuntimeException(
                'ADMIN_SEED_PASSWORD .env da o\'rnatilmagan. Production da admin parolini kodga yozib bo\'lmaydi.',
            );
        }

        // Faqat local/dev uchun — ochiq ogohlantirish bilan.
        $this->command?->warn('ADMIN_SEED_PASSWORD topilmadi — dev uchun vaqtinchalik parol ishlatildi. Kirib darhol almashtiring!');

        return 'ChangeMe!'.bin2hex(random_bytes(4));
    }
}
