<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Hr\Models\RowCluster;
use App\Domains\Hr\Models\Seat;
use App\Domains\Hr\Models\Sector;
use App\Domains\Hr\Models\Venue;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Avesto katta zali — geometriya avesto.dwg MTEXT yorliqlaridan CHIQARILGAN
 * (formula YO'Q). Manba: database/seeders/data/avesto_venue.json (ETL build_venue.py).
 * 2400 o'rindiq (1200 yorliq + mirror y=0), 156 qator-klaster.
 * Boshlang'ich sektorlar = K/O/Y guruhlari; PDF 11-sektor moslashtirish admin UI'da.
 */
class AvestoVenueSeeder extends Seeder
{
    public function run(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $path = database_path('seeders/data/avesto_venue.json');
        if (! is_file($path)) {
            $this->command?->warn("Geometriya fayli topilmadi: {$path}");

            return;
        }

        /** @var array{venue:array,row_clusters:array,seats:array} $data */
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $v = $data['venue'];

        $venue = Venue::updateOrCreate(
            ['slug' => $v['slug']],
            [
                'name' => $v['name'],
                'unit' => $v['unit'] ?? 'mm',
                'source_file' => $v['source_file'] ?? null,
                'bbox_json' => $v['bbox_json'] ?? null,
                'mirror_axis_json' => $v['mirror_axis_json'] ?? null,
                'stage_json' => $v['stage_json'] ?? null,
                'viewbox_json' => null,
                'capacity_cached' => (int) ($v['capacity'] ?? count($data['seats'])),
                'is_active' => true,
                'notes' => 'CAD (avesto.dwg) yorliqlaridan aniq koordinata. Mirror y=0.',
            ],
        );

        // Toza qayta seed (eski geometriya butunlay boshqacha edi)
        DB::connection('hr')->table('seats')->where('venue_id', $venue->id)->delete();
        DB::connection('hr')->table('row_clusters')->where('venue_id', $venue->id)->delete();
        DB::connection('hr')->table('sectors')->where('venue_id', $venue->id)->delete();

        // --- row_clusters ---
        $rcMap = []; // code -> id
        $now = now();
        $rcRows = [];
        foreach ($data['row_clusters'] as $rc) {
            $id = (string) Str::uuid();
            $rcMap[$rc['id']] = $id;
            $rcRows[] = [
                'id' => $id, 'uuid' => (string) Str::uuid(), 'venue_id' => $venue->id,
                'code' => $rc['id'], 'angle' => $rc['angle'], 'seat_count' => $rc['seat_count'],
                'centroid_x' => $rc['centroid_x'], 'centroid_y' => $rc['centroid_y'],
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rcRows, 200) as $chunk) {
            DB::connection('hr')->table('row_clusters')->insert($chunk);
        }

        // --- sectors (K/O/Y) ---
        $secDef = [
            'K' => ['label' => 'Кўк гуруҳ', 'color' => '#2563eb', 'sort' => 1],
            'O' => ['label' => 'Оқ гуруҳ', 'color' => '#94a3b8', 'sort' => 2],
            'Y' => ['label' => 'Яшил гуруҳ', 'color' => '#16a34a', 'sort' => 3],
        ];
        $secMap = [];
        foreach ($secDef as $g => $def) {
            $sec = Sector::create([
                'venue_id' => $venue->id, 'code' => $g, 'label' => $def['label'],
                'color' => $def['color'], 'sort_order' => $def['sort'],
            ]);
            $secMap[$g] = $sec->id;
        }

        // --- seats ---
        $seatRows = [];
        foreach ($data['seats'] as $s) {
            $seatRows[] = [
                'id' => (string) Str::uuid(), 'uuid' => (string) Str::uuid(), 'venue_id' => $venue->id,
                'code' => $s['code'], 'x' => $s['x'], 'y' => $s['y'], 'rotation' => $s['rotation'],
                'seat_group' => $s['seat_group'], 'row_cluster_id' => $rcMap[$s['row_cluster_id']] ?? null,
                'sector_id' => $secMap[$s['seat_group']] ?? null, 'is_mirrored' => $s['is_mirrored'],
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($seatRows, 500) as $chunk) {
            DB::connection('hr')->table('seats')->insert($chunk);
        }

        $venue->update(['capacity_cached' => count($seatRows)]);
        $this->command?->info(sprintf(
            'Avesto: %d sektor · %d klaster · %d o\'rindiq (bbox %s).',
            count($secMap), count($rcRows), count($seatRows),
            json_encode($venue->bbox_json)
        ));
    }
}
