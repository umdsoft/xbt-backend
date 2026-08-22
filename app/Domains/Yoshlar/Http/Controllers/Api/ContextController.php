<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Http\Controllers\Api;

use App\Domains\Yoshlar\Models\Organization;
use App\Domains\Yoshlar\Models\Sector;
use App\Domains\Yoshlar\Models\Youth;
use App\Domains\Yoshlar\Support\YoshlarAccess;
use App\Domains\Yoshlar\Support\YoshlarScope;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * `/api/yoshlar/context` — SPA ishga tushganda BIR marta chaqiriladi:
 * foydalanuvchi, roli, ruxsatlari, ko'rish doirasi va barcha spravochniklar.
 *
 * Ma'lumot ikkala alifboda (`name_lat` + `name_cyr`) beriladi: til rejimini
 * SPA tanlaydi, API javobi undan mustaqil (keshlash osonroq).
 */
class ContextController extends Controller
{
    public function __invoke(Request $request, YoshlarAccess $access, YoshlarScope $scope): JsonResponse
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
            // Navigatsiya nishonlari — SPA har sahifada qayta so'ramasin.
            'badges' => [
                'pending_youth' => $scope->applyYouth(Youth::query(), $user)
                    ->where('verification_status', 'pending')->count(),
            ],
            'reference' => [
                'districts' => DB::connection('master')->table('districts')
                    ->orderBy('sort_order')->get(['id', 'name_lat', 'name_cyr', 'soato_code'])->all(),
                'mahallas' => DB::connection('master')->table('mahallas')
                    ->where('is_active', true)->orderBy('sort_order')
                    ->get(['id', 'district_id', 'name_lat', 'name_cyr'])->all(),
                'sectors' => Sector::query()->where('is_active', true)
                    ->orderBy('sort_order')->get(['id', 'code', 'name_lat', 'name_cyr'])->all(),
                'organizations' => Organization::query()->where('is_active', true)
                    ->orderBy('name_lat')
                    ->get(['id', 'type', 'parent_id', 'district_id', 'sector_id', 'name_lat', 'name_cyr'])->all(),
                'education_statuses' => Youth::EDUCATION_STATUSES,
                'employment_statuses' => Youth::EMPLOYMENT_STATUSES,
                'registry_statuses' => Youth::REGISTRY_STATUSES,
                'organization_types' => Organization::TYPES,
                'roles' => YoshlarAccess::ROLE_NAMES,
            ],
        ]);
    }
}
