<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Services;

use App\Domains\Qurilish\Models\Organization;
use App\Domains\Qurilish\Models\QurilishProfile;
use App\Domains\Qurilish\Support\QurilishAccess;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Qurilish tizimi hisoblari — ochish, doirasini o'zgartirish, bloklash.
 *
 * Ikki manba bir vaqtda yozilishi shart: `auth.user_system_access` (kirish
 * huquqi) va `qurilish.profiles` (tashkilot doirasi). Ular turli ulanishda,
 * shuning uchun bitta tranzaksiyaga o'ralmaydi — tartib himoya vazifasini
 * bajaradi: profil AVVAL yaratiladi, kirish huquqi KEYIN. Teskarisida
 * doirasiz-u kira oladigan hisob qolib ketardi va u hech nima ko'rmasdi.
 *
 * Parol hech qachon qaytarib ko'rsatilmaydi: u faqat YARATILGAN yoki
 * TIKLANGAN paytda, bir marta qaytadi.
 */
class UserAdminService
{
    /** Bu rollar tashkilotsiz hech narsa ko'rmaydi (fail-closed scope). */
    private const NEEDS_ORG = ['qurilish_buyurtmachi', 'qurilish_boshqarma'];

    public function __construct(
        private readonly QurilishAccess $access,
        private readonly AdminAuditLogger $audit,
    ) {}

    /**
     * Domen hisoblari ro'yxati.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function list(?string $search = null, ?string $role = null): Collection
    {
        $systemId = $this->systemId();

        $rows = DB::connection('auth')->table('user_system_access as usa')
            ->join('users as u', 'u.id', '=', 'usa.user_id')
            ->where('usa.system_id', $systemId)
            ->whereNull('u.deleted_at')
            ->when($role !== null && $role !== '', fn ($q) => $q->where('usa.role', $role))
            ->when($search !== null && $search !== '', function ($q) use ($search) {
                $like = '%'.str_replace('%', '\%', $search).'%';
                $q->where(fn ($w) => $w->where('u.login', 'ilike', $like)->orWhere('u.name', 'ilike', $like));
            })
            ->orderBy('usa.role')
            ->orderBy('u.name')
            ->get(['u.id', 'u.login', 'u.name', 'u.is_active as user_active',
                'usa.role', 'usa.is_active as access_active', 'usa.created_at']);

        $profiles = QurilishProfile::query()
            ->whereIn('user_id', $rows->pluck('id')->all())
            ->with('organization:id,name_cyr')
            ->get()
            ->keyBy('user_id');

        return $rows->map(function ($r) use ($profiles): array {
            $profile = $profiles->get($r->id);

            return [
                'id' => $r->id,
                'login' => $r->login,
                'name' => $r->name,
                'role' => $r->role,
                'role_name' => QurilishAccess::ROLE_NAMES[$r->role] ?? $r->role,
                'organization_id' => $profile?->organization_id,
                'organization_name' => $profile?->organization?->name_cyr,
                'position' => $profile?->position,
                // Hisob ikki joyda o'chirilishi mumkin — foydalanuvchida ham,
                // tizimga kirishida ham. UI uchun ikkalasi ham FAOL bo'lishi shart.
                'is_active' => (bool) $r->user_active && (bool) $r->access_active,
                'created_at' => $r->created_at,
            ];
        });
    }

    /**
     * Yangi hisob. Qaytaradi: [profil ma'lumoti, bir martalik parol].
     *
     * @param  array<string, mixed>  $data
     * @return array{user: array<string, mixed>, password: string}
     */
    public function create(array $data, User $actor): array
    {
        $role = $this->assertRole($data['role']);
        $orgId = $this->assertOrganization($role, $data['organization_id'] ?? null);
        $login = trim((string) $data['login']);

        if (User::withTrashed()->where('login', $login)->exists()) {
            throw ValidationException::withMessages([
                'login' => 'Бу логин аллақачон банд.',
            ]);
        }

        $password = $data['password'] ?? Str::password(16);

        $user = User::query()->create([
            'login' => $login,
            'name' => trim((string) $data['name']),
            'password' => $password,
            'is_active' => true,
        ]);

        QurilishProfile::query()->create([
            'user_id' => $user->id,
            'role' => $role,
            'organization_id' => $orgId,
            'position' => $data['position'] ?? null,
            'is_active' => true,
        ]);

        DB::connection('auth')->table('user_system_access')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'system_id' => $this->systemId(),
            'role' => $role,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit->log($actor, 'user_create', 'user', $user->id, $login, [
            'role' => $role,
            'organization_id' => $orgId,
        ]);

        return [
            'user' => $this->one($user->id),
            'password' => $password,
        ];
    }

    /**
     * Rol, doira, ism o'zgartirish. Parol bu yerda o'zgarmaydi.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(string $userId, array $data, User $actor): array
    {
        $user = User::query()->findOrFail($userId);
        $this->assertInSystem($userId);

        $profile = QurilishProfile::query()->where('user_id', $userId)->first();
        $role = isset($data['role']) ? $this->assertRole($data['role']) : ($profile?->role ?? 'qurilish_hokimlik');
        $orgId = $this->assertOrganization(
            $role,
            array_key_exists('organization_id', $data) ? $data['organization_id'] : $profile?->organization_id,
        );

        if (isset($data['name'])) {
            $user->name = trim((string) $data['name']);
            $user->save();
        }

        QurilishProfile::query()->updateOrCreate(
            ['user_id' => $userId],
            [
                'role' => $role,
                'organization_id' => $orgId,
                'position' => $data['position'] ?? $profile?->position,
                'is_active' => true,
            ],
        );

        DB::connection('auth')->table('user_system_access')
            ->where('user_id', $userId)->where('system_id', $this->systemId())
            ->update(['role' => $role, 'updated_at' => now()]);

        $this->audit->log($actor, 'user_update', 'user', $userId, $user->login, [
            'role' => $role,
            'organization_id' => $orgId,
        ]);

        return $this->one($userId);
    }

    /** Parolni tiklaydi va yangisini BIR MARTA qaytaradi. */
    public function resetPassword(string $userId, User $actor, ?string $password = null): string
    {
        $user = User::query()->findOrFail($userId);
        $this->assertInSystem($userId);

        $new = $password ?: Str::password(16);
        $user->password = $new;
        $user->save();

        // Parolning o'zi emas, faqat FAKTI yoziladi.
        $this->audit->log($actor, 'user_password_reset', 'user', $userId, $user->login);

        return $new;
    }

    /**
     * Kirishni yoqish/o'chirish.
     *
     * Hisob O'CHIRILMAYDI — bloklanadi. Nazorat tizimida o'chirilgan hisob
     * o'zi qoldirgan audit izlarini «kim» siz qoldirardi.
     */
    public function setActive(string $userId, bool $active, User $actor): array
    {
        $user = User::query()->findOrFail($userId);
        $this->assertInSystem($userId);

        if ($user->id === $actor->id && ! $active) {
            throw ValidationException::withMessages([
                'is_active' => 'Ўз ҳисобингизни ўзингиз блоклай олмайсиз.',
            ]);
        }

        DB::connection('auth')->table('user_system_access')
            ->where('user_id', $userId)->where('system_id', $this->systemId())
            ->update(['is_active' => $active, 'updated_at' => now()]);

        QurilishProfile::query()->where('user_id', $userId)->update(['is_active' => $active]);

        $this->audit->log(
            $actor,
            $active ? 'user_enable' : 'user_disable',
            'user',
            $userId,
            $user->login,
        );

        return $this->one($userId);
    }

    /** @return array<string, mixed> */
    private function one(string $userId): array
    {
        $row = $this->list()->firstWhere('id', $userId);

        if ($row === null) {
            abort(404, 'Фойдаланувчи топилмади.');
        }

        return $row;
    }

    private function assertRole(mixed $role): string
    {
        $role = (string) $role;

        if (! in_array($role, QurilishAccess::ROLES, true)) {
            throw ValidationException::withMessages(['role' => 'Нотўғри роль.']);
        }

        return $role;
    }

    private function assertOrganization(string $role, mixed $orgId): ?string
    {
        $orgId = $orgId === '' ? null : $orgId;

        if ($orgId === null) {
            if (in_array($role, self::NEEDS_ORG, true)) {
                throw ValidationException::withMessages([
                    'organization_id' => 'Бу роль учун ташкилот танланиши шарт — усиз фойдаланувчи ҳеч қандай объект кўрмайди.',
                ]);
            }

            return null;
        }

        if (! Organization::query()->whereKey($orgId)->exists()) {
            throw ValidationException::withMessages(['organization_id' => 'Ташкилот топилмади.']);
        }

        return (string) $orgId;
    }

    private function assertInSystem(string $userId): void
    {
        $exists = DB::connection('auth')->table('user_system_access')
            ->where('user_id', $userId)->where('system_id', $this->systemId())->exists();

        if (! $exists) {
            // Boshqa tizim foydalanuvchisini bu yerdan boshqarib bo'lmaydi.
            abort(404, 'Фойдаланувчи бу тизимда рўйхатдан ўтмаган.');
        }
    }

    private function systemId(): string
    {
        $id = DB::connection('auth')->table('systems')
            ->where('code', QurilishAccess::SYSTEM_CODE)->value('id');

        if ($id === null) {
            abort(500, '«qurilish» тизими рўйхатда йўқ.');
        }

        return (string) $id;
    }
}
