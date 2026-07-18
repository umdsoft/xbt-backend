<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Controllers\Api;

use App\Domains\Mahalla\Models\House;
use App\Domains\Mahalla\Models\HouseZoneState;
use App\Domains\Mahalla\Models\Master\Building;
use App\Domains\Mahalla\Models\ZoneObservation;
use App\Domains\Mahalla\Support\MahallaAccess;
use App\Domains\Mahalla\Support\MahallaZones;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * WORKLIST — deputat o'z ko'chalaridagi turar binolar (kadastr) ro'yxati + monitoring
 * holati. Bino tafsiloti: 4 zona holati + kuzatuv/o'zgarish tarixi (progressiv yozuv).
 */
class WorklistController extends Controller
{
    /**
     * Deputat ko'chalaridagi binolar (paginatsiya) + zona holatlari qisqacha.
     */
    public function index(Request $request): JsonResponse
    {
        $scope = app(MahallaAccess::class)->scopeFor($request->user());

        $q = Building::query()->where('type', 'residential')->whereNotNull('street_id');

        if (! $scope->isAdmin) {
            if ($scope->streetIds === []) {
                return response()->json(['data' => [], 'meta' => ['total' => 0, 'current_page' => 1, 'last_page' => 1]]);
            }
            $q->whereIn('street_id', $scope->streetIds);
        }

        if ($request->filled('street_id')) {
            $q->where('street_id', $request->string('street_id'));
        }
        if ($request->filled('mahalla_id')) {
            $q->where('mahalla_id', $request->string('mahalla_id'));
        }
        if ($request->filled('q')) {
            $term = '%'.$request->string('q').'%';
            $q->where(fn ($w) => $w
                ->where('address', 'ilike', $term)
                ->orWhere('house_number', 'ilike', $term)
                ->orWhere('kadastr', 'ilike', $term)
                ->orWhere('street', 'ilike', $term));
        }

        $buildings = $q->orderBy('street_id')->orderBy('house_number')
            ->paginate(30, ['id', 'kadastr', 'address', 'house_number', 'street', 'street_id', 'mahalla_id', 'mahalla_name', 'lat', 'lng']);

        $ids = collect($buildings->items())->pluck('id')->all();
        $houses = House::whereIn('building_id', $ids)->with('zoneStates')->get()->keyBy('building_id');

        $data = collect($buildings->items())->map(function (Building $b) use ($houses) {
            $house = $houses->get($b->id);

            return [
                'building_id' => $b->id,
                'house_id' => $house?->id,
                'kadastr' => $b->kadastr,
                'address' => $b->address,
                'house_number' => $b->house_number,
                'street' => $b->street,
                'mahalla' => $b->mahalla_name,
                'lat' => $b->lat,
                'lng' => $b->lng,
                'monitored' => $house !== null,
                'overall_status' => $house?->status,
                'last_photo_date' => $house?->last_photo_date?->toDateString(),
                'zones' => $this->zoneSummary($house),
            ];
        })->all();

        return response()->json([
            'data' => $data,
            'meta' => [
                'total' => $buildings->total(),
                'current_page' => $buildings->currentPage(),
                'last_page' => $buildings->lastPage(),
                'per_page' => $buildings->perPage(),
            ],
        ]);
    }

    /**
     * Bino tafsiloti: 4 zona holati + kuzatuv/o'zgarish tarixi.
     */
    public function show(Request $request, Building $building): JsonResponse
    {
        $this->authorizeBuilding($request, $building);

        $house = House::where('building_id', $building->id)->with('zoneStates')->first();

        $observations = [];
        $changes = [];
        if ($house !== null) {
            $rows = ZoneObservation::where('house_id', $house->id)
                ->with('photos:id,observation_id,angle')
                ->orderByDesc('observed_at')
                ->limit(40)
                ->get();

            foreach ($rows as $o) {
                $ai = $o->ai_result ?? [];
                $observations[] = [
                    'id' => $o->id,
                    'zone' => $o->zone,
                    'zone_label' => MahallaZones::zoneLabel($o->zone),
                    'photo_count' => $o->photo_count,
                    'on_site' => $o->is_on_site,
                    'distance_m' => $o->distance_m,
                    'observed_at' => $o->observed_at?->toIso8601String(),
                    // Har rakurs uchun himoyalangan rasm URL'i
                    'photos' => $o->photos->map(fn ($p) => [
                        'id' => $p->id,
                        'angle' => $p->angle,
                        'url' => route('api.mahalla.photos.show', $p->id),
                    ])->values()->all(),
                    'analysis' => [
                        'decision' => $o->decision,
                        'is_change' => (bool) $o->is_change,
                        'prev_status' => $o->prev_status,
                        'suggested_status' => $o->suggested_status,
                        'before' => $ai['before'] ?? null,
                        'after' => $ai['after'] ?? null,
                        'summary' => $ai['change_description'] ?? null,
                        'confidence' => $o->confidence,
                        'reason' => $o->decision_reason,
                    ],
                ];

                if ($o->is_change) {
                    $changes[] = [
                        'zone' => $o->zone,
                        'zone_label' => MahallaZones::zoneLabel($o->zone),
                        'from' => $o->prev_status,
                        'to' => $o->suggested_status,
                        'description' => $ai['change_description'] ?? null,
                        'at' => $o->observed_at?->toIso8601String(),
                    ];
                }
            }
        }

        return response()->json([
            'building' => [
                'id' => $building->id,
                'kadastr' => $building->kadastr,
                'address' => $building->address,
                'house_number' => $building->house_number,
                'street' => $building->street,
                'mahalla' => $building->mahalla_name,
                'lat' => $building->lat,
                'lng' => $building->lng,
            ],
            'house_id' => $house?->id,
            'monitored' => $house !== null,
            'overall_status' => $house?->status,
            'zones' => $this->zoneSummary($house),
            'observations' => $observations,
            'changes' => $changes,
        ]);
    }

    /**
     * Deputat faqat o'z ko'chalaridagi binoni ko'ra oladi.
     */
    private function authorizeBuilding(Request $request, Building $building): void
    {
        $scope = app(MahallaAccess::class)->scopeFor($request->user());
        if ($scope->isAdmin) {
            return;
        }
        if ($building->street_id === null || ! in_array($building->street_id, $scope->streetIds, true)) {
            throw new NotFoundHttpException('Бино топилмади ёки рухсат йўқ.');
        }
    }

    /**
     * 4 zona holati (honadon bo'lmasa — default). Har doim 4 element.
     *
     * @return array<int, array<string, mixed>>
     */
    private function zoneSummary(?House $house): array
    {
        $states = $house
            ? $house->zoneStates->keyBy('zone')
            : collect();

        $out = [];
        foreach (MahallaZones::ZONES as $code => $label) {
            /** @var HouseZoneState|null $s */
            $s = $states->get($code);
            $status = $s?->status ?? MahallaZones::DEFAULT_STATUS;
            $out[] = [
                'zone' => $code,
                'zone_label' => $label,
                'status' => $status,
                'status_label' => MahallaZones::statusLabel($status),
                'last_observed_at' => $s?->last_observed_at?->toIso8601String(),
                'last_changed_at' => $s?->last_changed_at?->toIso8601String(),
            ];
        }

        return $out;
    }
}
