<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Console\Commands;

use App\Domains\Mahalla\Support\UzbekTransliterator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Mahalla nomlarini lotindan kirillga o'giradi.
 *
 * Kadastr manbasi (KMZ) nomlarni lotin yozuvida bergan, tizimning qolgan
 * qismi esa kirillda — rahbar ekranida aralash yozuv chiqardi.
 *
 * Asl lotin `name_lat` da saqlanadi, ya'ni amal qaytariladi. Sukut bo'yicha
 * faqat KO'RSATADI; yozish uchun `--apply` kerak.
 */
class CyrillicizeMahallaNamesCommand extends Command
{
    protected $signature = 'mahalla:cyrillicize-names
                            {--apply : O\'zgarishlarni bazaga yozadi (usiz faqat ko\'rsatadi)}
                            {--district= : Faqat shu SOATO kodli tuman}';

    protected $description = 'Mahalla nomlarini lotindan kirillga o\'giradi';

    public function handle(): int
    {
        $q = DB::connection('master')->table('mahallas');

        if ($soato = $this->option('district')) {
            $districtId = DB::connection('master')->table('districts')
                ->where('soato_code', $soato)->value('id');
            if ($districtId === null) {
                $this->error("Туман топилмади: {$soato}");

                return self::FAILURE;
            }
            $q->where('district_id', $districtId);
        }

        $rows = $q->orderBy('name_cyr')->get(['id', 'name_cyr', 'name_lat']);
        $apply = (bool) $this->option('apply');

        $changed = 0;
        $normalized = [];
        $skipped = 0;

        foreach ($rows as $r) {
            $source = (string) $r->name_cyr;

            // Allaqachon kirill bo'lsa tegilmaydi — buyruq qayta yurgizilsa
            // nomlarni ikkinchi marta "o'girib" buzib qo'ymasligi kerak.
            if (preg_match('/[А-Яа-яЁёЎўҚқҒғҲҳ]/u', $source) === 1) {
                $skipped++;

                continue;
            }

            $cyr = UzbekTransliterator::toCyrillic($source);

            // Manbada registr buzuq bo'lsa (so'z ichida kichikdan keyin katta
            // harf: "Oqko'L", "Qal'A"), natija ham buzuq chiqadi. Bunday
            // nomlar to'liq katta registrga keltiriladi — qolgan 500+ nom
            // shu ko'rinishda.
            if (self::hasBrokenCase($source)) {
                $cyr = mb_strtoupper($cyr);
                $normalized[] = "{$source}  ->  {$cyr}";
            }

            if ($apply) {
                DB::connection('master')->table('mahallas')
                    ->where('id', $r->id)
                    ->update([
                        'name_cyr' => $cyr,
                        // Asl lotin yozuv saqlanadi (bo'sh bo'lsa to'ldiriladi).
                        'name_lat' => $r->name_lat ?: $source,
                        'updated_at' => now(),
                    ]);
            }

            $changed++;
        }

        $this->info(($apply ? 'Ёзилди' : 'Кўрсатилди (--apply берилмаган)').": {$changed} та");
        $this->line("  ўзгармаган (аллақачон кирилл): {$skipped} та");

        if ($normalized !== []) {
            $this->newLine();
            $this->warn('Манбадаги регистри бузуқ номлар катта регистрга келтирилди:');
            foreach ($normalized as $line) {
                $this->line('  '.$line);
            }
        }

        return self::SUCCESS;
    }

    /** So'z ichida kichik harfdan keyin katta harf bormi? */
    private static function hasBrokenCase(string $name): bool
    {
        return preg_match('/\p{Ll}[\x{02BB}\x{02BC}\x{2018}\x{2019}\']?\p{Lu}/u', $name) === 1;
    }
}
