<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * OPERATSION MA'LUMOTNI TOZALASH.
 *
 * Sinovdan jonli ishga o'tishda kerak: demo anketalar bazada qolib
 * ketsa, ular balansga tushadi va birinchi haqiqiy hisobot NOTO'G'RI
 * chiqadi. Bu esa tuzatib bo'lmaydigan xato — hisobot allaqachon
 * yuqoriga ketgan bo'ladi.
 *
 * NIMA O'CHADI: anketa, ayol, xonadon, qizil belgilar, balanslar,
 * imzolar, ish rejalari, konfliktlar va jurnallar.
 *
 * NIMA QOLADI:
 *   `metric_registry` — balans shakli, bu SOZLAMA
 *   `staff`           — foydalanuvchi doiralari
 *   `auth.*`          — hisoblar
 *   `master.*`        — geografiya va kadastr
 *
 * PRODDA ISHLAMAYDI. `--force` bo'lmasa `APP_ENV=production` da
 * darhol to'xtaydi: bu buyruqni tasodifan jonli bazada yurgizish
 * butun MFY ishini yo'q qilardi.
 */
class PurgeAyollarDataCommand extends Command
{
    protected $signature = 'ayollar:purge
        {--force : Ishlab chiqarish muhitida ham bajarish}
        {--yes : Tasdiqlashni so‘ramaslik}';

    protected $description = 'Ayollar moduli operatsion ma’lumotini o‘chiradi (sozlama va hisoblar qoladi)';

    /**
     * O'chirish TARTIBI — bog'liqlik bo'yicha.
     *
     * Avval bolalar, keyin ota-onalar: `anketa_red_flags` anketaga,
     * anketa ayolga, ayol xonadonga ishora qiladi. Teskari tartibda
     * o'chirish chet el kaliti xatosini berardi.
     *
     * @var array<int, string>
     */
    private const TABLES = [
        'anketa_red_flags',
        'anketas',
        'women',
        'households',
        'balance_signatures',
        'mahalla_balances',
        'district_balances',
        'region_balances',
        'work_plans',
        'sync_conflicts',
        'sensitive_access_log',
        'audit_log',
    ];

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Ishlab chiqarish muhiti. Rostdan kerak bo‘lsa --force qo‘shing.');

            return self::FAILURE;
        }

        $counts = $this->counts();
        $total = array_sum($counts);

        if ($total === 0) {
            $this->info('Tozalanadigan yozuv yo‘q.');

            return self::SUCCESS;
        }

        $this->table(['Jadval', 'Yozuv'], array_map(
            fn ($t, $c) => [$t, $c],
            array_keys($counts),
            array_values($counts),
        ));

        if (! $this->option('yes') && ! $this->confirm("Jami {$total} ta yozuv o‘chiriladi. Davom etamizmi?")) {
            $this->warn('Bekor qilindi.');

            return self::SUCCESS;
        }

        // BITTA TRANZAKSIYA: yarim tozalangan baza to'liq tozalanmagan
        // bazadan yomonroq — balanslar o'chib, anketalar qolib ketsa,
        // hisobot mutlaqo tushunarsiz bo'lardi.
        DB::connection('ayollar')->transaction(function (): void {
            foreach (self::TABLES as $table) {
                DB::connection('ayollar')->table($table)->delete();
            }
        });

        $this->newLine();
        $this->info("Tozalandi: {$total} ta yozuv.");
        $this->line('Qolgani: metric_registry (balans shakli), staff (doiralar), auth va master sxemalari.');

        return self::SUCCESS;
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $out = [];

        foreach (self::TABLES as $table) {
            $n = (int) DB::connection('ayollar')->table($table)->count();

            if ($n > 0) {
                $out[$table] = $n;
            }
        }

        return $out;
    }
}
