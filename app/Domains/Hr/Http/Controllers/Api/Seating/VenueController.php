<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Controllers\Api\Seating;

use App\Domains\Hr\Http\Controllers\Api\HrController;
use App\Domains\Hr\Models\SeatRow;
use App\Domains\Hr\Models\Sector;
use App\Domains\Hr\Models\Venue;
use App\Domains\Hr\Services\Seating\PlanBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Obyektlar (zallar) — GLOBAL ma'lumotnoma. Ko'rish `seating.view`; import va
 * kalibrlash `venues.manage` (route'да gating). Geometriya `plan` — keshlangan.
 */
class VenueController extends HrController
{
    public function index(): JsonResponse
    {
        $venues = Venue::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'uuid', 'name', 'slug', 'unit', 'capacity_cached', 'is_active']);

        return response()->json(['venues' => $venues]);
    }

    public function plan(string $slug, PlanBuilder $builder): JsonResponse
    {
        $venue = Venue::where('slug', $slug)->firstOrFail();

        return response()->json($builder->build($venue));
    }

    /**
     * Yangi obyekt import — parse qilingan sektor/qatorlardan (kod yozmasdan).
     * Boshlang'ich geometriya oddiy (gorizontal), keyin CalibrationEditor'да moslanadi.
     */
    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sectors' => ['required', 'array', 'min:1'],
            'sectors.*.code' => ['required', 'string', 'max:32'],
            'sectors.*.tier' => ['nullable', 'string', 'max:32'],
            'sectors.*.rows' => ['required', 'array', 'min:1'],
            'sectors.*.rows.*' => ['integer', 'min:1'],
        ]);

        $slug = $this->uniqueSlug($data['name']);
        $capacity = 0;
        foreach ($data['sectors'] as $s) {
            $capacity += (int) array_sum($s['rows']);
        }

        $venue = DB::connection('hr')->transaction(function () use ($data, $slug, $capacity) {
            $venue = Venue::create([
                'name' => $data['name'],
                'slug' => $slug,
                'unit' => 'mm',
                'capacity_cached' => $capacity,
                'is_active' => true,
                'notes' => 'Import wizard orqali qo\'shildi — geometriya CalibrationEditor bilan moslanadi.',
                'created_by' => $this->actor()->id,
            ]);

            foreach (array_values($data['sectors']) as $i => $s) {
                $sector = Sector::create([
                    'venue_id' => $venue->id,
                    'code' => (string) $s['code'],
                    'label' => $s['code'].'-SEKTOR',
                    'anchor_x' => $i * 14000,   // boshlang'ich gorizontal joylashuv
                    'anchor_y' => 0,
                    'rotation' => 0,
                    'row_pitch' => 1050,
                    'seat_pitch' => 550,
                    'tier' => $s['tier'] ?? null,
                    'sort_order' => $i + 1,
                ]);
                foreach (array_values($s['rows']) as $ri => $seatCount) {
                    SeatRow::create([
                        'sector_id' => $sector->id,
                        'row_index' => $ri + 1,
                        'seat_count' => (int) $seatCount,
                        'seat_start' => 1,
                    ]);
                }
            }

            return $venue;
        });

        return response()->json([
            'message' => 'Обект импорт қилинди.',
            'venue' => $venue->only(['id', 'slug', 'name', 'capacity_cached']),
        ], 201);
    }

    /** Sektor geometriyasini (anchor/rotation) + viewbox/stage yangilash (kalibrlash). */
    public function calibrate(Request $request, string $slug): JsonResponse
    {
        $venue = Venue::where('slug', $slug)->firstOrFail();

        $data = $request->validate([
            'sectors' => ['present', 'array'],
            'sectors.*.code' => ['required', 'string'],
            'sectors.*.anchor_x' => ['required', 'numeric'],
            'sectors.*.anchor_y' => ['required', 'numeric'],
            'sectors.*.rotation' => ['required', 'numeric'],
            'sectors.*.row_pitch' => ['nullable', 'numeric'],
            'sectors.*.seat_pitch' => ['nullable', 'numeric'],
            'viewbox_json' => ['nullable', 'array'],
            'stage_json' => ['nullable', 'array'],
        ]);

        DB::connection('hr')->transaction(function () use ($venue, $data) {
            foreach ($data['sectors'] as $s) {
                $venue->sectors()->where('code', $s['code'])->update(array_filter([
                    'anchor_x' => $s['anchor_x'],
                    'anchor_y' => $s['anchor_y'],
                    'rotation' => $s['rotation'],
                    'row_pitch' => $s['row_pitch'] ?? null,
                    'seat_pitch' => $s['seat_pitch'] ?? null,
                ], fn ($v) => $v !== null));
            }
            $venue->fill(array_filter([
                'viewbox_json' => $data['viewbox_json'] ?? null,
                'stage_json' => $data['stage_json'] ?? null,
            ], fn ($v) => $v !== null));
            $venue->touch(); // plan keshi (updated_at kaliti) yangilanadi
        });

        return response()->json(['message' => 'Геометрия сақланди.']);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'obekt';
        $slug = $base;
        $n = 2;
        while (Venue::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n;
            $n += 1;
        }

        return $slug;
    }
}
