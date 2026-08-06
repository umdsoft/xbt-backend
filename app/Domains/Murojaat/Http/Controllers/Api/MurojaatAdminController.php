<?php

declare(strict_types=1);

namespace App\Domains\Murojaat\Http\Controllers\Api;

use App\Domains\Murojaat\Models\MurojaatProfile;
use App\Domains\Murojaat\Support\MurojaatAccess;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * MurojAAT foydalanuvchilari boshqaruvi — FAQAT 'murojaat.manage' (viloyat/admin).
 * Login/parol yaratish + reset. Advisor AdvisorAdminController naqshi.
 */
class MurojaatAdminController extends Controller
{
    public function __construct(private readonly MurojaatAccess $access) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'murojaat.manage'), 403);
        $scope = $this->access->scopeFor($user);

        $systemId = $this->systemId();
        $rows = DB::connection('auth')->table('user_system_access as usa')
            ->join('users as u', 'u.id', '=', 'usa.user_id')
            ->where('usa.system_id', $systemId)
            ->get(['u.id', 'u.login', 'u.name', 'u.is_active', 'usa.role']);

        $profiles = MurojaatProfile::query()->pluck('district_id', 'user_id');
        $out = $rows->map(fn ($r) => [
            'id' => $r->id, 'login' => $r->login, 'name' => $r->name,
            'is_active' => (bool) $r->is_active, 'role' => $r->role,
            'district_id' => $profiles[$r->id] ?? null,
        ])->filter(fn ($r) => $scope->seesAllDistricts() || $r['district_id'] === $scope->districtId)
            ->values();

        return response()->json(['users' => $out]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'murojaat.manage'), 403);
        $scope = $this->access->scopeFor($user);

        $v = $request->validate([
            'login' => ['required', 'string', 'max:64', Rule::unique('auth.users', 'login')],
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::in(MurojaatAccess::ROLES)],
            'district_id' => ['nullable', 'uuid'],
        ]);

        $districtId = $scope->seesAllDistricts() ? ($v['district_id'] ?? null) : $scope->districtId;
        $level = $v['role'] === 'murojaat_viloyat' ? 'viloyat' : 'tuman';
        $password = Str::password(12);

        DB::transaction(function () use ($v, $districtId, $level, $password) {
            $newUser = User::create(['login' => $v['login'], 'name' => $v['name'], 'password' => $password, 'is_active' => true]);
            $this->grant($newUser->id, $v['role']);
            MurojaatProfile::create(['user_id' => $newUser->id, 'level' => $level, 'district_id' => $districtId, 'position' => $v['name'], 'active' => true]);
        });

        return response()->json(['ok' => true, 'login' => $v['login'], 'password' => $password], 201);
    }

    public function resetPassword(Request $request, string $user): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'murojaat.manage'), 403);
        $target = User::on('auth')->findOrFail($user);
        $password = Str::password(12);
        $target->forceFill(['password' => $password])->save();

        return response()->json(['ok' => true, 'login' => $target->login, 'password' => $password]);
    }

    public function update(Request $request, string $user): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'murojaat.manage'), 403);
        $v = $request->validate([
            'role' => ['sometimes', Rule::in(MurojaatAccess::ROLES)],
            'district_id' => ['sometimes', 'nullable', 'uuid'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('is_active', $v)) {
            User::on('auth')->where('id', $user)->update(['is_active' => $v['is_active']]);
        }
        if (isset($v['role'])) {
            DB::connection('auth')->table('user_system_access')
                ->where('user_id', $user)->where('system_id', $this->systemId())
                ->update(['role' => $v['role'], 'updated_at' => now()]);
        }
        if (array_key_exists('district_id', $v)) {
            MurojaatProfile::query()->where('user_id', $user)->update(['district_id' => $v['district_id']]);
        }

        return response()->json(['ok' => true]);
    }

    private function systemId(): ?string
    {
        return DB::connection('auth')->table('systems')->where('code', MurojaatAccess::SYSTEM_CODE)->value('id');
    }

    private function grant(string $userId, string $role): void
    {
        $systemId = $this->systemId();
        if ($systemId === null) {
            return;
        }
        DB::connection('auth')->table('user_system_access')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $userId, 'system_id' => $systemId,
            'role' => $role, 'is_active' => true, 'granted_by' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
