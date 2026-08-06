<?php

declare(strict_types=1);

namespace App\Domains\Murojaat\Http\Controllers\Api;

use App\Domains\Murojaat\Support\MurojaatAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MurojAAT konteksti: joriy profil, rol, ruxsatlar. SPA nav/UI shu asosda render qiladi.
 * SHARTNOMA: { profile:{id,name,level,district:{id,name}|null}, role, permissions[] }
 */
class MeController extends Controller
{
    public function __invoke(Request $request, MurojaatAccess $access): JsonResponse
    {
        $user = $request->user();
        $profile = $access->profileFor($user);
        $profile?->loadMissing('district:id,name_cyr');

        return response()->json([
            'profile' => $profile === null ? null : [
                'id' => $profile->id,
                'name' => $user->name,
                'level' => $profile->level,
                'district' => $profile->district === null ? null : [
                    'id' => $profile->district->id,
                    'name' => $profile->district->name_cyr,
                ],
            ],
            'role' => $access->roleFor($user),
            'permissions' => $access->permissionsFor($user),
        ]);
    }
}
