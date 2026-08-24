<?php

declare(strict_types=1);

namespace App\Domains\Hr\Services\Seating;

use App\Domains\Hr\Models\Venue;
use Illuminate\Support\Facades\Cache;

/**
 * Obyekt geometriyasi + qatorlar (frontend SVG uchun). O'rindiq (x,y) frontend'да
 * formula bilan hisoblanadi (bu yerda faqat seat_count + pitch + anchor + rotation).
 * Kesh: obyekt slug + updated_at (o'zgarganда avtomatik yangilanadi).
 */
final class PlanBuilder
{
    public function build(Venue $venue): array
    {
        $key = "hr:venue-plan:{$venue->slug}:".(int) ($venue->updated_at?->timestamp ?? 0);

        return Cache::remember($key, 3600, function () use ($venue): array {
            $venue->load(['sectors.seatRows']);

            $sectors = $venue->sectors->map(fn ($s) => [
                'id' => $s->id,
                'code' => $s->code,
                'label' => $s->label,
                'tier' => $s->tier,
                'anchor_x' => $s->anchor_x,
                'anchor_y' => $s->anchor_y,
                'rotation' => $s->rotation,
                'row_pitch' => $s->row_pitch,
                'seat_pitch' => $s->seat_pitch,
                'sort_order' => $s->sort_order,
                'seat_total' => (int) $s->seatRows->sum('seat_count'),
                'rows' => $s->seatRows->map(fn ($r) => [
                    'id' => $r->id,
                    'index' => $r->row_index,
                    'seat_count' => $r->seat_count,
                    'seat_start' => $r->seat_start,
                    'points' => $r->points_json,   // [[x,y],...] obyekt-lokal mm, yoki null (formula)
                ])->values()->all(),
            ])->values()->all();   // ->all() => oddiy massiv (kesh round-trip'dan keyin ham JSON array)

            return [
                'venue' => [
                    'id' => $venue->id,
                    'slug' => $venue->slug,
                    'name' => $venue->name,
                    'unit' => $venue->unit,
                    'capacity' => $venue->capacity_cached,
                ],
                'viewbox' => $venue->viewbox_json,
                'stage' => $venue->stage_json,
                'floor' => $venue->floor_json,   // [[[x,y],...],...] zinapoya/yo'lak konturlari
                'sectors' => $sectors,
                'totals' => [
                    'sectors' => count($sectors),
                    'rows' => array_sum(array_map(fn ($s) => count($s['rows']), $sectors)),
                    'seats' => (int) array_sum(array_column($sectors, 'seat_total')),
                ],
            ];
        });
    }
}
