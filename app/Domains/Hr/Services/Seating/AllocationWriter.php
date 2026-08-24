<?php

declare(strict_types=1);

namespace App\Domains\Hr\Services\Seating;

use App\Domains\Hr\Models\Event;
use App\Domains\Hr\Models\EventAllocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Belgilashlarni bulk saqlash (autosave). REPLACE-ALL: tadbirning barcha
 * belgilashlari kelgan to'plam bilan almashtiriladi (bitta tranzaksiyada).
 *
 * Qat'iy qoidalar (TZ):
 *  - Bitta QATOR faqat bitta guruhga (seat_row_id takrorlanmasin).
 *  - Sektor YO butun (seat_row_id=null) YO qator kesimida — ikkalasi birga emas.
 *
 * @param  array<int, array{sector_id: string, seat_row_id: ?string, event_group_id: string}>  $items
 */
final class AllocationWriter
{
    public function sync(Event $event, array $items): int
    {
        $wholeSectors = [];   // sector_id (butun belgilangan)
        $rowSectors = [];     // sector_id (qator kesimида belgilangan)
        $seenRows = [];

        foreach ($items as $it) {
            $sectorId = $it['sector_id'];
            $rowId = $it['seat_row_id'] ?? null;

            if ($rowId === null) {
                if (isset($wholeSectors[$sectorId])) {
                    throw ValidationException::withMessages([
                        'allocations' => "Sektor {$sectorId} ikki marta butun belgilangan.",
                    ]);
                }
                $wholeSectors[$sectorId] = true;
            } else {
                if (isset($seenRows[$rowId])) {
                    throw ValidationException::withMessages([
                        'allocations' => 'Bitta qator ikki guruhga belgilanmaydi.',
                    ]);
                }
                $seenRows[$rowId] = true;
                $rowSectors[$sectorId] = true;
            }
        }

        // Sektor bir vaqtda ham butun, ham qator kesimida bo'lmasin.
        $conflict = array_intersect_key($wholeSectors, $rowSectors);
        if ($conflict !== []) {
            throw ValidationException::withMessages([
                'allocations' => 'Sektor bir vaqtda butun va qator kesimida belgilanmaydi.',
            ]);
        }

        return DB::connection('hr')->transaction(function () use ($event, $items): int {
            $event->allocations()->delete();

            $now = now();
            $rows = array_map(fn ($it) => [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'event_id' => $event->id,
                'sector_id' => $it['sector_id'],
                'seat_row_id' => $it['seat_row_id'] ?? null,
                'event_group_id' => $it['event_group_id'],
                'created_at' => $now,
                'updated_at' => $now,
            ], $items);

            if ($rows !== []) {
                EventAllocation::insert($rows);
            }

            return count($rows);
        });
    }
}
