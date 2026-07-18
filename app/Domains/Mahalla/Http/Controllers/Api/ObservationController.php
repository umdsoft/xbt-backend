<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Controllers\Api;

use App\Domains\Mahalla\Http\Requests\StoreObservationRequest;
use App\Domains\Mahalla\Jobs\AnalyzePhotoJob;
use App\Domains\Mahalla\Models\House;
use App\Domains\Mahalla\Models\HousePhoto;
use App\Domains\Mahalla\Models\HouseZoneState;
use App\Domains\Mahalla\Models\Master\Building;
use App\Domains\Mahalla\Services\GeofenceService;
use App\Domains\Mahalla\Services\HouseProvisioner;
use App\Domains\Mahalla\Support\MahallaZones;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * ZONA KUZATUVI (rasm) yuklash. Har upload DB'ga YOZIB BORILADI (progressiv tarix):
 *   - honadon yozuvi (binodan lazy) + 4 zona holati
 *   - house_photo (rasm + GPS + on-site) — kuzatuv yozuvi
 *   - zona holati last_observed/last_photo darhol yangilanadi (AI'gacha ham)
 *   - AI job navbatga: DB'dagi OLDINGI kuzatuv bilan solishtirib o'zgarishni aniqlaydi
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

        // 1) Honadon (binodan lazy) + zona holatlari
        $house = $this->provisioner->forBuilding($building);

        // 2) On-site: masofa <= 75m VA accuracy <= 100m
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

        // 3) Tur: shu zona bo'yicha birinchi rasm -> baseline, aks holda daily
        $isFirst = ! HousePhoto::where('house_id', $house->id)->where('zone', $zone)->exists();
        $type = $isFirst ? 'baseline' : 'daily';

        // 4) Rasmni maxfiy diskка saqlash
        $disk = (string) config('mahalla.photos_disk', 'local');
        $path = $request->file('image')->store("mahalla/photos/{$house->id}/{$zone}", $disk);

        // 5) Kuzatuv yozuvi (progressiv)
        $photo = HousePhoto::create([
            'house_id' => $house->id,
            'zone' => $zone,
            'type' => $type,
            'image_path' => $path,
            'captured_lat' => $data['captured_lat'],
            'captured_lng' => $data['captured_lng'],
            'gps_accuracy_m' => $data['gps_accuracy_m'] ?? null,
            'distance_m' => $distanceM,
            'geofence_ok' => $onSite,
            'taken_date' => now()->toDateString(),
            'captured_at' => now(), // server vaqti (backdating himoyasi)
            'uploaded_by' => $request->user()->id,
            'device_info' => $data['device_info'] ?? null,
        ]);

        // 6) Zona holati: kuzatuv darhol qayd etiladi (status AI tasdiqidan keyin o'zgaradi)
        HouseZoneState::where('house_id', $house->id)->where('zone', $zone)->update([
            'last_photo_id' => $photo->id,
            'last_observed_at' => $photo->captured_at,
        ]);
        $house->update(['last_photo_date' => $photo->taken_date]);

        // 7) AI tahlil (asinxron, robust queue) — DB'dagi oldingi kuzatuv bilan solishtiradi
        AnalyzePhotoJob::dispatch($photo->id);

        $state = HouseZoneState::where('house_id', $house->id)->where('zone', $zone)->first();

        return response()->json([
            'message' => 'Кузатув юкланди'.($onSite === false ? ' (диққат: GPS уйдан узоқ ёки аниқлик паст)' : '').'.',
            'observation' => [
                'id' => $photo->id,
                'zone' => $zone,
                'zone_label' => MahallaZones::zoneLabel($zone),
                'type' => $type,
                'on_site' => $onSite,
                'distance_m' => $distanceM,
                'captured_at' => $photo->captured_at?->toIso8601String(),
                'analysis_status' => 'pending', // AI navbatда
            ],
            'zone_state' => [
                'zone' => $zone,
                'status' => $state?->status,
                'status_label' => MahallaZones::statusLabel($state?->status),
            ],
        ], 201);
    }
}
