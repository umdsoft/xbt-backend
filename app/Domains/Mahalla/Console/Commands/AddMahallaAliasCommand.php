<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Console\Commands;

use App\Domains\Mahalla\Support\MahallaMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mahallaga qo'shimcha nom (imlo varianti) qo'shadi.
 *
 * `mahalla:rename` dan farqi: asosiy nom O'ZGARMAYDI. Bu buyruq tashqi
 * fayllarda uchraydigan boshqacha yozilishni qo'shadi — "ИЖТИМОЯТ" va
 * "ИЖТИМОИЯТ", "ЗАМАНДОШ" va "ЗАМОНДОШ" kabi. Bir marta qo'shilsa,
 * keyingi importlar o'zi taniydi.
 */
class AddMahallaAliasCommand extends Command
{
    protected $signature = 'mahalla:alias
                            {soato : Mahalla SOATO kodi}
                            {name : Qo\'shimcha nom (fayllarda uchraydigan)}
                            {--source=variant : variant | former | cadastre}';

    protected $description = 'Mahallaga qo\'shimcha nom (imlo varianti) qo\'shadi';

    public function handle(): int
    {
        $m = DB::connection('master')->table('mahallas')
            ->where('soato_code', (string) $this->argument('soato'))
            ->first(['id', 'name_cyr']);

        if ($m === null) {
            $this->error('Маҳалла топилмади: SOATO '.$this->argument('soato'));

            return self::FAILURE;
        }

        $alias = trim((string) $this->argument('name'));
        $norm = MahallaMatcher::normalize($alias);

        DB::connection('master')->table('mahalla_aliases')->updateOrInsert(
            ['mahalla_id' => $m->id, 'normalized' => $norm],
            [
                'id' => (string) Str::uuid(),
                'name_cyr' => $alias,
                'source' => (string) $this->option('source'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $this->info("✔ {$m->name_cyr}  ←  «{$alias}»");

        return self::SUCCESS;
    }
}
