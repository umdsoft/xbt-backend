<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Controllers\Api\Admin;

use App\Domains\Mahalla\Models\HouseZoneState;
use App\Domains\Mahalla\Models\ZoneObservation;
use App\Domains\Mahalla\Services\HouseProvisioner;
use App\Domains\Mahalla\Support\MahallaZones;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * MASUL HODIM (admin) REVIEW navbati: AI ikkilangan/o'zgarishsiz deb belgilagan
 * KUZATUVLAR (decision='flagged', ko'rilmagan). Har kuzatuv N ta rakursli.
 * Masul hodim tasdiqlaydi (holat o'rnatadi) yoki rad etadi.
 */
class ReviewController extends Controller
{
    public function __construct(private readonly HouseProvisioner $provisioner)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $q = ZoneObservation::query()
            ->where('decision', 'flagged')
            ->whereNull('reviewed_by')
            ->with(['house:id,building_id,mahalla_id,street_id,address', 'photos:id,observation_id,angle'])
            ->orderBy('observed_at');

        if ($request->filled('zone')) {
            $q->where('zone', $request->string('zone'));
        }

        $rows = $q->paginate(30);

        $userIds = collect($rows->items())->pluck('user_id')->filter()->unique()->all();
        $users = User::whereIn('id', $userIds)->pluck('name', 'id');

        $data = collect($rows->items())->map(function (ZoneObservation $o) use ($users) {
            $ai = $o->ai_result ?? [];

            return [
                'observation_id' => $o->id,
                'zone' => $o->zone,
                'zone_label' => MahallaZones::zoneLabel($o->zone),
                'house_id' => $o->house_id,
                'building_id' => $o->house?->building_id,
                'address' => $o->house?->address,
                'on_site' => $o->is_on_site,
                'distance_m' => $o->distance_m,
                'observed_at' => $o->observed_at?->toIso8601String(),
                'uploaded_by' => $users[$o->user_id] ?? null,
                'photo_count' => $o->photo_count,
                // Har rakurs uchun himoyalangan rasm URL'i
                'photos' => $o->photos->map(fn ($p) => [
                    'id' => $p->id,
                    'angle' => $p->angle,
                    'url' => route('api.mahalla.photos.show', $p->id),
                ])->values()->all(),
                'prev_status' => $o->prev_status,
                'prev_status_label' => MahallaZones::statusLabel($o->prev_status),
                'suggested_status' => $o->suggested_status,
                'suggested_status_label' => MahallaZones::statusLabel($o->suggested_status),
                'ai_before' => $ai['before'] ?? null,
                'ai_after' => $ai['after'] ?? null,
                'ai_summary' => $ai['change_description'] ?? null,
                'ai_confidence' => $o->confidence,
                'reason' => $o->decision_reason,
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
     * Tasdiqlash: masul hodim yakuniy holatni o'rnatadi. Holat oldingidan farq
     * qilsa — bu O'ZGARISH (zona holati + last_changed yangilanadi).
     */
    public function confirm(Request $request, ZoneObservation $observation): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'status' => ['nullable', 'string', Rule::in(MahallaZones::statusCodes())],
            'note' => ['nullable', 'string', 'max:500'],
        ])->validate();

        $state = HouseZoneState::firstOrCreate(
            ['house_id' => $observation->house_id, 'zone' => $observation->zone],
            ['status' => MahallaZones::DEFAULT_STATUS],
        );

        $prev = $state->status;
        $final = $data['status'] ?? $observation->suggested_status ?? $prev;
        if (! MahallaZones::isStatus((string) $final)) {
            $final = $prev;
        }
        $isChange = $final !== $prev;

        $observation->update([
            'prev_status' => $prev,
            'suggested_status' => $final,
            'status' => $final,
            'is_change' => $isChange,
            'decision' => 'auto_confirmed',
            'decision_reason' => trim(($observation->decision_reason ?? '').' | Масул ходим тасдиқлади'.(! empty($data['note']) ? ': '.$data['note'] : '')),
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        $upd = ['status' => $final, 'last_observation_id' => $observation->id];
        if ($isChange) {
            $upd['last_changed_at'] = now();
        }
        $state->update($upd);
        $this->provisioner->recomputeHouse($observation->house_id);

        return response()->json([
            'message' => 'Тасдиқланди.',
            'zone' => $observation->zone,
            'status' => $final,
            'status_label' => MahallaZones::statusLabel($final),
            'is_change' => $isChange,
        ]);
    }

    public function reject(Request $request, ZoneObservation $observation): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'note' => ['nullable', 'string', 'max:500'],
        ])->validate();

        $observation->update([
            'is_change' => false,
            'decision' => 'rejected',
            'decision_reason' => trim(($observation->decision_reason ?? '').' | Масул ходим рад этди'.(! empty($data['note']) ? ': '.$data['note'] : '')),
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return response()->json(['message' => 'Рад этилди.']);
    }
}
