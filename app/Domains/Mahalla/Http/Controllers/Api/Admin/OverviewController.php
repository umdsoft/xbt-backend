<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Controllers\Api\Admin;

use App\Domains\Mahalla\Models\House;
use App\Domains\Mahalla\Models\HousePhoto;
use App\Domains\Mahalla\Models\HousePhotoAnalysis;
use App\Domains\Mahalla\Models\Master\Mahalla;
use App\Domains\Mahalla\Models\Master\Street;
use App\Domains\Mahalla\Support\MahallaAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * ADMIN — tizim bo'yicha umumiy statistika (super-admin hammasini ko'radi).
 * Operatsion dashboard'dan farqli: bu yerda scope YO'Q — butun tizim ko'lami.
 */
class OverviewController extends Controller
{
    public function __invoke(): JsonResponse
    {
        // Operatsion (deputat-rol) userlar soni — mahalla tizimi, o'chirilmagan.
        $totalUsers = DB::connection('auth')->table('user_system_access as usa')
            ->join('systems as s', 's.id', '=', 'usa.system_id')
            ->join('users as u', 'u.id', '=', 'usa.user_id')
            ->where('s.code', MahallaAccess::SYSTEM_CODE)
            ->where('usa.role', 'deputat')
            ->whereNull('u.deleted_at')
            ->count();

        $byStatus = House::query()
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $totalHouses = (int) $byStatus->sum();
        $avgProgress = (float) (House::query()->avg('progress_percent') ?? 0);

        return response()->json([
            'stats' => [
                'total_users' => $totalUsers,
                'total_mahallas' => Mahalla::query()->count(),
                'total_streets' => Street::query()->count(),
                'total_houses' => $totalHouses,
                'houses_by_status' => [
                    'not_started' => (int) ($byStatus['not_started'] ?? 0),
                    'in_progress' => (int) ($byStatus['in_progress'] ?? 0),
                    'completed' => (int) ($byStatus['completed'] ?? 0),
                ],
                'overall_progress_percent' => (int) round($avgProgress),
                'total_photos' => HousePhoto::query()->count(),
                'flagged_count' => HousePhotoAnalysis::query()->where('decision', 'flagged')->count(),
            ],
        ]);
    }
}
