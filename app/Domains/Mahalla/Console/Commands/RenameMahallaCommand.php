<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Console\Commands;

use App\Domains\Mahalla\Support\MahallaMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mahalla nomini rasman o'zgartiradi va ESKI NOMNI saqlab qoladi.
 *
 * Nom o'zgargach, tashqi fayllar (statistika, kambag'allik ro'yxati) bir muddat
 * eski nom bilan kelishda davom etadi. Eski nom saqlanmasa, har import'da
 * "bu qaysi mahalla edi?" degan qidiruv takrorlanadi.
 */
class RenameMahallaCommand extends Command
{
    protected $signature = 'mahalla:rename
                            {soato : Mahalla SOATO kodi}
                            {name : Yangi nom (kirill)}
                            {--apply : O\'zgarishni bazaga yozadi}';

    protected $description = 'Mahalla nomini o\'zgartiradi, eski nomni alias sifatida saqlaydi';

    public function handle(): int
    {
        $soato = (string) $this->argument('soato');
        $newName = trim((string) $this->argument('name'));

        $m = DB::connection('master')->table('mahallas')
            ->where('soato_code', $soato)->first(['id', 'name_cyr', 'district_id']);

        if ($m === null) {
            $this->error("Маҳалла топилмади: SOATO {$soato}");

            return self::FAILURE;
        }

        $this->line("  эски ном : {$m->name_cyr}");
        $this->line("  янги ном : {$newName}");

        if (! $this->option('apply')) {
            $this->warn('  (--apply берилмагани учун ёзилмади)');

            return self::SUCCESS;
        }

        DB::connection('master')->transaction(function () use ($m, $newName) {
            // Eski nom alias sifatida saqlanadi — kelajakdagi importlar uni
            // ham taniydi.
            DB::connection('master')->table('mahalla_aliases')->updateOrInsert(
                ['mahalla_id' => $m->id, 'normalized' => MahallaMatcher::normalize($m->name_cyr)],
                [
                    'id' => (string) Str::uuid(),
                    'name_cyr' => $m->name_cyr,
                    'source' => 'former',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );

            DB::connection('master')->table('mahallas')
                ->where('id', $m->id)
                ->update(['name_cyr' => $newName, 'updated_at' => now()]);
        });

        $this->info('  ✔ ном ўзгартирилди, эски ном alias сифатида сақланди');

        return self::SUCCESS;
    }
}
