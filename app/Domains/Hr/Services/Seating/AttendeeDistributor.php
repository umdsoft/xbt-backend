<?php

declare(strict_types=1);

namespace App\Domains\Hr\Services\Seating;

use App\Domains\Hr\Models\Event;
use App\Domains\Hr\Models\EventAttendee;
use App\Domains\Hr\Models\EventGroup;
use App\Domains\Hr\Models\Seat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ismlarni guruhga biriktirilgan o'rindiqlarga TARTIBLI taqsimlaydi
 * (sektor sort_order → klaster centroid_y/x → o'rindiq y/x). Ortiqcha ismlar
 * o'rindiqsiz (seat_id=null) saqlanadi. REPLACE-ALL: guruh mehmonlari qayta yoziladi.
 *
 * @return int yaratilgan mehmonlar soni
 */
final class AttendeeDistributor
{
    /** @param array<int, string> $names */
    public function distribute(Event $event, EventGroup $group, array $names): int
    {
        $seatIds = $this->orderedSeatIds($event, $group);

        $names = array_values(array_filter(array_map('trim', $names), fn ($n) => $n !== ''));

        return DB::connection('hr')->transaction(function () use ($event, $group, $names, $seatIds): int {
            EventAttendee::where('event_group_id', $group->id)->delete();

            $now = now();
            $rows = [];
            foreach ($names as $i => $name) {
                $seatId = $seatIds[$i] ?? null;
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'uuid' => (string) Str::uuid(),
                    'event_id' => $event->id,
                    'event_group_id' => $group->id,
                    'seat_id' => $seatId,
                    'seat_number' => $seatId !== null ? $i + 1 : null,
                    'full_name' => mb_substr($name, 0, 255),
                    'present' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if ($rows !== []) {
                EventAttendee::insert($rows);
            }

            return count($rows);
        });
    }

    /**
     * Guruhga biriktirilgan o'rindiqlarning TARTIBLI ID ro'yxati. Klaster-belgilashlari
     * (row_cluster_id) va butun-sektor belgilashlari (sector_id) o'rindiqlari birlashtiriladi.
     *
     * @return array<int, string>
     */
    private function orderedSeatIds(Event $event, EventGroup $group): array
    {
        $allocs = $event->allocations()->where('event_group_id', $group->id)->get();

        $clusterIds = [];
        $wholeSectorIds = [];
        foreach ($allocs as $a) {
            if ($a->row_cluster_id !== null) {
                $clusterIds[] = $a->row_cluster_id;
            } else {
                $wholeSectorIds[] = $a->sector_id;
            }
        }

        if ($clusterIds === [] && $wholeSectorIds === []) {
            return [];
        }

        return Seat::query()
            ->where('seats.venue_id', $event->venue_id)
            ->leftJoin('sectors', 'seats.sector_id', '=', 'sectors.id')
            ->leftJoin('row_clusters', 'seats.row_cluster_id', '=', 'row_clusters.id')
            ->where(function ($q) use ($clusterIds, $wholeSectorIds) {
                if ($clusterIds !== []) {
                    $q->orWhereIn('seats.row_cluster_id', $clusterIds);
                }
                if ($wholeSectorIds !== []) {
                    $q->orWhereIn('seats.sector_id', $wholeSectorIds);
                }
            })
            ->orderByRaw('COALESCE(sectors.sort_order, 0)')
            ->orderByRaw('COALESCE(row_clusters.centroid_y, 0)')
            ->orderByRaw('COALESCE(row_clusters.centroid_x, 0)')
            ->orderBy('seats.y')
            ->orderBy('seats.x')
            ->pluck('seats.id')
            ->all();
    }
}
