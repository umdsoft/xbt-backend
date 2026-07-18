<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Controllers\Api;

use App\Domains\Hr\Http\Requests\StoreOrganizationUserRequest;
use App\Domains\Hr\Models\HrProfile;
use App\Domains\Hr\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

/**
 * Ташкилот фойдаланувчилари (admin + жамоа) — котибият мудири очади/бошқаради.
 */
class OrganizationUserController extends HrController
{
    /** Ташкилотга admin/ходим логини яратиш. */
    public function store(StoreOrganizationUserRequest $request, Organization $organization): JsonResponse
    {
        $this->authorize('manageUsers', $organization);

        $user = HrProfile::create([
            'name' => $request->validated('name'),
            'login' => $request->validated('login'),
            'password' => Hash::make($request->validated('password')),
            'organization_id' => $organization->id,
            // department_id / position_id — null (org foydalanuvchisi)
        ]);

        $user->assignRole($request->validated('role'));

        return response()->json([
            'message' => 'Ташкилот фойдаланувчиси яратилди.',
            'user' => $user->load('roles'),
        ], 201);
    }

    /** Ташкилот фойдаланувчисини ўчириш. */
    public function destroy(Organization $organization, HrProfile $user): JsonResponse
    {
        $this->authorize('manageUsers', $organization);

        // Faqat shu tashkilotga tegishli foydalanuvchini o'chirish mumkin
        abort_unless($user->organization_id === $organization->id, 403);

        $user->delete();

        return response()->json([
            'message' => 'Фойдаланувчи ўчирилди.',
        ]);
    }
}
