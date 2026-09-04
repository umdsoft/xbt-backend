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
 *   social_registry_families — "Ижтимоий реестр"даги oilalar
 *   social_registry_members  — reestrdagi oila a'zolari
 *   social_registry_rate     — qamrov (%)
 *   poor_families  — kambag'al oilalar (reestrdagilarning bir qismi)
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
        $unknownDistricts = [];

        foreach ($rows as $r) {
            $name = trim((string) ($r['mahalla'] ?? ''));
            if ($name === '') {
                continue;
            }

            // `district` ustuni bo'lsa har qator o'z tumani doirasida
            // qidiriladi — bitta fayl butun viloyatni ko'tara oladi. Bir xil
            // mahalla nomi turli tumanlarda uchraydi ("Гулистон" beshta
            // tumanda bor), shuning uchun tumansiz qidirish xato juftlik beradi.
            $rowDistrict = trim((string) ($r['district'] ?? ''));
            if ($rowDistrict !== '') {
                $resolved = $this->resolveDistrict($rowDistrict);
                if ($resolved === null) {
                    $unknownDistricts[$rowDistrict] = true;

                    continue;
                }
                $matcher->forDistrict($resolved);
            }

            $id = $matcher->match($name);
            if ($id === null) {
                $miss[] = ($rowDistrict !== '' ? "{$rowDistrict} / " : '').$name;

                continue;
            }

            $ok++;
            if (! $apply) {
                continue;
            }

            DB::connection('master')->table('mahalla_indicators')->updateOrInsert(
                ['mahalla_id' => $id, 'period' => $period],
                $this->payload($r, $path),
            );
        }

        $this->newLine();
        $this->info(($apply ? 'Ёзилди' : 'Кўрсатилди (--apply берилмаган)').':');
        $this->line("  давр            : {$period}");
        $this->line('  мос келди       : '.$ok.' / '.count($rows));

        if ($unknownDistricts !== []) {
            $this->newLine();
            $this->warn('НОМАЪЛУМ ТУМАНЛАР ('.count($unknownDistricts).' та) — қаторлари ўтказиб юборилди:');
            foreach (array_keys($unknownDistricts) as $d) {
                $this->line("  {$d}");
            }
        }

        if ($miss !== []) {
            $this->newLine();
            $this->warn('МОС КЕЛМАГАН НОМЛАР ('.count($miss).' та) — базада топилмади:');
            foreach (array_slice($miss, 0, 30) as $n) {
                $this->line("  {$n}");
            }
            if (count($miss) > 30) {
                $this->line('  ... яна '.(count($miss) - 30).' та');
            }
            $this->line('  (ном ўзгарган бўлса: php artisan mahalla:rename <soato> "<янги ном>" --apply)');
        }

        return self::SUCCESS;
    }

    /** @var array<string, string|null>  normallashtirilgan nom/soato => district_id */
    private array $districtCache = [];

    /**
     * CSV ustuni => baza ustuni va uni o'qish usuli.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const FIELDS = [
        'population' => ['population', 'num'],
        'households' => ['households', 'num'],
        'families' => ['families', 'num'],
        'social_registry_families' => ['social_registry_families', 'num'],
        'social_registry_members' => ['social_registry_members', 'num'],
        'social_registry_rate' => ['social_registry_rate', 'dec'],
        'state_supported_families' => ['state_supported_families', 'num'],
        'state_supported_members' => ['state_supported_members', 'num'],
        'poor_families' => ['poor_families', 'num'],
        'poor_members' => ['poor_members', 'num'],
        'poverty_rate' => ['poverty_rate', 'dec'],
        'borderline_families' => ['borderline_families', 'num'],
        'borderline_members' => ['borderline_members', 'num'],
        'ogir' => ['is_ogir', 'bool'],
        'yangi_uzbekiston' => ['is_yangi_uzbekiston', 'bool'],
        'employed_population' => ['employed_population', 'num'],
        'employment_rate' => ['employment_rate', 'dec'],
        'specialization' => ['specialization', 'text'],
        'specialization_defined' => ['specialization_defined', 'bool'],
        'unemployed' => ['unemployed', 'num'],
        'unemployment_rate' => ['unemployment_rate', 'dec'],
        'tomorqa_households' => ['tomorqa_households', 'num'],
        'tomorqa_area_sotix' => ['tomorqa_area_sotix', 'dec'],
        'registry_waiting_families' => ['registry_waiting_families', 'num'],
        'registry_unemployed_members' => ['registry_unemployed_members', 'num'],
        'registry_disabled_members' => ['registry_disabled_members', 'num'],
    ];

    /**
     * Faqat CSV da BOR ustunlarni yozadi.
     *
     * Muhim: yo'q ustun `null` bilan ustidan yozilmaydi. Manbalar bo'lak-bo'lak
     * keladi — viloyat fayli oila va kambag'allikni beradi, og'ir mahalla ro'yxati
     * esa aholi sonini va maqom belgisini. Ikkinchisini import qilish birinchisini
     * o'chirib yuborsa, har safar hamma faylni qayta yuklash kerak bo'lardi.
     *
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private function payload(array $row, string $path): array
    {
        $out = [
            'id' => (string) Str::uuid(),
            'source' => basename($path),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        foreach (self::FIELDS as $csvKey => [$column, $caster]) {
            if (array_key_exists($csvKey, $row)) {
                $out[$column] = $this->{$caster}($row[$csvKey]);
            }
        }

        return $out;
    }

    /**
     * Tuman nomini yoki SOATO kodini `district_id` ga aylantiradi.
     *
     * Fayllarda tuman goh kod bilan ("1733230"), goh nom bilan ("ШОВОТ
     * ТУМАНИ") keladi.
     *
     * DIQQAT: "тумани"/"шаҳри" qo'shimchasini TASHLAB BO'LMAYDI. Xorazmda
     * "Урганч тумани" (56 mahalla) va "Урганч шаҳар" (40 mahalla) — ikki
     * boshqa hudud, xuddi shunday Хива. Qo'shimcha tashlansa ikkalasi
     * "урганч" ga aylanadi va shahar mahallalari tuman ichidan qidiriladi.
     * Shuning uchun qo'shimcha bitta belgiga keltiriladi: "т" yoki "ш".
     */
    private function resolveDistrict(string $raw): ?string
    {
        $key = self::districtKey($raw);

        if (array_key_exists($key, $this->districtCache)) {
            return $this->districtCache[$key];
        }

        if (ctype_digit(trim($raw))) {
            return $this->districtCache[$key] = DB::connection('master')->table('districts')
                ->where('soato_code', trim($raw))->value('id');
        }

        $id = null;
        foreach (DB::connection('master')->table('districts')->get(['id', 'name_cyr', 'name_lat']) as $d) {
            foreach ([$d->name_cyr, $d->name_lat] as $n) {
                if ($n !== null && self::districtKey((string) $n) === $key) {
                    $id = $d->id;
                    break 2;
                }
            }
        }

        return $this->districtCache[$key] = $id;
    }

    /** Hudud nomini turi bilan birga yagona kalitga keltiradi. */
    private static function districtKey(string $raw): string
    {
        $s = mb_strtolower(trim($raw));
        $s = (string) preg_replace('/\b(шаҳри|шахри|шаҳар|шахар|shahri|shahar)\b/u', ' ~ш ', $s);
        $s = (string) preg_replace('/\b(тумани|туман|tumani|tuman)\b/u', ' ~т ', $s);

        // Turi ko'rsatilmagan bo'lsa tuman deb qabul qilinadi — manbalarda
        // shahar har doim aniq belgilanadi, tuman esa tushib qolishi mumkin.
        if (! str_contains($s, '~')) {
            $s .= ' ~т';
        }

        return str_replace(' ', '', MahallaMatcher::fold($s));
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

    private function text(mixed $v): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }
}
