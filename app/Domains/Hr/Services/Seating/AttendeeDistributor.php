<?php

declare(strict_types=1);

namespace App\Domains\Hr\Services\Seating;

use App\Domains\Hr\Models\Event;
use App\Domains\Hr\Models\EventAttendee;
use App\Domains\Hr\Models\EventGroup;
use Illuminate\Support\Facades\DB;

/**
 * Ismlarni guruhга biriktirilган o'rindiqlarга TARTIBLI taqsimlaydi
 * (sektor tartibi → qator → o'rindiq raqami). Ortiqcha ismlar o'rindiqsiz
 * (navbatда) saqlanadi. REPLACE-ALL: guruh mehmonlari qayta yoziladi.
 *
 * @return int  yaratilган mehmonlar soni
 */
final class AttendeeDistributor
{
    /** @param array<int, string> $names */
    public function distribute(Event $event, EventGroup $group, array $names): int
    {
        $seats = $this->orderedSeats($event, $group); // [{seat_row_id, seat_number}]

        $names = array_values(array_filter(array_map('trim', $names), fn ($n) => $n !== ''));

        return DB::connection('hr')->transaction(function () use ($event, $group, $names, $seats): int {
            EventAttendee::where('event_group_id', $group->id)->delete();

            $now = now();
            $rows = [];
            foreach ($names as $i => $name) {
                $seat = $seats[$i] ?? null;
                $rows[] = [
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'uuid' => (string) \Illuminate\Support\Str::uuid(),
                    'event_id' => $event->id,
                    'event_group_id' => $group->id,
                    'seat_row_id' => $seat['seat_row_id'] ?? null,
                    'seat_number' => $seat['seat_number'] ?? null,
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
     * Guruhга biriktirilган o'rindiqlar TARTIBLI ro'yxati.
     *
     * @return array<int, array{seat_row_id: string, seat_number: int}>
     */
    private function orderedSeats(Event $event, EventGroup $group): array
    {
        $allocs = $event->allocations()->where('event_group_id', $group->id)
            ->with(['sector.seatRows', 'seatRow.sector'])->get();

        $rows = [];
        foreach ($allocs as $a) {
            if ($a->seat_row_id === null) {
                foreach (($a->sector?->seatRows ?? collect()) as $r) {
                    $rows[] = ['sort' => (int) ($a->sector->sort_order ?? 0), 'ri' => $r->row_index, 'row' => $r];
                }
            } elseif ($a->seatRow) {
                $rows[] = ['sort' => (int) ($a->seatRow->sector->sort_order ?? 0), 'ri' => $a->seatRow->row_index, 'row' => $a->seatRow];
            }
        }

        usort($rows, fn ($x, $y) => [$x['sort'], $x['ri']] <=> [$y['sort'], $y['ri']]);

        $seats = [];
        foreach ($rows as $r) {
            $row = $r['row'];
            for ($n = 0; $n < $row->seat_count; $n++) {
                $seats[] = ['seat_row_id' => $row->id, 'seat_number' => (int) $row->seat_start + $n];
            }
        }

        return $seats;
    }
}
