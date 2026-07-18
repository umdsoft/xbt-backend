<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Controllers\Api;

use App\Domains\Mahalla\Http\Requests\StoreObservationRequest;
use App\Domains\Mahalla\Jobs\AnalyzeObservationJob;
use App\Domains\Mahalla\Models\HousePhoto;
use App\Domains\Mahalla\Models\HouseZoneState;
use App\Domains\Mahalla\Models\Master\Building;
use App\Domains\Mahalla\Models\ZoneObservation;
use App\Domains\Mahalla\Services\GeofenceService;
use App\Domains\Mahalla\Services\HouseProvisioner;
use App\Domains\Mahalla\Support\MahallaZones;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ZONA KUZATUVI — bir zonani BIR NECHTA RAKURSdan suratga olib, BIR kuzatuv sifatida
 * yuklaydi. Har upload DB'ga progressiv yozuv; AI bu kuzatuvni (barcha rakurslar)
 * OLDINGI kuzatuv bilan solishtirib o'zgarishni aniqlaydi.
 */
class ObservationController extends Controller
{
    public function __construct(
        private readonly GeofenceService $geofence,
        private readonly HouseProvisioner $provisioner,
    ) {
    }

    public function store(StoreObservationRequest $request, Building $building): JsonResponse
    {
        $data = $request->validated();
        $zone = $data['zone'];
        $images = $request->file('images');

        $house = $this->provisioner->forBuilding($building);

        // On-site: masofa <= 75m VA accuracy <= 100m (kuzatuv-darajasi, bitta joy)
        $distanceM = null;
        $onSite = null;
        if ($house->lat !== null && $house->lng !== null) {
            $distanceM = round($this->geofence->distanceMeters(
                (float) $house->lat,
                (float) $house->lng,
                (float) $data['captured_lat'],
                (float) $data['captured_lng'],
            ), 2);
            $accuracy = isset($data['gps_accuracy_m']) ? (float) $data['gps_accuracy_m'] : null;
            $maxAcc = (int) config('mahalla.gps_accuracy_max_m', 100);
            $onSite = $this->geofence->isWithin($distanceM)
                && ($accuracy === null || $accuracy <= $maxAcc);
        }

        // Birinchi kuzatuv (shu zona) -> baseline, aks holda daily (rasm turi legacy).
        $isFirst = ! ZoneObservation::where('house_id', $house->id)->where('zone', $zone)->exists();
        $type = $isFirst ? 'baseline' : 'daily';
        $disk = (string) config('mahalla.photos_disk', 'local');

        $observation = DB::connection('mahalla')->transaction(function () use (
            $house, $zone, $images, $data, $distanceM, $onSite, $type, $disk, $request
        ) {
            $obs = ZoneObservation::create([
                'house_id' => $house->id,
                'zone' => $zone,
                'user_id' => $request->user()->id,
                'observed_at' => now(),
                'lat' => $data['captured_lat'],
                'lng' => $data['captured_lng'],
                'gps_accuracy_m' => $data['gps_accuracy_m'] ?? null,
                'distance_m' => $distanceM,
                'is_on_site' => $onSite,
                'photo_count' => count($images),
                'decision' => 'pending',
            ]);

            $angle = 1;
            foreach ($images as $image) {
                $path = $image->store("mahalla/photos/{$house->id}/{$zone}", $disk);
                HousePhoto::create([
                    'id' => (string) Str::uuid(),
                    'house_id' => $house->id,
                    'observation_id' => $obs->id,
                    'zone' => $zone,
                    'angle' => $angle,
                    'type' => $type,
                    'image_path' => $path,
                    'captured_lat' => $data['captured_lat'],
                    'captured_lng' => $data['captured_lng'],
                    'gps_accuracy_m' => $data['gps_accuracy_m'] ?? null,
                    'distance_m' => $distanceM,
                    'geofence_ok' => $onSite,
                    'taken_date' => now()->toDateString(),
                    'captured_at' => now(),
                    'uploaded_by' => $request->user()->id,
                    'device_info' => $data['device_info'] ?? null,
                ]);
                $angle++;
            }

            // Zona holati: kuzatuv darhol qayd etiladi (status AI/masul hodimdan keyin)
            HouseZoneState::where('house_id', $house->id)->where('zone', $zone)->update([
                'last_observation_id' => $obs->id,
                'last_observed_at' => $obs->observed_at,
            ]);
            $house->update(['last_photo_date' => now()->toDateString()]);

            return $obs;
        });

        // AI tahlil (asinxron, robust queue) — oldingi kuzatuv bilan solishtiradi
        AnalyzeObservationJob::dispatch($observation->id);

        $state = HouseZoneState::where('house_id', $house->id)->where('zone', $zone)->first();

        return response()->json([
            'message' => 'Кузатув юкланди ('.count($images).' ракурс)'.($onSite === false ? ' (диққат: GPS уйдан узоқ ёки аниқлик паст)' : '').'.',
            'observation' => [
                'id' => $observation->id,
                'zone' => $zone,
                'zone_label' => MahallaZones::zoneLabel($zone),
                'photo_count' => count($images),
                'on_site' => $onSite,
                'distance_m' => $distanceM,
                'observed_at' => $observation->observed_at?->toIso8601String(),
                'analysis_status' => 'pending',
            ],
            'zone_state' => [
                'zone' => $zone,
                'status' => $state?->status,
                'status_label' => MahallaZones::statusLabel($state?->status),
            ],
        ], 201);
    }
}
