<?php

declare(strict_types=1);

namespace App\Domains\Sport\Console\Commands;

use App\Domains\Mahalla\Support\MahallaMatcher;
use App\Domains\Sport\Models\Trainer;
use App\Domains\Sport\Models\TrainerMahalla;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * «Маҳаллаларга бириктирилган селекционер тренерлар» CSV importi (build_trainers_csv.py).
 *
 * Trenerlarni trainer_seq bo'yicha guruhlab yaratadi, mahalla nomini
 * MahallaMatcher (tuman doirasida) bilan master.mahallas'ga bog'laydi. Har
 * importda avvalgi ma'lumot tozalanadi (idempotent). PII CSV'да yo'q.
 */
class ImportTrainersCommand extends Command
{
    protected $signature = 'sport:import-trainers {csv : trainers_clean.csv yo\'li} {--fresh : avval jadvallarni tozalash}';

    protected $description = 'Sport trenerlar + mahalla biriktirishlarini CSV\'dan import qiladi (master\'ga bog\'lab)';

    public function handle(MahallaMatcher $matcher): int
    {
        $path = $this->argument('csv');
        if (! is_file($path)) {
            $this->error("CSV topilmadi: {$path}");

            return self::FAILURE;
        }

        $rows = $this->readCsv($path);
        if ($rows === []) {
            $this->error('CSV bo\'sh.');

            return self::FAILURE;
        }

        $districts = $this->districtIndex();
        $this->info('Tuman indeksi: '.count($districts).' ta');

        // To'liq qayta import — avval tozalash (idempotent).
        DB::connection('sport')->table('trainer_mahallas')->delete();
        DB::connection('sport')->table('trainers')->delete();

        $grouped = [];
        foreach ($rows as $r) {
            $grouped[$r['trainer_seq']][] = $r;
        }

        $trainerCount = 0;
        $assignCount = 0;
        $matched = 0;
        $unmatched = [];
        $duplicates = [];
        $seenMahalla = [];  // mahalla_id => true (bir mahalla — bir trener)

        foreach ($grouped as $seq => $items) {
            $first = $items[0];
            $districtId = $this->matchDistrict($districts, $first['district_name']);

            $trainer = Trainer::create([
                'district_id' => $districtId,
                'full_name' => $first['full_name'] ?: 'Номаълум',
                'phone' => $first['phone'] ?: null,
                'birth_date' => $this->parseDate($first['birth_date']),
                'age' => $this->intOrNull($first['age']),
                'workplace' => $first['workplace'] ?: null,
                'sport_type' => $first['sport_type'] ?: null,
                'specialization_raw' => $first['specialization_raw'] ?: null,
                'staff_unit' => $this->floatOrNull($first['staff_unit']),
                'uniform_size' => $first['uniform_size'] ?: null,
                'other_workplace' => $first['other_workplace'] ?: null,
                'source' => 'xlsx:1-JADVAL',
            ]);
            $trainerCount++;

            foreach ($items as $it) {
                $mahallaId = null;
                if ($districtId !== null && $it['mahalla_name'] !== '') {
                    $mahallaId = $matcher->forDistrict($districtId)->match($it['mahalla_name'])
                        ?? $this->fuzzyMatch($districtId, $it['mahalla_name'], $seenMahalla);
                }
                // Bir mahalla bir necha trenerга yozilса — unique buzilmasin: keyingisi
                // null-link bo'lib saqlanadi (xom nom qoladi), dublikat sifatida sanaladi.
                if ($mahallaId !== null && isset($seenMahalla[$mahallaId])) {
                    $duplicates[] = $first['district_name'].' / '.$it['mahalla_name'];
                    $mahallaId = null;
                } elseif ($mahallaId !== null) {
                    $seenMahalla[$mahallaId] = true;
                    $matched++;
                } elseif ($it['mahalla_name'] !== '') {
                    $unmatched[] = $first['district_name'].' / '.$it['mahalla_name'];
                }

                TrainerMahalla::create([
                    'trainer_id' => $trainer->id,
                    'mahalla_id' => $mahallaId,
                    'mahalla_name_raw' => $it['mahalla_name'],
                    'district_id' => $districtId,
                    'schools' => $it['schools'] ?: null,
                    'sport_objects_count' => $this->intOrNull($it['sport_objects_count']),
                    'youth_7_17' => $this->floatOrNull($it['youth_7_17']),
                    'youth_7_30' => $this->floatOrNull($it['youth_7_30']),
                    'youth_14_30' => $this->floatOrNull($it['youth_14_30']),
                    'youth_16_30' => $this->floatOrNull($it['youth_16_30']),
                    'youth_30_50' => $this->floatOrNull($it['youth_30_50']),
                    'pop_30plus' => $this->floatOrNull($it['pop_30plus']),
                    'pop_total' => $this->intOrNull($it['pop_total']),
                    'plan_2026' => $this->floatOrNull($it['plan_2026']),
                    'plan_percent' => $this->floatOrNull($it['plan_percent']),
                ]);
                $assignCount++;
            }
        }

        $this->info("Trenerlar: {$trainerCount}");
        $this->info("Biriktirishlar: {$assignCount} | mos: {$matched} | mos EMAS: ".count($unmatched)." | dublikat mahalla: ".count($duplicates));
        if ($duplicates !== []) {
            $this->warn('Bir necha trenerга yozilган mahallalar (birinchi 10):');
            foreach (array_slice(array_unique($duplicates), 0, 10) as $d) {
                $this->line('  '.$d);
            }
        }
        if ($unmatched !== []) {
            $this->warn('Moslanmagan mahallalar (birinchi 15):');
            foreach (array_slice(array_unique($unmatched), 0, 15) as $u) {
                $this->line('  '.$u);
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function readCsv(string $path): array
    {
        $fh = fopen($path, 'r');
        $header = fgetcsv($fh);
        $out = [];
        while (($row = fgetcsv($fh)) !== false) {
            if ($row === [null] || $row === false) {
                continue;
            }
            $out[] = array_combine($header, array_map(fn ($v) => trim((string) $v), $row));
        }
        fclose($fh);

        return $out;
    }

    /**
     * Tuman nomi -> master.districts.id. Nom "тумани/шаҳри/т" qo'shimchalaridan
     * tozalanib, MahallaMatcher::fold (unli tebranish) bilan solishtiriladi.
     *
     * @return array<string, string>  folded => district_id
     */
    private function districtIndex(): array
    {
        $rows = DB::connection('master')->table('districts')->get(['id', 'name_cyr', 'name_lat']);
        $idx = [];
        foreach ($rows as $d) {
            foreach ([$d->name_cyr, $d->name_lat] as $n) {
                if ($n) {
                    $idx[$this->districtKey((string) $n)] = $d->id;
                }
            }
        }

        return $idx;
    }

    private function matchDistrict(array $idx, string $name): ?string
    {
        return $idx[$this->districtKey($name)] ?? null;
    }

    /** @var array<string, array<int, array{id: string, norm: string}>> */
    private array $mahallaCache = [];

    /**
     * Konservativ zaxira moslash: qisqartma nomlar ("П.Махмуд", "М.Хоразмий")
     * uchun eng uzun ma'noli so'z bo'yicha tuman ichidan YAGONA mos qidiradi
     * (ko'p mos bo'lsa — null, taxmin qilmaymiz). Band mahalla o'tkazib yuboriladi.
     *
     * @param  array<string, bool>  $seen
     */
    private function fuzzyMatch(string $districtId, string $raw, array $seen): ?string
    {
        if (! isset($this->mahallaCache[$districtId])) {
            $rows = DB::connection('master')->table('mahallas')
                ->where('district_id', $districtId)->get(['id', 'name_cyr']);
            $this->mahallaCache[$districtId] = $rows
                ->map(fn ($m) => ['id' => $m->id, 'norm' => MahallaMatcher::normalize((string) $m->name_cyr)])
                ->all();
        }

        $norm = MahallaMatcher::normalize($raw);
        $tokens = array_filter(explode(' ', $norm), fn ($t) => mb_strlen($t) >= 4);
        if ($tokens === []) {
            return null;
        }
        usort($tokens, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $key = $tokens[0];

        $hits = [];
        foreach ($this->mahallaCache[$districtId] as $m) {
            if (! isset($seen[$m['id']]) && str_contains(' '.$m['norm'].' ', $key)) {
                $hits[$m['id']] = true;
            }
        }

        return count($hits) === 1 ? array_key_first($hits) : null;
    }

    /**
     * Tuman nomini bir ko'rinishga: qo'shimchalar olib tashlanib, fold qilinadi.
     * MUHIM: shahar va tuman AJRATILADI (#sh/#t) — "Урганч шаҳар" va "Урганч
     * тумани" master'да ALOHIDA tumanlar; birlashtirilsa mahallalar chalkashadi.
     */
    private function districtKey(string $name): string
    {
        $s = mb_strtolower(trim($name));
        $isCity = (bool) preg_match('/(шаҳар|шаҳри|шахар|шахри|shahar|shahri)/u', $s);
        $s = (string) preg_replace('/\b(тумани|туман|шаҳри|шаҳар|шахри|шахар|shahri|shahar|tumani|tuman|т|ш)\b/u', ' ', $s);
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));

        return MahallaMatcher::fold($s).($isCity ? '#sh' : '#t');
    }

    private function parseDate(string $v): ?string
    {
        $v = trim($v);
        // FAQAT to'liq dd.mm.yyyy (19xx/20xx) — buzuq/qisman qiymatlar null.
        if (! preg_match('/^\d{1,2}\.\d{1,2}\.(19|20)\d{2}$/', $v)) {
            return null;
        }
        try {
            $d = Carbon::createFromFormat('!d.m.Y', $v);
            $y = (int) $d->format('Y');

            return ($y >= 1940 && $y <= 2015) ? $d->format('Y-m-d') : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function intOrNull(string $v): ?int
    {
        $v = trim($v);

        return $v === '' ? null : (int) $v;
    }

    private function floatOrNull(string $v): ?float
    {
        $v = trim($v);

        return $v === '' ? null : (float) $v;
    }
}
