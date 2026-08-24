<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Hr\Models\SeatRow;
use App\Domains\Hr\Models\Sector;
use App\Domains\Hr\Models\Venue;
use Illuminate\Database\Seeder;

/**
 * Avesto majmuasi katta zali — obyekt + 6 jismoniy hudud (Президиум/Партер/Чап-Ўнг
 * қанот/ложалар) + 62 qator + 2384 o'rindiq. Manba: avesto.dwg (libredwg) → aniq
 * o'rindiq (x,y) koordinatalari database/seeders/data/avesto_venue_geometry.json'da.
 * Idempotent; qatorlar `points_json` bilan aniq chiziladi (alohida `seats` jadvali YO'Q).
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

        // Qatorlar endi obyekt: {index, seat_count, seat_start, points}. Eski format (int) ham qo'llab-quvvatlanadi.
        $rowSeatCount = static fn ($row): int => is_array($row) ? (int) ($row['seat_count'] ?? 0) : (int) $row;

        $capacity = 0;
        foreach ($sectors as $s) {
            foreach (($s['rows'] ?? []) as $row) {
                $capacity += $rowSeatCount($row);
            }
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

        // Eski geometriyadan qolgan sektorlarni tozalash (kodlar to'plami o'zgargan bo'lishi mumkin).
        $keepCodes = array_map(static fn ($s) => (string) $s['code'], $sectors);
        $venue->sectors()->whereNotIn('code', $keepCodes)->delete();

        foreach ($sectors as $s) {
            $sector = Sector::updateOrCreate(
                ['venue_id' => $venue->id, 'code' => (string) $s['code']],
                [
                    'label' => $s['label'] ?? (($s['code'] ?? '').'-SEKTOR'),
                    'anchor_x' => (float) $s['anchor_x'],
                    'anchor_y' => (float) $s['anchor_y'],
                    'rotation' => (float) $s['rotation'],
                    'row_pitch' => (float) ($s['row_pitch'] ?? 1050),
                    'seat_pitch' => (float) ($s['seat_pitch'] ?? 550),
                    'tier' => $s['tier'] ?? null,
                    'sort_order' => (int) ($s['sort_order'] ?? 0),
                ],
            );

            // Eski qatorlarni tozalash (agar geometriya o'zgargan bo'lsa — qator soni farq qilishi mumkin).
            SeatRow::where('sector_id', $sector->id)->delete();

            foreach (($s['rows'] ?? []) as $i => $row) {
                $isObj = is_array($row);
                SeatRow::create([
                    'sector_id' => $sector->id,
                    'row_index' => $isObj ? (int) ($row['index'] ?? $i + 1) : $i + 1,
                    'seat_count' => $rowSeatCount($row),
                    'seat_start' => $isObj ? (int) ($row['seat_start'] ?? 1) : 1,
                    'points_json' => $isObj ? ($row['points'] ?? null) : null,
                ]);
            }
        }

        $rows = SeatRow::whereIn('sector_id', $venue->sectors()->pluck('id'))->count();
        $seats = (int) SeatRow::whereIn('sector_id', $venue->sectors()->pluck('id'))->sum('seat_count');
        $this->command?->info("Avesto: {$venue->sectors()->count()} sektor · {$rows} qator · {$seats} o'rindiq (cache: {$venue->capacity_cached}).");
    }
}
