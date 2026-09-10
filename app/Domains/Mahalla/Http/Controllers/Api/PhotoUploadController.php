<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Controllers\Api;

use App\Domains\Mahalla\Http\Requests\StorePhotoRequest;
use App\Domains\Mahalla\Jobs\AnalyzePhotoJob;
use App\Domains\Mahalla\Models\House;
use App\Domains\Mahalla\Models\HousePhoto;
use App\Domains\Mahalla\Services\GeofenceService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class PhotoUploadController extends Controller
{
    public function __construct(private readonly GeofenceService $geofence)
    {
    }

    public function store(StorePhotoRequest $request, House $house): JsonResponse
    {
        $data = $request->validated();
        $type = $data['type'];
        $takenDate = isset($data['taken_date'])
            ? Carbon::parse($data['taken_date'])->toDateString()
            : now()->toDateString();

        $exists = HousePhoto::where('house_id', $house->id)
            ->where('taken_date', $takenDate)
            ->where('type', $type)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Бу сана учун '.($type === 'baseline' ? 'бошланғич' : 'кунлик').' расм аллақачон юкланган.',
            ], 422);
        }

        $distanceM = null;
        $geofenceOk = null;
        if ($house->lat !== null && $house->lng !== null) {
            $distanceM = round($this->geofence->distanceMeters(
                (float) $house->lat,
                (float) $house->lng,
                (float) $data['captured_lat'],
                (float) $data['captured_lng'],
            ), 2);
            $geofenceOk = $this->geofence->isWithin($distanceM);
        }

        $disk = (string) config('mahalla.photos_disk', 'local');
        $path = $request->file('image')->store("mahalla/photos/{$house->id}", $disk);

        $photo = HousePhoto::create([
            'house_id' => $house->id,
            'type' => $type,
            'image_path' => $path,
            'captured_lat' => $data['captured_lat'],
            'captured_lng' => $data['captured_lng'],
            'gps_accuracy_m' => $data['gps_accuracy_m'] ?? null,
            'distance_m' => $distanceM,
            'geofence_ok' => $geofenceOk,
            'taken_date' => $takenDate,
            'captured_at' => now(), // server vaqti (backdating himoyasi)
            'uploaded_by' => $request->user()->id,
            'device_info' => $data['device_info'] ?? null,
        ]);

        $house->update(['last_photo_date' => $takenDate]);

        if ($type === 'daily') {
            AnalyzePhotoJob::dispatch($photo->id);
        }

        return response()->json([
            'message' => 'Расм юкланди'.($geofenceOk === false ? ' (диққат: GPS уйдан узоқ)' : '').'.',
            'photo' => [
                'id' => $photo->id,
                'type' => $photo->type,
                'geofence_ok' => $geofenceOk,
                'distance_m' => $distanceM,
            ],
        ], 201);
    }
}
