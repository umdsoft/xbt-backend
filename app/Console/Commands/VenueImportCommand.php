<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Hr\Models\Sector;
use App\Domains\Hr\Models\Venue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * venue:import — DWG fayldan zal geometriyasini CHIQARIB (ETL orqali) bazaga
 * yozadi. Geometriya YARATILMAYDI — DWG dagi MTEXT yorliqlaridan olinadi.
 *
 * Pipeline (venue-geometriya oqimining 7-bosqichi):
 *   1) node database/tools/venue-import/extract_labels.mjs  <dwg> <tmp_labels>
 *   2) python database/tools/venue-import/build_venue.py   <tmp_labels> <tmp_venue>
 *   3) hosil bo'lgan venue JSON o'qiladi va (dry-run bo'lmasa) 'hr' ulanishiga yoziladi.
 *
 * Konfiguratsiya (mirror o'qi, yorliq regexi, slug, nom) — opsiyalar orqali,
 * HECH NARSA hardcode qilinmagan; komanda boshqa DWG'lar uchun qayta ishlatiladi.
 *
 * DB yozish logikasi AvestoVenueSeeder bilan bir xil (venue slug bo'yicha
 * updateOrCreate, eski seats/row_clusters/sectors o'chiriladi, chunked insert).
 */
class VenueImportCommand extends Command
{
    protected $signature = 'venue:import
        {dwg : DWG fayl yo\'li}
        {--slug= : obyekt slug (majburiy)}
        {--name= : obyekt nomi (majburiy)}
        {--mirror-axis=y:0 : mirror o\'qi "axis:value" (masalan y:0)}
        {--label-regex= : yorliq regexi (ixtiyoriy; 2 capture-guruh: guruh + seq)}
        {--dry-run : Bazaga yozmasdan faqat xulosa jadvalini ko\'rsat}';

    protected $description = 'DWG fayldan zal geometriyasini chiqarib bazaga import qiladi (reusable ETL)';

    /** Node/Python jarayonlari uchun saxovatli timeout (soniya). */
    private const PROCESS_TIMEOUT = 600;

    public function handle(): int
    {
        $dwg = (string) $this->argument('dwg');
        $slug = trim((string) $this->option('slug'));
        $name = trim((string) $this->option('name'));
        $mirrorAxis = (string) $this->option('mirror-axis') ?: 'y:0';
        $labelRegex = (string) $this->option('label-regex');
        $dryRun = (bool) $this->option('dry-run');

        // --- kirish tekshiruvi ---
        if (! is_file($dwg)) {
            $this->error("DWG fayl topilmadi: {$dwg}");

            return self::FAILURE;
        }
        if ($slug === '' || $name === '') {
            $this->error('--slug va --name majburiy.');

            return self::FAILURE;
        }

        // --- node/python mavjudligini aniqlash ---
        if (! $this->toolAvailable('node')) {
            $this->error('`node` topilmadi (PATH da emas). Node.js o\'rnatilganini tekshiring.');

            return self::FAILURE;
        }
        if (! $this->toolAvailable('python')) {
            $this->error('`python` topilmadi (PATH da emas). Python o\'rnatilganini tekshiring.');

            return self::FAILURE;
        }

        $toolsDir = base_path('database/tools/venue-import');
        $extractScript = $toolsDir.DIRECTORY_SEPARATOR.'extract_labels.mjs';
        $buildScript = $toolsDir.DIRECTORY_SEPARATOR.'build_venue.py';

        // --- vaqtinchalik fayllar ---
        $tmpDir = sys_get_temp_dir();
        $labelsTmp = $tmpDir.DIRECTORY_SEPARATOR.'venue_labels_'.Str::random(10).'.json';
        $venueTmp = $tmpDir.DIRECTORY_SEPARATOR.'venue_data_'.Str::random(10).'.json';

        try {
            // (a) DWG -> yorliqlar JSON (node ESM + libredwg-web WASM)
            $this->line("<info>1/3</info> Yorliqlar chiqarilmoqda: {$dwg}");
            $extractArgs = ['node', $extractScript, $dwg, $labelsTmp];
            if ($labelRegex !== '') {
                $extractArgs[] = '--label-regex='.$labelRegex;
            }
            $extract = Process::path($toolsDir)->timeout(self::PROCESS_TIMEOUT)->run($extractArgs);
            if (! $extract->successful()) {
                $this->error('extract_labels.mjs muvaffaqiyatsiz (exit '.$extract->exitCode().'):');
                $this->line($extract->errorOutput() ?: $extract->output());

                return self::FAILURE;
            }
            foreach (array_filter(explode("\n", trim($extract->output()))) as $ln) {
                $this->line('    '.$ln);
            }

            // (b) yorliqlar JSON -> to'liq venue dataset (mirror + klaster + burchak)
            $this->line('<info>2/3</info> Venue dataset qurilmoqda (mirror '.$mirrorAxis.') ...');
            $buildArgs = [
                'python', $buildScript, $labelsTmp, $venueTmp,
                '--slug='.$slug,
                '--name='.$name,
                '--mirror-axis='.$mirrorAxis,
                '--source-file='.basename($dwg),
            ];
            $build = Process::path($toolsDir)->timeout(self::PROCESS_TIMEOUT)->run($buildArgs);
            if (! $build->successful()) {
                $this->error('build_venue.py muvaffaqiyatsiz (exit '.$build->exitCode().'):');
                $this->line($build->errorOutput() ?: $build->output());

                return self::FAILURE;
            }
            foreach (array_filter(explode("\n", trim($build->output()))) as $ln) {
                $this->line('    '.$ln);
            }

            // (c) hosil bo'lgan venue JSON ni o'qish
            if (! is_file($venueTmp)) {
                $this->error("Venue JSON hosil bo'lmadi: {$venueTmp}");

                return self::FAILURE;
            }
            /** @var array{venue:array,row_clusters:array,seats:array} $data */
            $data = json_decode((string) file_get_contents($venueTmp), true, 512, JSON_THROW_ON_ERROR);

            // --- xulosa jadvali ---
            $this->newLine();
            $this->line('<comment>3/3</comment> Xulosa:');
            $this->renderSummary($data);

            if ($dryRun) {
                $this->newLine();
                $this->info('DRY-RUN — bazaga hech narsa yozilmadi.');

                return self::SUCCESS;
            }

            // --- bazaga import (AvestoVenueSeeder logikasi bilan bir xil) ---
            $venue = $this->importToDatabase($data);
            $this->newLine();
            $this->info(sprintf(
                "Import tugadi: '%s' (%s) — %d o'rindiq bazaga yozildi.",
                $venue->name, $venue->slug, (int) $venue->capacity_cached,
            ));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Xato: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            // vaqtinchalik fayllarni tozalash
            foreach ([$labelsTmp, $venueTmp] as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
        }
    }

    /** CLI vositasi (node/python) chaqiriladigan holatda mavjudmi. */
    private function toolAvailable(string $bin): bool
    {
        try {
            return Process::timeout(30)->run([$bin, '--version'])->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Dry-run va import oldidan xulosa jadvalini chizadi:
     * jami o'rindiq, gabarit (En x Boy, mm), qator-klasterlar soni,
     * burchak gistogrammasi, guruh gistogrammasi.
     *
     * @param  array{venue:array,row_clusters:array,seats:array}  $data
     */
    private function renderSummary(array $data): void
    {
        $seats = $data['seats'];
        $clusters = $data['row_clusters'];
        $bbox = $data['venue']['bbox_json'];
        $w = (float) $bbox['max_x'] - (float) $bbox['min_x'];
        $h = (float) $bbox['max_y'] - (float) $bbox['min_y'];

        // burchak gistogrammasi (klasterlar bo'yicha)
        $angleHist = [];
        foreach ($clusters as $rc) {
            $a = (string) (0 + $rc['angle']);
            $angleHist[$a] = ($angleHist[$a] ?? 0) + 1;
        }
        uksort($angleHist, static fn ($x, $y) => (float) $x <=> (float) $y);

        // guruh gistogrammasi (o'rindiqlar bo'yicha)
        $groupHist = [];
        foreach ($seats as $s) {
            $g = (string) $s['seat_group'];
            $groupHist[$g] = ($groupHist[$g] ?? 0) + 1;
        }
        ksort($groupHist);

        $fmt = static function (array $hist): string {
            $parts = [];
            foreach ($hist as $k => $v) {
                $parts[] = "{$k}:{$v}";
            }

            return implode(', ', $parts);
        };

        $this->table(
            ['Ko\'rsatkich', 'Qiymat'],
            [
                ['O\'rindiqlar (jami)', (string) count($seats)],
                ['Gabarit En x Boy (mm)', sprintf('%d x %d', (int) round($w), (int) round($h))],
                ['Qator klasterlar', (string) count($clusters)],
                ['Burchak gistogrammasi', $fmt($angleHist)],
                ['Guruh gistogrammasi', $fmt($groupHist)],
                ['bbox', json_encode($bbox, JSON_UNESCAPED_UNICODE)],
                ['Mirror o\'q', json_encode($data['venue']['mirror_axis_json'] ?? null, JSON_UNESCAPED_UNICODE)],
            ],
        );
    }

    /**
     * Venue JSON ni 'hr' ulanishiga import qiladi. Logika AvestoVenueSeeder bilan
     * AYNAN bir xil: slug bo'yicha updateOrCreate, eski seats/row_clusters/sectors
     * o'chiriladi, chunked insert, capacity_cached yangilanadi.
     *
     * @param  array{venue:array,row_clusters:array,seats:array}  $data
     */
    private function importToDatabase(array $data): Venue
    {
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
                'notes' => 'CAD ('.($v['source_file'] ?? 'dwg').') yorliqlaridan aniq koordinata. Mirror '
                    .json_encode($v['mirror_axis_json'] ?? null, JSON_UNESCAPED_UNICODE).'.',
            ],
        );

        // Toza qayta import (eski geometriya butunlay boshqacha bo'lishi mumkin)
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

        return $venue;
    }
}
