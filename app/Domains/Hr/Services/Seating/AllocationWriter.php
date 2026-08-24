<?php

declare(strict_types=1);

namespace App\Domains\Hr\Services\Seating;

use App\Domains\Hr\Models\Event;
use App\Domains\Hr\Models\EventAllocation;
use App\Domains\Hr\Models\RowCluster;
use App\Domains\Hr\Models\Sector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Belgilashlarni bulk saqlash (autosave). REPLACE-ALL: tadbirning barcha
 * belgilashlari kelgan to'plam bilan almashtiriladi (bitta tranzaksiyada).
 *
 * Qat'iy qoidalar:
 *  - Bitta QATOR-KLASTER faqat bir marta (row_cluster_id takrorlanmasin).
 *  - Sektor YO butun (row_cluster_id=null) YO klaster kesimida — ikkalasi birga emas.
 *  - sector_id / row_cluster_id tadbir obyektiga (venue) tegishli bo'lishi shart (IDOR).
 *
 * @param  array<int, array{sector_id: string, row_cluster_id: ?string, event_group_id: string}>  $items
 */
final class AllocationWriter
{
    public function sync(Event $event, array $items): int
    {
        $seenClusters = [];   // row_cluster_id (takror tekshiruvi)
        $wholeSectors = [];   // sector_id (butun belgilangan)
        $clusterSectors = []; // sector_id (klaster kesimida belgilangan)

        foreach ($items as $it) {
            $sectorId = $it['sector_id'];
            $clusterId = $it['row_cluster_id'] ?? null;

            if ($clusterId === null) {
                if (isset($wholeSectors[$sectorId])) {
                    throw ValidationException::withMessages([
                        'allocations' => 'Sektor ikki marta butun belgilangan.',
                    ]);
                }
                $wholeSectors[$sectorId] = true;
            } else {
                if (isset($seenClusters[$clusterId])) {
                    throw ValidationException::withMessages([
                        'allocations' => 'Bitta qator-klaster ikki marta belgilangan.',
                    ]);
                }
                $seenClusters[$clusterId] = true;
                $clusterSectors[$sectorId] = true;
            }
        }

        // Sektor bir vaqtda ham butun, ham klaster kesimida bo'lmasin.
        if (array_intersect_key($wholeSectors, $clusterSectors) !== []) {
            throw ValidationException::withMessages([
                'allocations' => 'Sektor bir vaqtda butun va klaster kesimida belgilanmaydi.',
            ]);
        }

        // Obyekt tegishliligi (IDOR) — sector/cluster shu tadbir obyektiniki bo'lsin.
        $sectorIds = array_values(array_unique(array_column($items, 'sector_id')));
        $clusterIds = array_values(array_unique(array_filter(
            array_map(fn ($it) => $it['row_cluster_id'] ?? null, $items),
            fn ($v) => $v !== null
        )));

        if ($sectorIds !== []
            && Sector::where('venue_id', $event->venue_id)->whereIn('id', $sectorIds)->count() !== count($sectorIds)) {
            throw ValidationException::withMessages([
                'allocations' => 'Sektor bu obyektga tegishli emas.',
            ]);
        }
        if ($clusterIds !== []
            && RowCluster::where('venue_id', $event->venue_id)->whereIn('id', $clusterIds)->count() !== count($clusterIds)) {
            throw ValidationException::withMessages([
                'allocations' => 'Qator-klaster bu obyektga tegishli emas.',
            ]);
        }

        return DB::connection('hr')->transaction(function () use ($event, $items): int {
            $event->allocations()->delete();

            $now = now();
            $rows = array_map(fn ($it) => [
                'id' => (string) Str::uuid(),
                'uuid' => (string) Str::uuid(),
                'event_id' => $event->id,
                'sector_id' => $it['sector_id'],
                'row_cluster_id' => $it['row_cluster_id'] ?? null,
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
