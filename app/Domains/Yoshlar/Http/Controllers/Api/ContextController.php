<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Http\Controllers\Api;

use App\Domains\Yoshlar\Support\YoshlarAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/api/yoshlar/context` — SPA ishga tushganda BIR marta chaqiriladi:
 * foydalanuvchi, roli, ruxsatlari va ko'rish doirasi.
 * Spravochnik va nishonlar keyingi bosqichda qo'shiladi.
 */
class ContextController extends Controller
{
    public function __invoke(Request $request, YoshlarAccess $access): JsonResponse
    {
        $user = $request->user();
        $staff = $access->staffFor($user);
        $org = $staff?->organization;

        return response()->json([
            'user' => ['id' => $user->id, 'name' => $user->name, 'login' => $user->login],
            'role' => $access->roleFor($user),
            'role_name' => YoshlarAccess::ROLE_NAMES[$access->roleFor($user)] ?? null,
            'permissions' => $access->permissionsFor($user),
            'sees_everything' => $access->seesEverything($user),
            'viewer_only' => $access->isViewerOnly($user),
            'scope' => [
                'org_id' => $staff?->org_id,
                'org_name' => $org?->name_lat,
                'org_type' => $org?->type,
                'district_id' => $org?->district_id,
                'sector_id' => $org?->sector_id,
                'can_patronage' => (bool) $staff?->can_patronage,
            ],
        ]);
    }
}
