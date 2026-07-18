<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Controllers\Api\Admin;

use App\Domains\Mahalla\Models\House;
use App\Domains\Mahalla\Models\HouseZoneState;
use App\Domains\Mahalla\Models\ZoneObservation;
use App\Domains\Mahalla\Support\MahallaZones;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * HISOBOTLAR — zona holati taqsimoti, o'zgarish dinamikasi, deputat faolligi.
 * Filtrlar: mahalla_id, district_id. O'zgarishlar (holat o'tishlari) asos qilinadi,
 * rasmlar soni emas.
 */
class ReportsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $mahallaId = $request->string('mahalla_id')->toString() ?: null;
        $districtId = $request->string('district_id')->toString() ?: null;

        return response()->json([
            'summary' => $this->summary($mahallaId, $districtId),
            'zones' => $this->zoneDistribution($mahallaId, $districtId),
            'changes_daily' => $this->changeDynamics($mahallaId, $districtId),
            'deputies' => $this->deputyActivity($mahallaId, $districtId),
        ]);
    }

    /**
     * Honadonlarga filtr (mahalla/tuman) qo'llaydigan yordamchi.
     */
    private function houseFilter($query, ?string $mahallaId, ?string $districtId, string $col = 'house_id')
    {
        if ($mahallaId === null && $districtId === null) {
            return $query;
        }

        return $query->whereIn($col, function ($sub) use ($mahallaId, $districtId) {
            $sub->from('houses')->select('id');
            if ($mahallaId !== null) {
                $sub->where('mahalla_id', $mahallaId);
            }
            if ($districtId !== null) {
                $sub->where('district_id', $districtId);
            }
        });
    }

    /**
     * @return array<string, int>
     */
    private function summary(?string $mahallaId, ?string $districtId): array
    {
        $houses = House::query();
        if ($mahallaId !== null) {
            $houses->where('mahalla_id', $mahallaId);
        }
        if ($districtId !== null) {
            $houses->where('district_id', $districtId);
        }

        $obs = $this->houseFilter(ZoneObservation::query(), $mahallaId, $districtId);

        return [
            'monitored_houses' => (clone $houses)->count(),
            'observations' => (clone $obs)->count(),
            'changes' => (clone $obs)->where('is_change', true)->count(),
            'pending_reviews' => ZoneObservation::where('decision', 'flagged')->whereNull('reviewed_by')->count(),
        ];
    }

    /**
     * Zona x holat taqsimoti (nechta honadon zonasi qaysi holatда).
     *
     * @return array<int, array<string, mixed>>
     */
    private function zoneDistribution(?string $mahallaId, ?string $districtId): array
    {
        $rows = $this->houseFilter(HouseZoneState::query(), $mahallaId, $districtId)
            ->selectRaw('zone, status, count(*) as c')
            ->groupBy('zone', 'status')
            ->get();

        $out = [];
        foreach (MahallaZones::ZONES as $zone => $zoneLabel) {
            $statuses = [];
            foreach (MahallaZones::STATUSES as $st => $stLabel) {
                $statuses[] = [
                    'status' => $st,
                    'status_label' => $stLabel,
                    'count' => (int) ($rows->firstWhere(fn ($r) => $r->zone === $zone && $r->status === $st)->c ?? 0),
                ];
            }
            $out[] = ['zone' => $zone, 'zone_label' => $zoneLabel, 'statuses' => $statuses];
        }

        return $out;
    }

    /**
     * O'zgarishlar dinamikasi — oxirgi 30 kun, kunlik holat o'tishlari soni.
     *
     * @return array<int, array<string, mixed>>
     */
    private function changeDynamics(?string $mahallaId, ?string $districtId): array
    {
        $q = ZoneObservation::query()
            ->where('is_change', true)
            ->where('observed_at', '>=', now()->subDays(30));

        $q = $this->houseFilter($q, $mahallaId, $districtId);

        return $q->selectRaw("to_char(observed_at, 'YYYY-MM-DD') as d, count(*) as c")
            ->groupBy('d')
            ->orderBy('d')
            ->get()
            ->map(fn ($r) => ['date' => $r->d, 'count' => (int) $r->c])
            ->all();
    }

    /**
     * Deputat faolligi — kuzatuvlar soni + on-site foizi.
     *
     * @return array<int, array<string, mixed>>
     */
    private function deputyActivity(?string $mahallaId, ?string $districtId): array
    {
        $q = $this->houseFilter(ZoneObservation::query(), $mahallaId, $districtId);

        $rows = $q->selectRaw('user_id, count(*) as total, count(*) filter (where is_on_site is true) as onsite')
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->orderByDesc(DB::raw('count(*)'))
            ->limit(50)
            ->get();

        $names = User::whereIn('id', $rows->pluck('user_id')->all())->pluck('name', 'id');

        return $rows->map(fn ($r) => [
            'user' => $names[$r->user_id] ?? '—',
            'observations' => (int) $r->total,
            'on_site' => (int) $r->onsite,
            'on_site_percent' => $r->total > 0 ? round(100 * $r->onsite / $r->total) : 0,
        ])->all();
    }
}
