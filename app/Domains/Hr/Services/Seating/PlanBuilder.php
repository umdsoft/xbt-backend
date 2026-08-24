<?php

declare(strict_types=1);

namespace App\Domains\Hr\Services\Seating;

use App\Domains\Hr\Models\Venue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Obyekt reja — aniq o'rindiq (x,y) DWG'dan (formula YO'Q). Frontend 1:1 chizadi.
 * Kesh: slug + updated_at.
 */
final class PlanBuilder
{
    public function build(Venue $venue): array
    {
        $key = "hr:venue-plan2:{$venue->slug}:".(int) ($venue->updated_at?->timestamp ?? 0);

        return Cache::remember($key, 3600, function () use ($venue): array {
            $sectors = DB::connection('hr')->table('sectors')->where('venue_id', $venue->id)
                ->orderBy('sort_order')->get(['id', 'code', 'label', 'color'])
                ->map(fn ($s) => ['id' => $s->id, 'code' => $s->code, 'label' => $s->label, 'color' => $s->color])
                ->all();

            $clusters = DB::connection('hr')->table('row_clusters')->where('venue_id', $venue->id)
                ->get(['id', 'code', 'angle', 'seat_count', 'centroid_x', 'centroid_y'])
                ->map(fn ($c) => [
                    'id' => $c->id, 'code' => $c->code, 'angle' => (float) $c->angle,
                    'seat_count' => (int) $c->seat_count,
                    'cx' => (float) $c->centroid_x, 'cy' => (float) $c->centroid_y,
                ])->all();

            $seats = DB::connection('hr')->table('seats')->where('venue_id', $venue->id)
                ->get(['id', 'code', 'x', 'y', 'rotation', 'seat_group', 'sector_id', 'row_cluster_id', 'row_label', 'seat_label', 'is_mirrored'])
                ->map(fn ($s) => [
                    'id' => $s->id, 'code' => $s->code,
                    'x' => (float) $s->x, 'y' => (float) $s->y, 'rot' => (float) $s->rotation,
                    'g' => $s->seat_group, 'sid' => $s->sector_id, 'rc' => $s->row_cluster_id,
                    'rl' => $s->row_label, 'sl' => $s->seat_label, 'm' => (bool) $s->is_mirrored,
                ])->all();

            return [
                'venue' => [
                    'id' => $venue->id, 'slug' => $venue->slug, 'name' => $venue->name,
                    'unit' => $venue->unit, 'capacity' => $venue->capacity_cached,
                ],
                'bbox' => $venue->bbox_json,
                'mirror_axis' => $venue->mirror_axis_json,
                'stage' => $venue->stage_json,
                'sectors' => $sectors,
                'row_clusters' => $clusters,
                'seats' => $seats,
                'totals' => ['seats' => count($seats), 'clusters' => count($clusters), 'sectors' => count($sectors)],
            ];
        });
    }
}
