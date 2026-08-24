<?php

declare(strict_types=1);

namespace App\Domains\Hr\Services\Seating;

use App\Domains\Hr\Models\Event;

/**
 * Tadbir sig'imi — guruh bo'yicha belgilangan o'rindiqlar + bo'sh o'rindiqlar +
 * rejaga nisbatan farq. Butun-sektor belgilashi (seat_row_id=null) sektorning
 * barcha qatorlari yig'indisini beradi.
 */
final class CapacityCalculator
{
    public function forEvent(Event $event): array
    {
        $event->loadMissing(['groups', 'venue', 'allocations.seatRow', 'allocations.sector.seatRows']);

        $byGroup = [];
        $assigned = 0;
        foreach ($event->allocations as $a) {
            $seats = $a->seat_row_id === null
                ? (int) ($a->sector?->seatRows->sum('seat_count') ?? 0)
                : (int) ($a->seatRow?->seat_count ?? 0);
            $byGroup[$a->event_group_id] = ($byGroup[$a->event_group_id] ?? 0) + $seats;
            $assigned += $seats;
        }

        $capacity = (int) ($event->venue?->capacity_cached ?? 0);

        $groups = $event->groups->map(function ($g) use ($byGroup) {
            $a = $byGroup[$g->id] ?? 0;

            return [
                'id' => $g->id,
                'name' => $g->name,
                'color' => $g->color,
                'expected' => $g->expected_count,
                'assigned' => $a,
                'diff' => $g->expected_count !== null ? $a - $g->expected_count : null,
            ];
        })->values();

        return [
            'capacity' => $capacity,
            'assigned' => $assigned,
            'unassigned' => max(0, $capacity - $assigned),
            'groups' => $groups,
        ];
    }
}
