<?php

declare(strict_types=1);

namespace App\Domains\Hr\Services\Seating;

use App\Domains\Hr\Models\Event;
use App\Domains\Hr\Models\Seat;

/**
 * Tadbir sig'imi — guruh bo'yicha belgilangan o'rindiqlar + bo'sh o'rindiqlar +
 * rejaga nisbatan farq. Belgilash klasterga ishora qilsa — shu klaster o'rindiqlari;
 * row_cluster_id=null bo'lsa — butun sektor o'rindiqlari. Sig'im = obyekt o'rindiqlari soni.
 */
final class CapacityCalculator
{
    public function forEvent(Event $event): array
    {
        $event->loadMissing(['groups', 'allocations']);

        // Sig'im = obyektga tegishli o'rindiqlar soni.
        $capacity = Seat::query()->where('venue_id', $event->venue_id)->count();

        // Guruh bo'yicha qamrab olingan klaster/sektor ID'larini yig'amiz.
        $clustersByGroup = [];  // gid => [row_cluster_id, ...]
        $sectorsByGroup = [];   // gid => [sector_id, ...]  (butun sektor)
        foreach ($event->allocations as $a) {
            $gid = $a->event_group_id;
            if ($a->row_cluster_id !== null) {
                $clustersByGroup[$gid][] = $a->row_cluster_id;
            } else {
                $sectorsByGroup[$gid][] = $a->sector_id;
            }
        }

        $assigned = 0;
        $groups = $event->groups->map(function ($g) use ($event, $clustersByGroup, $sectorsByGroup, &$assigned) {
            $clusters = $clustersByGroup[$g->id] ?? [];
            $sectors = $sectorsByGroup[$g->id] ?? [];

            // Qamrab olingan DISTINCT o'rindiqlar (ikki marta sanashdan himoya —
            // har o'rindiq natijada bir marta uchraydi, chunki id noyob).
            $count = 0;
            if ($clusters !== [] || $sectors !== []) {
                $count = Seat::query()
                    ->where('venue_id', $event->venue_id)
                    ->where(function ($q) use ($clusters, $sectors) {
                        if ($clusters !== []) {
                            $q->orWhereIn('row_cluster_id', $clusters);
                        }
                        if ($sectors !== []) {
                            $q->orWhereIn('sector_id', $sectors);
                        }
                    })
                    ->count();
            }

            $assigned += $count;

            return [
                'id' => $g->id,
                'name' => $g->name,
                'color' => $g->color,
                'expected' => $g->expected_count,
                'assigned' => $count,
                'diff' => $g->expected_count !== null ? $count - $g->expected_count : null,
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
