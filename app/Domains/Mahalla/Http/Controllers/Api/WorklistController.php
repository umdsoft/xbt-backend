<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Controllers\Api;

use App\Domains\Mahalla\Models\House;
use App\Domains\Mahalla\Models\HousePhoto;
use App\Domains\Mahalla\Models\HouseZoneState;
use App\Domains\Mahalla\Models\Master\Building;
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
            $photos = HousePhoto::where('house_id', $house->id)
                ->with('analysis')
                ->orderByDesc('captured_at')
                ->limit(60)
                ->get();

            foreach ($photos as $p) {
                $a = $p->analysis;
                $obs = [
                    'id' => $p->id,
                    'zone' => $p->zone,
                    'zone_label' => MahallaZones::zoneLabel($p->zone),
                    'type' => $p->type,
                    'on_site' => $p->geofence_ok,
                    'distance_m' => $p->distance_m,
                    'captured_at' => $p->captured_at?->toIso8601String(),
                    'photo_url' => route('api.mahalla.photos.show', $p->id),
                    'analysis' => $a ? [
                        'decision' => $a->decision,           // auto_confirmed | flagged | pending | rejected
                        'is_change' => (bool) $a->is_change,
                        'prev_status' => $a->prev_status,
                        'suggested_status' => $a->suggested_status,
                        'before' => $a->changes['before'] ?? null,
                        'after' => $a->changes['after'] ?? null,
                        'summary' => $a->daily_work,
                        'confidence' => $a->confidence,
                        'reason' => $a->decision_reason,
                    ] : null,
                ];
                $observations[] = $obs;

                // O'zgarishlar tarixi = is_change bo'lgan kuzatuvlar
                if ($a !== null && $a->is_change) {
                    $changes[] = [
                        'zone' => $p->zone,
                        'zone_label' => MahallaZones::zoneLabel($p->zone),
                        'from' => $a->prev_status,
                        'to' => $a->suggested_status,
                        'description' => $a->daily_work,
                        'at' => $p->captured_at?->toIso8601String(),
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
