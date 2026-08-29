<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Console\Commands;

use App\Domains\Ayollar\Models\Staff;
use App\Domains\Ayollar\Support\AyollarAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * «Ayollar Balansi» foydalanuvchisini yaratadi yoki yangilaydi.
 *
 * IKKI YOZUV kerak va ular BIRGA yaratilishi shart:
 *   `auth.users` + `auth.user_system_access` — KIM va qaysi tizimda;
 *   `ayollar.staff`                          — NIMANI ko'radi (doira).
 *
 * Ikkinchisisiz foydalanuvchi kira oladi, lekin hech narsa ko'rmaydi —
 * bu holat oldingi modullarda («hisob ochildi, lekin bo'sh ekran»)
 * bir necha marta takrorlangan. Shuning uchun bitta buyruq ikkalasini
 * ham qiladi.
 */
class MakeAyollarUserCommand extends Command
{
    protected $signature = 'ayollar:make-user
        {login : Kirish nomi}
        {--name= : F.I.Sh.}
        {--password= : Parol (berilmasa tasodifiy)}
        {--role= : '.'Rol: '.'mfy_activist|mfy_chairman|hokim_assistant|district_family_dept|district_org|region_analyst|admin}
        {--district= : Tuman nomi yoki ID}
        {--mahalla= : MFY nomi yoki ID}
        {--org= : Tuman idorasi kodi (district_org roli uchun)}
        {--position= : Lavozim}';

    protected $description = 'Ayollar Balansi tizimiga foydalanuvchi qo‘shadi (auth + doira).';

    public function handle(): int
    {
        $role = (string) $this->option('role');

        if (! in_array($role, AyollarAccess::ROLES, true)) {
            $this->error('Noto‘g‘ri rol. Mumkin: '.implode(', ', AyollarAccess::ROLES));

            return self::FAILURE;
        }

        $district = $this->resolveDistrict();
        $mahalla = $this->resolveMahalla($district);

        // MFY darajasidagi rol MFY'siz ma'nosiz: u hech narsa ko'rmaydi.
        // Buni yaratish paytida ushlash keyin «nega bo'sh?» deb
        // qidirishdan ancha arzon.
        $level = AyollarAccess::ROLE_SCOPE[$role];

        if ($level === AyollarAccess::SCOPE_MAHALLA && $mahalla === null) {
            $this->error("«{$role}» roli uchun --mahalla majburiy — aks holda foydalanuvchi hech narsa ko‘rmaydi.");

            return self::FAILURE;
        }

        if ($level === AyollarAccess::SCOPE_DISTRICT && $district === null) {
            $this->error("«{$role}» roli uchun --district majburiy.");

            return self::FAILURE;
        }

        $login = (string) $this->argument('login');
        $password = (string) ($this->option('password') ?: Str::random(14));

        $auth = DB::connection('auth');
        $userId = $auth->table('users')->where('login', $login)->value('id');

        if ($userId === null) {
            $userId = (string) Str::uuid();
            $auth->table('users')->insert([
                'id' => $userId,
                'login' => $login,
                'name' => (string) ($this->option('name') ?: $login),
                'password' => Hash::make($password),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->info("Yangi hisob: {$login}");
        } else {
            $auth->table('users')->where('id', $userId)->update([
                'password' => Hash::make($password),
                'updated_at' => now(),
            ]);
            $this->info("Mavjud hisob yangilandi: {$login}");
        }

        $systemId = $auth->table('systems')->where('code', AyollarAccess::SYSTEM_CODE)->value('id');

        if ($systemId === null) {
            $this->error('auth.systems da `ayollar` yo‘q. `php artisan db:seed --class=SystemsSeeder` yuriting.');

            return self::FAILURE;
        }

        // Reyestr grantini qayta yozamiz: rol o'zgarganda eskisi qolib
        // ketmasin (bir foydalanuvchi bir tizimda bitta rol).
        $auth->table('user_system_access')
            ->where('user_id', $userId)->where('system_id', $systemId)->delete();

        $auth->table('user_system_access')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'system_id' => $systemId,
            'role' => $role,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $regionId = DB::connection('master')->table('regions')->value('id');

        Staff::query()->updateOrCreate(
            ['user_id' => $userId],
            [
                'region_id' => $regionId,
                'district_id' => $district,
                'mahalla_id' => $mahalla,
                'org_code' => $this->option('org'),
                'position' => (string) ($this->option('position') ?: AyollarAccess::ROLE_NAMES[$role]),
                'is_active' => true,
            ],
        );

        $this->newLine();
        $this->table(['Maydon', 'Qiymat'], [
            ['Login', $login],
            ['Parol', $password],
            ['Rol', AyollarAccess::ROLE_NAMES[$role]],
            ['Doira', $level],
            ['Tuman', $district ?? '—'],
            ['MFY', $mahalla ?? '—'],
            ['Idora', $this->option('org') ?? '—'],
        ]);

        return self::SUCCESS;
    }

    /** Nom yoki ID bo'yicha tumanni topadi. */
    private function resolveDistrict(): ?string
    {
        $input = (string) $this->option('district');

        if ($input === '') {
            return null;
        }

        $id = DB::connection('master')->table('districts')
            ->where('id', $input)
            ->orWhere('name_lat', 'ilike', "%{$input}%")
            ->orWhere('name_cyr', 'ilike', "%{$input}%")
            ->value('id');

        if ($id === null) {
            $this->warn("Tuman topilmadi: {$input}");
        }

        return $id === null ? null : (string) $id;
    }

    private function resolveMahalla(?string $districtId): ?string
    {
        $input = (string) $this->option('mahalla');

        if ($input === '') {
            return null;
        }

        $query = DB::connection('master')->table('mahallas')
            ->where(function ($q) use ($input): void {
                $q->where('id', $input)
                    ->orWhere('name_lat', 'ilike', "%{$input}%")
                    ->orWhere('name_cyr', 'ilike', "%{$input}%");
            });

        if ($districtId !== null) {
            $query->where('district_id', $districtId);
        }

        $id = $query->value('id');

        if ($id === null) {
            $this->warn("MFY topilmadi: {$input}");
        }

        return $id === null ? null : (string) $id;
    }
}
