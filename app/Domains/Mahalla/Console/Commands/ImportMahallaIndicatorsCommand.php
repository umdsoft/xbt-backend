<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Console\Commands;

use App\Domains\Mahalla\Support\MahallaMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mahalla ko'rsatkichlarini CSV'dan import qiladi (aholi, xonadon, oila,
 * kambag'allik, dastur belgilari).
 *
 * CSV (Excel emas): tashqi kutubxona qo'shmaslik uchun. Excel fayl
 * "Farqli saqlash -> CSV UTF-8" bilan bir necha soniyada tayyorlanadi.
 *
 * Ustunlar (sarlavha qatori majburiy, tartibi ahamiyatsiz):
 *   mahalla        — mahalla nomi (eski nom ham bo'ladi, alias orqali topiladi)
 *   population     — aholi soni
 *   households     — xonadon soni
 *   families       — oila soni
 *   poor_families  — kambag'al oilalar
 *   poverty_rate   — kambag'allik darajasi (%)
 *   ogir           — og'ir mahalla (1/0, ha/yo'q)
 *   yangi_uzbekiston — dasturda (1/0)
 */
class ImportMahallaIndicatorsCommand extends Command
{
    protected $signature = 'mahalla:import-indicators
                            {file : CSV fayl yo\'li (UTF-8)}
                            {--district= : Tuman SOATO kodi}
                            {--period= : Hisobot davri (YYYY-MM-DD), sukut: joriy oy boshi}
                            {--apply : O\'zgarishlarni bazaga yozadi}';

    protected $description = 'Mahalla ko\'rsatkichlarini CSV dan import qiladi';

    public function handle(MahallaMatcher $matcher): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("Файл топилмади: {$path}");

            return self::FAILURE;
        }

        $districtId = null;
        if ($soato = $this->option('district')) {
            $districtId = DB::connection('master')->table('districts')
                ->where('soato_code', $soato)->value('id');
            if ($districtId === null) {
                $this->error("Туман топилмади: {$soato}");

                return self::FAILURE;
            }
            $matcher->forDistrict($districtId);
        }

        $period = $this->option('period') ?: now()->startOfMonth()->toDateString();
        $apply = (bool) $this->option('apply');

        $rows = $this->readCsv($path);
        if ($rows === []) {
            $this->error('CSV бўш ёки сарлавҳа қатори топилмади');

            return self::FAILURE;
        }

        $ok = 0;
        $miss = [];

        foreach ($rows as $r) {
            $name = trim((string) ($r['mahalla'] ?? ''));
            if ($name === '') {
                continue;
            }

            $id = $matcher->match($name);
            if ($id === null) {
                $miss[] = $name;
                continue;
            }

            $ok++;
            if (! $apply) {
                continue;
            }

            DB::connection('master')->table('mahalla_indicators')->updateOrInsert(
                ['mahalla_id' => $id, 'period' => $period],
                [
                    'id' => (string) Str::uuid(),
                    'population' => $this->num($r['population'] ?? null),
                    'households' => $this->num($r['households'] ?? null),
                    'families' => $this->num($r['families'] ?? null),
                    'poor_families' => $this->num($r['poor_families'] ?? null),
                    'poverty_rate' => $this->dec($r['poverty_rate'] ?? null),
                    'is_ogir' => $this->bool($r['ogir'] ?? null),
                    'is_yangi_uzbekiston' => $this->bool($r['yangi_uzbekiston'] ?? null),
                    'source' => basename($path),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        $this->newLine();
        $this->info(($apply ? 'Ёзилди' : 'Кўрсатилди (--apply берилмаган)').':');
        $this->line("  давр            : {$period}");
        $this->line('  мос келди       : '.$ok.' / '.count($rows));

        if ($miss !== []) {
            $this->newLine();
            $this->warn('МОС КЕЛМАГАН НОМЛАР ('.count($miss).' та) — базада топилмади:');
            foreach ($miss as $n) {
                $this->line("  {$n}");
            }
            $this->line('  (ном ўзгарган бўлса: php artisan mahalla:rename <soato> "<янги ном>" --apply)');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function readCsv(string $path): array
    {
        $fh = fopen($path, 'r');
        if ($fh === false) {
            return [];
        }

        // Excel UTF-8 CSV boshiga BOM qo'yadi — u birinchi ustun nomiga
        // yopishib qolsa, sarlavha tanilmaydi.
        $first = fgets($fh);
        if ($first !== false && str_starts_with($first, "\xEF\xBB\xBF")) {
            $first = substr($first, 3);
        }
        rewind($fh);
        if ($first !== false) {
            fgets($fh);
        }

        $sep = substr_count((string) $first, ';') > substr_count((string) $first, ',') ? ';' : ',';
        $head = array_map(
            fn ($h) => mb_strtolower(trim((string) $h, " \t\"'\xEF\xBB\xBF")),
            str_getcsv(trim((string) $first), $sep),
        );

        $out = [];
        while (($line = fgetcsv($fh, 0, $sep)) !== false) {
            if ($line === [null] || $line === []) {
                continue;
            }
            $row = [];
            foreach ($head as $i => $key) {
                $row[$key] = isset($line[$i]) ? trim((string) $line[$i]) : '';
            }
            $out[] = $row;
        }
        fclose($fh);

        return $out;
    }

    private function num(mixed $v): ?int
    {
        $v = preg_replace('/[^\d\-]/', '', (string) $v);

        return $v === '' ? null : (int) $v;
    }

    private function dec(mixed $v): ?float
    {
        $v = str_replace(',', '.', (string) $v);
        $v = preg_replace('/[^\d.\-]/', '', $v);

        return $v === '' ? null : (float) $v;
    }

    private function bool(mixed $v): bool
    {
        $v = mb_strtolower(trim((string) $v));

        return in_array($v, ['1', 'ha', 'ҳа', 'да', 'yes', 'true', '+'], true);
    }
}
