<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Controllers\Api\Admin;

use App\Domains\Mahalla\Models\HousePhoto;
use App\Domains\Mahalla\Models\HousePhotoAnalysis;
use App\Domains\Mahalla\Models\HouseZoneState;
use App\Domains\Mahalla\Services\HouseProvisioner;
use App\Domains\Mahalla\Support\MahallaZones;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * MASUL HODIM (admin) REVIEW navbati: AI ikkilangan yoki o'zgarishsiz deb belgilagan
 * (decision='flagged', hali ko'rilmagan) kuzatuvlar. Masul hodim tasdiqlaydi (holatni
 * o'rnatadi) yoki rad etadi.
 */
class ReviewController extends Controller
{
    public function __construct(private readonly HouseProvisioner $provisioner)
    {
    }

    /**
     * Ko'rilmagan flagged kuzatuvlar (paginatsiya) — rasm + honadon + zona + AI tahlili.
     */
    public function index(Request $request): JsonResponse
    {
        $q = HousePhotoAnalysis::query()
            ->where('decision', 'flagged')
            ->whereNull('reviewed_by')
            ->with(['photo.house:id,building_id,mahalla_id,street_id,address,cadastral_number'])
            ->orderBy('created_at');

        if ($request->filled('zone')) {
            $q->where('zone', $request->string('zone'));
        }

        $rows = $q->paginate(30);

        $uploaderIds = collect($rows->items())->map(fn ($a) => $a->photo?->uploaded_by)->filter()->unique()->all();
        $uploaders = User::whereIn('id', $uploaderIds)->pluck('name', 'id');

        $data = collect($rows->items())->map(function (HousePhotoAnalysis $a) use ($uploaders) {
            $p = $a->photo;

            return [
                'analysis_id' => $a->id,
                'photo_id' => $p?->id,
                'zone' => $a->zone,
                'zone_label' => MahallaZones::zoneLabel($a->zone),
                'house_id' => $p?->house_id,
                'building_id' => $p?->house?->building_id,
                'address' => $p?->house?->address,
                'photo_url' => $p ? route('api.mahalla.photos.show', $p->id) : null,
                'on_site' => $p?->geofence_ok,
                'distance_m' => $p?->distance_m,
                'captured_at' => $p?->captured_at?->toIso8601String(),
                'uploaded_by' => $uploaders[$p?->uploaded_by] ?? null,
                'prev_status' => $a->prev_status,
                'prev_status_label' => MahallaZones::statusLabel($a->prev_status),
                'suggested_status' => $a->suggested_status,
                'suggested_status_label' => MahallaZones::statusLabel($a->suggested_status),
                'ai_before' => $a->changes['before'] ?? null,
                'ai_after' => $a->changes['after'] ?? null,
                'ai_summary' => $a->daily_work,
                'ai_confidence' => $a->confidence,
                'reason' => $a->decision_reason,
            ];
        })->all();

        return response()->json([
            'data' => $data,
            'meta' => [
                'total' => $rows->total(),
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
            ],
        ]);
    }

    /**
     * Tasdiqlash: masul hodim yakuniy holatni o'rnatadi (yoki AI taklifini qabul qiladi).
     * Holat oldingidan farq qilsa — bu O'ZGARISH (zona holati + last_changed yangilanadi).
     */
    public function confirm(Request $request, HousePhoto $photo): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'status' => ['nullable', 'string', Rule::in(MahallaZones::statusCodes())],
            'note' => ['nullable', 'string', 'max:500'],
        ])->validate();

        $analysis = HousePhotoAnalysis::where('house_photo_id', $photo->id)->first();
        $state = HouseZoneState::firstOrCreate(
            ['house_id' => $photo->house_id, 'zone' => $photo->zone],
            ['status' => MahallaZones::DEFAULT_STATUS],
        );

        $prev = $state->status;
        $final = $data['status'] ?? $analysis?->suggested_status ?? $prev;
        if (! MahallaZones::isStatus((string) $final)) {
            $final = $prev;
        }
        $isChange = $final !== $prev;

        HousePhotoAnalysis::updateOrCreate(
            ['house_photo_id' => $photo->id],
            [
                'zone' => $photo->zone,
                'prev_status' => $prev,
                'suggested_status' => $final,
                'is_change' => $isChange,
                'decision' => 'auto_confirmed',
                'decision_reason' => trim(($analysis?->decision_reason ?? '').' | Масул ходим тасдиқлади'.(! empty($data['note']) ? ': '.$data['note'] : '')),
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ],
        );

        $upd = ['status' => $final];
        if ($isChange) {
            $upd['last_changed_at'] = now();
        }
        $state->update($upd);
        $this->provisioner->recomputeHouse($photo->house_id);

        return response()->json([
            'message' => 'Тасдиқланди.',
            'zone' => $photo->zone,
            'status' => $final,
            'status_label' => MahallaZones::statusLabel($final),
            'is_change' => $isChange,
        ]);
    }

    /**
     * Rad etish (yaroqsiz rasm / aldash). Zona holati o'zgarmaydi.
     */
    public function reject(Request $request, HousePhoto $photo): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'note' => ['nullable', 'string', 'max:500'],
        ])->validate();

        $analysis = HousePhotoAnalysis::where('house_photo_id', $photo->id)->first();

        HousePhotoAnalysis::updateOrCreate(
            ['house_photo_id' => $photo->id],
            [
                'zone' => $photo->zone,
                'is_change' => false,
                'decision' => 'rejected',
                'decision_reason' => trim(($analysis?->decision_reason ?? '').' | Масул ходим рад этди'.(! empty($data['note']) ? ': '.$data['note'] : '')),
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ],
        );

        return response()->json(['message' => 'Рад этилди.']);
    }
}
