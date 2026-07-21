<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Controllers\Api;

use App\Domains\Mahalla\Models\House;
use App\Domains\Mahalla\Support\MahallaAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class HouseController extends Controller
{
    public function __construct(private readonly MahallaAccess $access)
    {
    }

    /**
     * Honadonlar ro'yxati — KADASTR binolari asosida.
     *
     * DIQQAT: manba `master.buildings` (kadastr), operatsion `houses` jadvali
     * EMAS. `houses` dangasa to'ldiriladi — u faqat kuzatuv boshlanganda
     * (birinchi surat) yoziladi, shuning uchun deyarli bo'sh. Undan o'qish
     * "mahallaga bog'langan xonadonlar chiqmayapti" degan holatga olib kelardi:
     * mahallada 598 xonadon bor, jadval esa 1 tasini ko'rsatardi.
     *
     * Endi barcha turar-joy binosi ko'rinadi, kuzatuv holati (bor bo'lsa)
     * ustiga qo'yiladi.
     */
    public function index(Request $request): JsonResponse
    {
        $scope = $this->access->scopeFor($request->user());
        $today = now()->toDateString();

        $q = DB::connection('master')->table('buildings as b')
            ->leftJoin('streets as s', 's.id', '=', 'b.street_id')
            ->where('b.type', 'residential');

        // Qamrov: deputat — ko'chalar; rais — butun mahalla; admin/viloyat — hamma.
        if (! $scope->isAdmin && ! $scope->canSeeAll) {
            if ($scope->restrictToStreets) {
                if ($scope->streetIds === []) {
                    return response()->json(['houses' => [], 'today' => $today]);
                }
                $q->whereIn('b.street_id', $scope->streetIds);
            } elseif ($scope->mahallaId !== null) {
                $q->where('b.mahalla_id', $scope->mahallaId);
            } else {
                return response()->json(['houses' => [], 'today' => $today]);
            }
        }

        $buildings = $q->orderBy('s.name')->orderBy('b.house_number')
            ->limit(2000)
            ->get([
                'b.id', 'b.kadastr', 'b.address', 'b.house_number',
                's.name as street_name', 'b.mahalla_name',
            ]);

        // Kuzatuv holati operatsion `houses` dan — bino bo'yicha bog'lanadi.
        $houses = House::whereIn('building_id', $buildings->pluck('id'))
            ->get(['id', 'building_id', 'status', 'progress_percent', 'last_photo_date'])
            ->keyBy('building_id');

        $rows = $buildings->map(function ($b) use ($houses, $today) {
            $h = $houses->get($b->id);

            return [
                // Navigatsiya operatsion house'ga: kuzatuv boshlanmagan bo'lsa
                // `house_id` null — frontend uni "boshlanmagan" deb ko'rsatadi.
                'id' => $h?->id,
                'building_id' => $b->id,
                'address' => $b->address ?: trim(($b->street_name ?? '').' '.($b->house_number ?? '')),
                'cadastral_number' => $b->kadastr,
                'owner_name' => null,
                'status' => $h?->status ?? 'not_started',
                'progress_percent' => $h?->progress_percent ?? 0,
                'mahalla' => $b->mahalla_name,
                'street' => $b->street_name,
                'has_baseline' => $h !== null,
                'uploaded_today' => $h?->last_photo_date?->toDateString() === $today,
            ];
        });

        return response()->json([
            'houses' => $rows,
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
