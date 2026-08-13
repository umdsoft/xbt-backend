<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Http\Controllers\Api;

use App\Domains\Qurilish\Models\ConstructionObject;
use App\Domains\Qurilish\Models\ObjectStage;
use App\Domains\Qurilish\Models\Program;
use App\Domains\Qurilish\Models\Sector;
use App\Domains\Qurilish\Support\QurilishAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * `/api/qurilish/context` — SPA ishga tushganda BIR marta chaqiriladi:
 * foydalanuvchi, roli, ruxsatlari, ko'rish doirasi va barcha spravochniklar.
 *
 * Ma'lumot KIRILL alifbosida (`name_cyr`) — foydalanuvchi qarori (2026-08-13).
 * Lotin ustunlari (`name_lat`) bazada saqlanadi: qidiruv ikkala yozuvda ishlaydi.
 */
class ContextController extends Controller
{
    /** Bosqich kodi -> nomi (SPA'da alohida tarjima jadvali kerak emas). */
    private const STAGE_NAMES = [
        'designer_selection' => 'Лойиҳачини аниқлаш',
        'design_estimate' => 'Лойиҳа-смета ҳужжатлари',
        'urban_planning' => 'Шаҳарсозлик ҳужжатлари экспертизаси',
        'complex_expertise' => 'Комплекс экспертиза',
        'tender' => 'Тендер савдолари',
        'contract' => 'Шартнома',
        'execution' => 'Ижро',
        'handover' => 'Топшириш',
    ];

    public function __invoke(Request $request, QurilishAccess $access): JsonResponse
    {
        $user = $request->user();
        $profile = $access->profileFor($user);
        $organization = $profile?->organization;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'login' => $user->login,
            ],
            'role' => $access->roleFor($user),
            'permissions' => $access->permissionsFor($user),
            'sees_everything' => $access->seesEverything($user),
            'viewer_only' => $access->isViewerOnly($user),
            'scope' => [
                'organization_id' => $profile?->organization_id,
                'organization_name' => $organization?->name_cyr,
                'district_id' => $profile?->district_id,
            ],
            'reference' => [
                'programs' => Program::query()->where('is_active', true)
                    ->orderBy('sort_order')
                    ->get(['id', 'code', 'name_cyr as name'])->all(),
                'sectors' => Sector::query()->where('is_active', true)
                    ->orderBy('sort_order')
                    ->get(['id', 'code', 'name_cyr as name'])->all(),
                'districts' => DB::connection('master')->table('districts')
                    ->orderBy('sort_order')
                    ->get(['id', 'name_cyr as name', 'soato_code'])->all(),
                'stages' => $this->stages(),
                'stage_statuses' => ObjectStage::STATUSES,
                'lifecycles' => ConstructionObject::LIFECYCLES,
                'work_types' => ConstructionObject::WORK_TYPES,
            ],
        ]);
    }

    /** @return array<int, array{code: string, order: int, name: string}> */
    private function stages(): array
    {
        $out = [];
        foreach (ConstructionObject::STAGES as $i => $code) {
            $out[] = ['code' => $code, 'order' => $i + 1, 'name' => self::STAGE_NAMES[$code]];
        }

        return $out;
    }
}
