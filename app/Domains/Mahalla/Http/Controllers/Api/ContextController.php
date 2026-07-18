<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Controllers\Api;

use App\Domains\Mahalla\Models\MahallaProfile;
use App\Domains\Mahalla\Models\Master\Street;
use App\Domains\Mahalla\Support\MahallaAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * mahalla domeni konteksti: foydalanuvchi roli, ruxsatlari, geo-scope (nomlar bilan).
 * SPA nav/UI'ni shu asosda render qiladi.
 */
class ContextController extends Controller
{
    public function __invoke(Request $request, MahallaAccess $access): JsonResponse
    {
        $user = $request->user();
        $profile = MahallaProfile::with(['district:id,name_cyr', 'mahalla:id,name_cyr'])->find($user->id);

        $streetIds = $profile ? $profile->streetAssignments()->pluck('street_id')->all() : [];
        $streets = $streetIds === []
            ? []
            : Street::query()
                ->whereIn('id', $streetIds)
                ->orderBy('sort_order')
                ->get(['id', 'name'])
                ->map(fn (Street $s) => ['id' => $s->id, 'name' => $s->name])
                ->all();

        return response()->json([
            'role' => $access->roleFor($user),
            'permissions' => $access->permissionsFor($user),
            'scope' => [
                'district' => $profile?->district ? [
                    'id' => $profile->district->id,
                    'name' => $profile->district->name_cyr,
                ] : null,
                'mahalla' => $profile?->mahalla ? [
                    'id' => $profile->mahalla->id,
                    'name' => $profile->mahalla->name_cyr,
                ] : null,
                'streets' => $streets,
            ],
            'geofence_radius_m' => (int) config('mahalla.geofence_radius_m'),
        ]);
    }
}
