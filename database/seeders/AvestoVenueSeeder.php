<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Hr\Models\SeatRow;
use App\Domains\Hr\Models\Sector;
use App\Domains\Hr\Models\Venue;
use Illuminate\Database\Seeder;

/**
 * Avesto majmuasi katta zali — obyekt + 11 sektor + 109 qator (2379 o'rindiq).
 * Manba: database/seeders/data/avesto_venue_geometry.json (CAD chizmasidan).
 * Idempotent (updateOrCreate); alohida `seats` YO'Q — qator seat_count saqlaydi.
 */
class AvestoVenueSeeder extends Seeder
{
    public function run(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $path = database_path('seeders/data/avesto_venue_geometry.json');
        if (! is_file($path)) {
            $this->command?->warn("Geometriya fayli topilmadi: {$path}");

            return;
        }

        /** @var array{viewbox_json?: array, stage_json?: array, sectors: array<int, array<string, mixed>>} $data */
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $sectors = $data['sectors'] ?? [];

        $capacity = 0;
        foreach ($sectors as $s) {
            $capacity += (int) array_sum($s['rows'] ?? []);
        }

        $venue = Venue::updateOrCreate(
            ['slug' => 'avesto-katta-zal'],
            [
                'name' => 'Avesto majmuasi — katta zal',
                'unit' => 'mm',
                'viewbox_json' => $data['viewbox_json'] ?? null,
                'stage_json' => $data['stage_json'] ?? null,
                'capacity_cached' => $capacity,
                'is_active' => true,
                'notes' => 'CAD chizmasidan (2026). Geometriya provizion — CalibrationEditor bilan kalibrlanadi.',
            ],
        );

        foreach ($sectors as $s) {
            $sector = Sector::updateOrCreate(
                ['venue_id' => $venue->id, 'code' => (string) $s['code']],
                [
                    'label' => ($s['code'] ?? '').'-SEKTOR',
                    'anchor_x' => (float) $s['anchor_x'],
                    'anchor_y' => (float) $s['anchor_y'],
                    'rotation' => (float) $s['rotation'],
                    'row_pitch' => (float) ($s['row_pitch'] ?? 1050),
                    'seat_pitch' => (float) ($s['seat_pitch'] ?? 550),
                    'tier' => $s['tier'] ?? null,
                    'sort_order' => (int) ($s['sort_order'] ?? 0),
                ],
            );

            foreach (($s['rows'] ?? []) as $i => $seatCount) {
                SeatRow::updateOrCreate(
                    ['sector_id' => $sector->id, 'row_index' => $i + 1],
                    ['seat_count' => (int) $seatCount, 'seat_start' => 1],
                );
            }
        }

        $rows = SeatRow::whereIn('sector_id', $venue->sectors()->pluck('id'))->count();
        $seats = (int) SeatRow::whereIn('sector_id', $venue->sectors()->pluck('id'))->sum('seat_count');
        $this->command?->info("Avesto: {$venue->sectors()->count()} sektor · {$rows} qator · {$seats} o'rindiq (cache: {$venue->capacity_cached}).");
    }
}
