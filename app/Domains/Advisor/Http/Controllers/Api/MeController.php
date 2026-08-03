<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Http\Controllers\Api;

use App\Domains\Advisor\Support\AdvisorAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * advisor domeni konteksti: joriy maslahatchi profili, roli, ruxsatlari.
 * SPA nav/UI'ni shu asosda render qiladi (mahalla ContextController naqshi).
 *
 * SHARTNOMA (frontend tayanadi):
 *   { "advisor": {"id","name","level","district":{"id","name"}|null},
 *     "role": <string>, "permissions": <string[]> }
 */
class MeController extends Controller
{
    public function __invoke(Request $request, AdvisorAccess $access): JsonResponse
    {
        $user = $request->user();
        $advisor = $access->advisorFor($user);
        $advisor?->loadMissing('district:id,name_cyr');

        return response()->json([
            'advisor' => $advisor === null ? null : [
                'id' => $advisor->id,
                // Ism markaziy identifikatsiyadan (auth.users).
                'name' => $user->name,
                'level' => $advisor->level,
                'district' => $advisor->district === null ? null : [
                    'id' => $advisor->district->id,
                    'name' => $advisor->district->name_cyr,
                ],
            ],
            'role' => $access->roleFor($user),
            'permissions' => $access->permissionsFor($user),
        ]);
    }
}
