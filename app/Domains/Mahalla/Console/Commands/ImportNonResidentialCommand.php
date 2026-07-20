<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Console\Commands;

use App\Domains\Mahalla\Support\CadastreObjectClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use ZipArchive;

/**
 * Noturar binolar KMZ'sidan MAQSAD (bino vazifasi) ni import qiladi.
 *
 * Binolar allaqachon bazada (kadastr importida kelgan), lekin `purpose`
 * maydoni bo'sh qolgan — KMZ'da u `description` ichidagi HTML jadvalda
 * yashiringan va dastlabki import uni o'qimagan.
 *
 * Bu buyruq mavjud qatorlarni KADASTR raqami bo'yicha topib to'ldiradi.
 * Nom bo'yicha bog'lanmaydi: KMZ'dagi mahalla nomlari bazadagidan biroz
 * farq qiladi (Катқальа / КАТҚАЛЪА), kadastr raqami esa aniq.
 */
class ImportNonResidentialCommand extends Command
{
    protected $signature = 'cadastre:import-nonresidential
                            {file : KMZ fayl yo\'li}
                            {--apply : O\'zgarishlarni bazaga yozadi}
                            {--district= : Faqat shu SOATO kodli tuman}';

    protected $description = 'Noturar binolar KMZ\'sidan vazifa (MAQSAD) va toifani import qiladi';

    public function handle(CadastreObjectClassifier $classifier): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("Файл топилмади: {$path}");

            return self::FAILURE;
        }

        $this->info('KMZ ўқилмоқда…');

        // Tuman filtri — SOATO orqali, chunki KMZ'dagi tuman nomi turlicha
        // yozilgan ("Шовот тумани", "Ханкинский район" va h.k.).
        $districtId = null;
        if ($soato = $this->option('district')) {
            $districtId = DB::connection('master')->table('districts')
                ->where('soato_code', $soato)->value('id');
            if ($districtId === null) {
                $this->error("Туман топилмади: {$soato}");

                return self::FAILURE;
            }
        }

        $apply = (bool) $this->option('apply');
        $matched = 0;
        $missing = 0;
        $seen = 0;
        $byType = [];

        foreach ($this->streamKmz($path) as $r) {
            $seen++;
            if ($seen % 2000 === 0) {
                $this->line("  ўқилди: {$seen}…");
            }

            $kadastr = trim((string) ($r['KADASTR'] ?? ''));
            $maqsad = trim((string) ($r['MAQSAD'] ?? ''));
            if ($kadastr === '') {
                continue;
            }

            $q = DB::connection('master')->table('buildings')->where('kadastr', $kadastr);
            if ($districtId !== null) {
                $q->where('district_id', $districtId);
            }

            $building = $q->first(['id']);
            if ($building === null) {
                $missing++;

                continue;
            }

            $typeId = $classifier->classify($maqsad);
            $code = $classifier->codeFor($typeId);
            $byType[$code] = ($byType[$code] ?? 0) + 1;
            $matched++;

            if ($apply) {
                DB::connection('master')->table('buildings')
                    ->where('id', $building->id)
                    ->update([
                        // Asl matn o'zgartirilmaydi — u dalil va keyingi
                        // qo'lda tekshirish uchun kerak.
                        'purpose' => $maqsad !== '' ? $maqsad : null,
                        'object_type_id' => $typeId,
                        'updated_at' => now(),
                    ]);
            }
        }

        $this->newLine();
        $this->info(($apply ? 'Ёзилди' : 'Кўрсатилди (--apply берилмаган)').':');
        $this->line("  KMZ да жами       : {$seen}");
        $this->line("  мос келган бинолар : {$matched}");
        $this->line("  базада топилмаган  : {$missing}");

        $this->newLine();
        $this->line('ТОИФАЛАР БЎЙИЧА:');
        $types = DB::connection('master')->table('object_types')
            ->orderBy('sort_order')->get(['code', 'name_cyr', 'is_social'])->keyBy('code');
        arsort($byType);
        foreach ($byType as $code => $n) {
            $t = $types[$code] ?? null;
            $mark = $t && $t->is_social ? ' ★' : '';
            $this->line(sprintf('  %-26s %5d%s', $t->name_cyr ?? $code, $n, $mark));
        }
        $this->newLine();
        $this->line('  ★ — ижтимоий объект');

        return self::SUCCESS;
    }

    /**
     * KMZ ichidagi doc.kml ni OQIM sifatida o'qib, har `<Placemark>` ni
     * alohida qaytaradi.
     *
     * Butun faylni xotiraga yuklab bo'lmaydi: Xorazm bo'yicha doc.kml
     * ~82 MB, `preg_match_all` esa ustiga 32 000 blokdan iborat massiv
     * yaratib, PHP xotira chegarasini oshirib yuboradi. Shuning uchun
     * bo'lak-bo'lak o'qib, to'liq Placemark yig'ilgan zahoti qaytariladi.
     *
     * Atributlar `<description>` ichidagi CDATA HTML jadvalida
     * (`<td>KALIT</td><td>QIYMAT</td>`) yotadi — oddiy KML SimpleData emas.
     *
     * @return \Generator<int, array<string, string>>
     */
    private function streamKmz(string $path): \Generator
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new \RuntimeException("KMZ очилмади: {$path}");
        }

        $kmlName = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_ends_with(strtolower($name), '.kml')) {
                $kmlName = $name;
                break;
            }
        }

        if ($kmlName === null) {
            $zip->close();
            throw new \RuntimeException('KMZ ичида .kml топилмади');
        }

        $stream = $zip->getStream($kmlName);
        if ($stream === false) {
            $zip->close();
            throw new \RuntimeException('KML оқими очилмади');
        }

        $buffer = '';
        try {
            while (! feof($stream)) {
                $buffer .= (string) fread($stream, 1 << 20);

                // Buferdagi barcha TUGALLANGAN Placemark'larni chiqaramiz,
                // qolgan chala qismini keyingi o'qishga qoldiramiz.
                while (($start = strpos($buffer, '<Placemark')) !== false) {
                    $end = strpos($buffer, '</Placemark>', $start);
                    if ($end === false) {
                        break;
                    }

                    $block = substr($buffer, $start, $end - $start + 12);
                    $buffer = substr($buffer, $end + 12);

                    $row = $this->parseBlock($block);
                    if ($row !== []) {
                        yield $row;
                    }
                }

                // Placemark boshlanmagan qismni saqlashning hojati yo'q.
                if (strpos($buffer, '<Placemark') === false && strlen($buffer) > (1 << 20)) {
                    $buffer = substr($buffer, -20);
                }
            }
        } finally {
            fclose($stream);
            $zip->close();
        }
    }

    /**
     * @return array<string, string>
     */
    private function parseBlock(string $block): array
    {
        preg_match_all('/<td>([A-Za-z_0-9]+)<\/td>\s*<td>(.*?)<\/td>/s', $block, $m, PREG_SET_ORDER);

        $row = [];
        foreach ($m as $pair) {
            $row[$pair[1]] = trim(strip_tags($pair[2]));
        }

        return $row;
    }
}
