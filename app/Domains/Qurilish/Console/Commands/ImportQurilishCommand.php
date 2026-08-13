<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Console\Commands;

use App\Domains\Qurilish\Models\ConstructionObject;
use App\Domains\Qurilish\Models\ImportSession;
use App\Domains\Qurilish\Services\ObjectImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `qurilish:import` — Python ekstraktori chiqargan CSV'larni bazaga yuklaydi.
 *
 * Oldindan:
 *   python scripts/qurilish/extract_xlsx.py
 *
 * Idempotent: `external_id` bo'yicha upsert, shuning uchun xohlagancha
 * qayta yurgizish mumkin — sanoq o'zgarmaydi.
 */
class ImportQurilishCommand extends Command
{
    protected $signature = 'qurilish:import
        {--dir=storage/app/import/qurilish : CSV papkasi}
        {--year=2026 : oylik grafik yili}';

    protected $description = 'Qurilish obyektlarini xom CSV dan import qiladi (idempotent)';

    public function handle(ObjectImporter $importer): int
    {
        $dir = rtrim((string) $this->option('dir'), '/\\');
        $objectsPath = base_path($dir.'/objects.csv');
        $monthlyPath = base_path($dir.'/monthly.csv');

        foreach ([$objectsPath, $monthlyPath] as $path) {
            if (! is_file($path)) {
                $this->error("CSV topilmadi: {$path}");
                $this->line('Avval: python scripts/qurilish/extract_xlsx.py');

                return self::FAILURE;
            }
        }

        $this->info('Import boshlandi...');

        $report = DB::connection('qurilish')->transaction(fn (): array => $importer->import(
            $this->readCsv($objectsPath),
            $this->readCsv($monthlyPath),
            (int) $this->option('year'),
        ));

        ImportSession::query()->create([
            'file_name' => basename($objectsPath),
            'records_count' => $report['objects'],
            'is_active' => true,
            'notes' => json_encode([
                'stages' => $report['stages'],
                'monthly' => $report['monthly'],
                'no_district' => $report['no_district'],
                'duplicates' => $report['duplicates'],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $this->newLine();
        $this->table(['Ko\'rsatkich', 'Qiymat'], [
            ['Obyekt (qator)', $report['objects']],
            ['Obyekt (baza)', ConstructionObject::query()->count()],
            ['Bosqich', $report['stages']],
            ['Oylik grafik', $report['monthly']],
            ['Tumansiz obyekt', $report['no_district']],
            ['Takroriy ID', $report['duplicates']],
            ['Jami limit (mln)', number_format((float) ConstructionObject::query()->sum('limit_amount'), 3, '.', ' ')],
        ]);

        foreach (array_slice($report['warnings'], 0, 20) as $w) {
            $this->warn('  '.$w);
        }
        if (count($report['warnings']) > 20) {
            $this->warn('  ... yana '.(count($report['warnings']) - 20).' ta ogohlantirish');
        }

        $this->info('Import tugadi.');

        return self::SUCCESS;
    }

    /**
     * CSV'ni sarlavha bo'yicha assotsiativ qatorlarga aylantiradi (generator —
     * 611 qator kichik, lekin naqsh kattaroq fayl uchun ham xavfsiz).
     *
     * @return \Generator<int, array<string, string>>
     */
    private function readCsv(string $path): \Generator
    {
        $fh = fopen($path, 'r');
        if ($fh === false) {
            return;
        }

        $header = fgetcsv($fh);
        if ($header === false) {
            fclose($fh);

            return;
        }

        while (($row = fgetcsv($fh)) !== false) {
            if ($row === [null] || $row === []) {
                continue;
            }
            yield array_combine($header, array_pad(array_slice($row, 0, count($header)), count($header), ''));
        }

        fclose($fh);
    }
}
