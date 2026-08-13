<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Http\Controllers\Api;

use App\Domains\Qurilish\Models\ConstructionObject;
use App\Domains\Qurilish\Models\ObjectStage;
use App\Domains\Qurilish\Models\Program;
use App\Domains\Qurilish\Models\Sector;
use App\Domains\Qurilish\Models\WeeklyReport;
use App\Domains\Qurilish\Support\QurilishAccess;
use App\Domains\Qurilish\Support\QurilishScope;
use App\Http\Controllers\Controller;
use App\Models\User;
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

    public function __invoke(Request $request, QurilishAccess $access, QurilishScope $scope): JsonResponse
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
            'role_name' => QurilishAccess::ROLE_NAMES[$access->roleFor($user)] ?? null,
            'permissions' => $access->permissionsFor($user),
            'sees_everything' => $access->seesEverything($user),
            'viewer_only' => $access->isViewerOnly($user),
            'scope' => [
                'organization_id' => $profile?->organization_id,
                'organization_name' => $organization?->name_cyr,
                'district_id' => $profile?->district_id,
            ],
            // Navigatsiya nishonlari — SPA har sahifada qayta so'ramasin.
            'badges' => $this->badges($user, $scope),
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

    /**
     * Nishon sonlari: nechta bosqich va nechta haftalik hisobot javob kutmoqda.
     *
     * Scope obyektlar so'roviga qo'llanadi — buyurtmachi o'zi yuborganini,
     * prokuratura hammasini ko'radi. Ikkala son ham BITTA kontekst so'rovidan
     * keladi: har sahifa ochilganda navbatni qayta so'rash dashboard
     * yuklanishini sekinlashtirardi.
     *
     * @return array<string, int>
     */
    private function badges(User $user, QurilishScope $scope): array
    {
        $visible = $scope->apply(ConstructionObject::query()->select('id'), $user);

        return [
            'stage_queue' => ObjectStage::query()
                ->whereIn('status', ObjectStage::PENDING_STATUSES)
                ->whereIn('object_id', $visible)
                ->count(),
            'weekly_queue' => WeeklyReport::query()
                ->whereIn('status', WeeklyReport::PENDING_STATUSES)
                ->whereIn('object_id', $visible)
                ->count(),
        ];
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
