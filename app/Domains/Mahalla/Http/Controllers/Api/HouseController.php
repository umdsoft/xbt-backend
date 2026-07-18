<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Controllers\Api;

use App\Domains\Mahalla\Models\House;
use App\Domains\Mahalla\Support\MahallaAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class HouseController extends Controller
{
    public function __construct(private readonly MahallaAccess $access)
    {
    }

    /**
     * Ish ro'yxati: ko'lamdagi honadonlar + bugungi holat.
     */
    public function index(Request $request): JsonResponse
    {
        $scope = $this->access->scopeFor($request->user());
        $today = now()->toDateString();

        $houses = House::query()
            ->visibleTo($scope)
            ->with(['mahalla:id,name_cyr', 'street:id,name'])
            ->withCount([
                'photos as has_baseline' => fn ($q) => $q->where('type', 'baseline'),
                'photos as uploaded_today' => fn ($q) => $q->where('type', 'daily')->where('taken_date', $today),
            ])
            ->orderBy('street_id')
            ->get()
            ->map(fn (House $h) => [
                'id' => $h->id,
                'address' => $h->address,
                'cadastral_number' => $h->cadastral_number,
                'owner_name' => $h->owner_name,
                'status' => $h->status,
                'progress_percent' => $h->progress_percent,
                'mahalla' => $h->mahalla?->name_cyr,
                'street' => $h->street?->name,
                'has_baseline' => $h->has_baseline > 0,
                'uploaded_today' => $h->uploaded_today > 0,
            ]);

        return response()->json([
            'houses' => $houses,
            'today' => $today,
        ]);
    }

    /**
     * Honadon: rasm tarixi + oxirgi tahlil.
     */
    public function show(Request $request, House $house): JsonResponse
    {
        $user = $request->user();
        $scope = $this->access->scopeFor($user);

        $visible = House::query()->visibleTo($scope)->whereKey($house->getKey())->exists();
        if (! $visible) {
            throw new NotFoundHttpException();
        }

        $house->load(['mahalla:id,name_cyr', 'street:id,name']);

        $photos = $house->photos()
            ->with('analysis')
            ->orderByDesc('taken_date')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'type' => $p->type,
                'url' => route('api.mahalla.photos.show', $p->id),
                'taken_date' => Carbon::parse($p->taken_date)->toDateString(),
                'geofence_ok' => $p->geofence_ok,
                'distance_m' => $p->distance_m,
                'analysis' => $p->analysis ? [
                    'decision' => $p->analysis->decision,
                    'decision_reason' => $p->analysis->decision_reason,
                    'same_house' => $p->analysis->same_house,
                    'confidence' => $p->analysis->confidence,
                    'cheating_suspected' => $p->analysis->cheating_suspected,
                    'daily_work' => $p->analysis->daily_work,
                    'progress_percent' => $p->analysis->progress_percent,
                ] : null,
            ]);

        return response()->json([
            'house' => [
                'id' => $house->id,
                'address' => $house->address,
                'cadastral_number' => $house->cadastral_number,
                'owner_name' => $house->owner_name,
                'lat' => $house->lat,
                'lng' => $house->lng,
                'status' => $house->status,
                'progress_percent' => $house->progress_percent,
                'mahalla' => $house->mahalla?->name_cyr,
                'street' => $house->street?->name,
                'has_baseline' => $house->photos()->where('type', 'baseline')->exists(),
            ],
            'photos' => $photos,
            'canUpload' => $this->access->can($user, 'photos.upload'),
            'geofenceRadius' => (int) config('mahalla.geofence_radius_m'),
        ]);
    }
}
