<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Controllers\Api;

use App\Domains\Hr\Models\Department;
use App\Domains\Hr\Models\HrProfile;
use App\Domains\Hr\Models\Organization;
use App\Domains\Hr\Models\Position;
use App\Domains\Hr\Support\Authorization\RoleHierarchy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends HrController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', HrProfile::class);

        $users = HrProfile::with(['roles', 'department', 'position'])
            ->tap(fn (Builder $q) => $this->scopeToTenant($q))
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->whereLike('name', "%{$s}%")
                ->orWhereLike('login', "%{$s}%")))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return response()->json([
            'users' => $users,
            'filters' => $request->only('search'),
        ]);
    }

    public function create(): JsonResponse
    {
        $this->authorize('create', HrProfile::class);

        return response()->json([]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', HrProfile::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'login' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9._-]+$/', 'unique:users,login'],
            'password' => ['required', 'string', Password::min(12)->mixedCase()->numbers()->symbols()],
            'department_id' => ['required', 'string', 'exists:departments,id'],
            'position_id' => ['required', 'string', 'exists:positions,id'],
        ]);

        $position = Position::findOrFail($validated['position_id']);
        $roleName = $position->role_name ?? 'mutaxassis';

        $actor = $this->actor();
        $this->guardDepartmentTenant($actor, $validated['department_id']);
        $this->guardAssignableRole($actor, $roleName);

        $user = HrProfile::create([
            'name' => $validated['name'],
            'login' => $validated['login'],
            'password' => Hash::make($validated['password']),
            'department_id' => $validated['department_id'],
            'position_id' => $validated['position_id'],
        ]);

        $user->assignRole($roleName);

        return response()->json([
            'message' => 'Фойдаланувчи яратилди.',
            'user' => $user->load(['roles', 'department', 'position']),
        ], 201);
    }

    public function edit(HrProfile $user): JsonResponse
    {
        $this->authorize('update', $user);

        $user->load(['roles', 'department:id,parent_id,name_cyr', 'position']);

        return response()->json([
            'editUser' => $user,
        ]);
    }

    public function update(Request $request, HrProfile $user): JsonResponse
    {
        $this->authorize('update', $user);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'login' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9._-]+$/', Rule::unique('users', 'login')->ignore($user->id)],
            'password' => ['nullable', 'string', Password::min(12)->mixedCase()->numbers()->symbols()],
            'department_id' => ['required', 'string', 'exists:departments,id'],
            'position_id' => ['required', 'string', 'exists:positions,id'],
        ]);

        $position = Position::findOrFail($validated['position_id']);
        $roleName = $position->role_name ?? 'mutaxassis';

        $actor = $this->actor();
        $this->guardDepartmentTenant($actor, $validated['department_id']);
        $this->guardAssignableRole($actor, $roleName);

        $updateData = [
            'name' => $validated['name'],
            'login' => $validated['login'],
            'department_id' => $validated['department_id'],
            'position_id' => $validated['position_id'],
        ];

        if (! empty($validated['password'])) {
            $updateData['password'] = Hash::make($validated['password']);
        }

        $user->update($updateData);

        // Лавозим ўзгарса ролни ҳам янгилаш
        $user->syncRoles([$roleName]);

        return response()->json([
            'message' => 'Фойдаланувчи янгиланди.',
            'user' => $user->fresh(['roles', 'department', 'position']),
        ]);
    }

    public function destroy(HrProfile $user): JsonResponse
    {
        $this->authorize('delete', $user);

        if ($user->id === $this->actor()->id) {
            return response()->json(['message' => 'Ўзингизни ўчира олмайсиз.'], 403);
        }

        $user->delete();

        return response()->json(['message' => 'Фойдаланувчи ўчирилди.']);
    }

    /**
     * Index ro'yxatini joriy tenant bilan cheklash.
     * Cross-tenant admin (super/viloyat) — barcha foydalanuvchilar.
     */
    private function scopeToTenant(Builder $query): void
    {
        $actor = $this->actor();

        if ($actor->hasRole('super-admin') || $actor->hasRole('viloyat-admin')) {
            return;
        }

        $tenantId = $actor->hokimlik_id;
        if ($tenantId === null) {
            // Tenant aniqlanmasa — hech kimni ko'rsatmaymiz (fail-closed).
            $query->whereRaw('1 = 0');

            return;
        }

        $root = Department::find($tenantId);
        $deptIds = $root !== null ? $root->descendantAndSelfIds() : [];
        // Organization modeli tenant-scoped — joriy tenant tashkilotlari.
        $orgIds = Organization::query()->pluck('id')->all();

        $query->where(fn (Builder $q) => $q
            ->whereIn('department_id', $deptIds)
            ->orWhereIn('organization_id', $orgIds));
    }

    /**
     * Tanlangan bo'lim actor tenantiga tegishli bo'lishi shart (cross-tenant admindan tashqari).
     */
    private function guardDepartmentTenant(HrProfile $actor, string $departmentId): void
    {
        if ($actor->hasRole('super-admin') || $actor->hasRole('viloyat-admin')) {
            return;
        }

        $dept = Department::find($departmentId);
        abort_if(
            $dept === null || $dept->rootId() !== $actor->hokimlik_id,
            403,
            'Бошқа ҳокимлик бўлимига фойдаланувчи бириктириб бўлмайди.',
        );
    }

    /**
     * Tayinlanayotgan rol actor darajasidan qat'iy past bo'lishi shart —
     * privilegiya eskalatsiyasining oldini oladi (masalan tuman-admin → super-admin).
     */
    private function guardAssignableRole(HrProfile $actor, string $roleName): void
    {
        abort_unless(
            RoleHierarchy::canAssign($actor, $roleName),
            403,
            'Бу лавозим/ролни тайинлашга рухсатингиз йўқ.',
        );
    }
}
