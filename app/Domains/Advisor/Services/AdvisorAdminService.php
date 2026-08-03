<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Services;

use App\Domains\Advisor\Models\Advisor;
use App\Domains\Advisor\Support\AdvisorAccess;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * MASLAHATCHILAR (hisoblar) boshqaruvi — FAQAT viloyat super-admin (kontrollerда
 * tekshiriladi). Markaziy auth (auth.users + auth.user_system_access) + advisor
 * profili (advisor.advisors) ustidan idempotent amallar.
 *
 * Parollar HECH QAYERДА ochiq saqlanmaydi — yaratish/reset paytiда bir marta
 * qaytariladi (UI ko'rsatadi), keyin faqat hash qoladi.
 */
class AdvisorAdminService
{
    /** Daraja => advisor tizimi roli (auth.user_system_access.role). */
    private const LEVEL_ROLE = [
        'viloyat' => 'advisor_viloyat',
        'bolinma' => 'advisor_bolinma',
        'tuman' => 'advisor_tuman',
    ];

    /** Ro'yxatда tartib: viloyat -> bo'linma -> tuman. */
    private const LEVEL_RANK = ['viloyat' => 0, 'bolinma' => 1, 'tuman' => 2];

    /**
     * Barcha maslahatchilar ro'yxati (daraja + tuman + login + holat).
     *
     * @return array<int, array{id: string, user_id: string, login: string, name: string, level: string, role: ?string, district: ?array{id: string, name: ?string}, active: bool, last_login_at: ?string}>
     */
    public function list(): array
    {
        $advisors = Advisor::query()->with('district:id,name_cyr')->get();
        $userIds = $advisors->pluck('user_id')->all();

        $users = DB::connection('auth')->table('users')
            ->whereIn('id', $userIds)
            ->get(['id', 'login', 'name', 'is_active', 'last_login_at'])
            ->keyBy('id');

        $roles = DB::connection('auth')->table('user_system_access as usa')
            ->join('systems as s', 's.id', '=', 'usa.system_id')
            ->where('s.code', AdvisorAccess::SYSTEM_CODE)
            ->whereIn('usa.user_id', $userIds)
            ->pluck('usa.role', 'usa.user_id');

        $rows = $advisors->map(function (Advisor $a) use ($users, $roles) {
            $u = $users->get($a->user_id);

            return [
                'id' => $a->id,
                'user_id' => $a->user_id,
                'login' => $u->login ?? '—',
                'name' => $u->name ?? $a->position ?? '—',
                'level' => $a->level,
                'role' => $roles[$a->user_id] ?? null,
                'district' => $a->district === null ? null
                    : ['id' => $a->district->id, 'name' => $a->district->name_cyr],
                'active' => (bool) ($u->is_active ?? $a->active),
                'last_login_at' => $u->last_login_at ?? null,
            ];
        })->all();

        // Daraja bo'yicha (viloyat->bo'linma->tuman), so'ng tuman nomi bo'yicha.
        usort($rows, function ($x, $y) {
            $lx = self::LEVEL_RANK[$x['level']] ?? 9;
            $ly = self::LEVEL_RANK[$y['level']] ?? 9;
            if ($lx !== $ly) {
                return $lx <=> $ly;
            }

            return strcmp((string) ($x['district']['name'] ?? ''), (string) ($y['district']['name'] ?? ''));
        });

        return $rows;
    }

    /**
     * Yangi maslahatchi (user + advisor rol + profil). Parol GENERATSIYA qilinadi
     * va bir marta qaytariladi.
     *
     * @param  array{login: string, name: string, level: string, district_id?: ?string, phone?: ?string}  $data
     * @return array{user_id: string, login: string, name: string, password: string}
     */
    public function create(array $data): array
    {
        $level = $data['level'];
        $role = self::LEVEL_ROLE[$level] ?? throw new RuntimeException('Notoʻgʻri daraja.');
        $districtId = $level === 'tuman' ? ($data['district_id'] ?? null) : null;

        if ($level === 'tuman' && $districtId === null) {
            throw ValidationException::withMessages(['district_id' => 'Туман маслаҳатчиси учун ҳудуд танланиши шарт.']);
        }

        if (User::where('login', $data['login'])->exists()) {
            throw ValidationException::withMessages(['login' => 'Бу логин банд.']);
        }

        $password = $this->generatePassword();

        $user = User::create([
            'login' => $data['login'],
            'name' => $data['name'],
            'password' => $password, // 'hashed' cast xeshlайди
            'phone' => $data['phone'] ?? null,
            'is_active' => true,
        ]);

        $this->grantAdvisorRole((string) $user->id, $role);

        Advisor::create([
            'user_id' => $user->id,
            'level' => $level,
            'district_id' => $districtId,
            'position' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'active' => true,
        ]);

        return ['user_id' => (string) $user->id, 'login' => $user->login, 'name' => $user->name, 'password' => $password];
    }

    /**
     * Parolni reset qiladi (yangi generatsiya) — bir marta qaytariladi. FAQAT
     * advisor profiliga ega foydalanuvchi uchun (boshqa tizim admini emas).
     *
     * @return array{login: string, name: string, password: string}
     */
    public function resetPassword(string $userId): array
    {
        $advisor = Advisor::where('user_id', $userId)->first();
        if ($advisor === null) {
            throw ValidationException::withMessages(['user_id' => 'Маслаҳатчи топилмади.']);
        }

        $user = User::findOrFail($userId);
        $password = $this->generatePassword();
        $user->forceFill(['password' => $password])->save();

        return ['login' => $user->login, 'name' => $user->name, 'password' => $password];
    }

    /**
     * Faol/nofaol qiladi (auth.users.is_active + advisor.advisors.active). Nofaol
     * foydalanuvchi tizimga kira olmaydi (AuthController is_active tekshiradi).
     */
    public function setActive(string $userId, bool $active): void
    {
        $advisor = Advisor::where('user_id', $userId)->first();
        if ($advisor === null) {
            throw ValidationException::withMessages(['user_id' => 'Маслаҳатчи топилмади.']);
        }

        User::whereKey($userId)->update(['is_active' => $active]);
        $advisor->update(['active' => $active]);
    }

    /**
     * Profilni tahrirlaydi (ism; tuman uchun hudud). Login/daraja/rol o'zgармайди.
     *
     * @param  array{name?: string, district_id?: ?string, phone?: ?string}  $data
     */
    public function update(string $userId, array $data): void
    {
        $advisor = Advisor::where('user_id', $userId)->first();
        if ($advisor === null) {
            throw ValidationException::withMessages(['user_id' => 'Маслаҳатчи топилмади.']);
        }

        if (array_key_exists('name', $data) && $data['name'] !== null) {
            User::whereKey($userId)->update(['name' => $data['name']]);
            $advisor->position = $data['name'];
        }

        if ($advisor->level === 'tuman' && array_key_exists('district_id', $data)) {
            $advisor->district_id = $data['district_id'];
        }

        if (array_key_exists('phone', $data)) {
            $advisor->phone = $data['phone'];
            User::whereKey($userId)->update(['phone' => $data['phone']]);
        }

        $advisor->save();
    }

    /**
     * Maslahatchilar uchun yangi parol generatsiya qiladi (bulk) — konsol buyrug'i
     * uchun. Har biriga alohida parol; ro'yxat qaytariladi (bir marta).
     *
     * DIQQAT: viloyat maslahatchisi (super-admin, o'z paroli qo'lда o'rnatilган)
     * DEFAULT'да o'tkazib yuboriladi — uni tasodifan almashtirmaslik uchun.
     * $includeViloyat=true bo'lса, u ham reset qilinadi.
     *
     * @return array<int, array{login: string, name: string, level: string, district: ?string, password: string}>
     */
    public function resetAll(bool $includeViloyat = false): array
    {
        $out = [];
        foreach ($this->list() as $row) {
            if (! $includeViloyat && $row['level'] === 'viloyat') {
                continue;
            }
            $creds = $this->resetPassword($row['user_id']);
            $out[] = [
                'login' => $creds['login'],
                'name' => $creds['name'],
                'level' => $row['level'],
                'district' => $row['district']['name'] ?? null,
                'password' => $creds['password'],
            ];
        }

        return $out;
    }

    /**
     * Kuchli, o'qiladigan parol (harflar+raqamlар+belgi). Str::password —
     * kriptografik jihatдан xavfsiz random.
     */
    private function generatePassword(int $length = 12): string
    {
        return Str::password($length, letters: true, numbers: true, symbols: true, spaces: false);
    }

    /**
     * Foydalanuvchiga advisor tizimi rolini beradi (auth.user_system_access) —
     * idempotent (AdvisorSeeder naqshi).
     */
    private function grantAdvisorRole(string $userId, string $role): void
    {
        $auth = DB::connection('auth');

        $systemId = $auth->table('systems')->where('code', AdvisorAccess::SYSTEM_CODE)->value('id');
        if ($systemId === null) {
            throw new RuntimeException('Advisor tizimi (auth.systems) topilmadi — seeder ishga tushiring.');
        }

        $exists = $auth->table('user_system_access')
            ->where('user_id', $userId)->where('system_id', $systemId)->exists();
        if ($exists) {
            $auth->table('user_system_access')
                ->where('user_id', $userId)->where('system_id', $systemId)
                ->update(['role' => $role, 'is_active' => true, 'updated_at' => now()]);

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
}
