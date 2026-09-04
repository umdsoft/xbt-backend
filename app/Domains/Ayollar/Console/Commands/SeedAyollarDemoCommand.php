<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Console\Commands;

use App\Domains\Ayollar\Models\Anketa;
use App\Domains\Ayollar\Models\Household;
use App\Domains\Ayollar\Models\Woman;
use App\Domains\Ayollar\Services\AnketaService;
use App\Domains\Ayollar\Services\BalanceCalculator;
use App\Domains\Ayollar\Services\BalanceRefresher;
use App\Domains\Ayollar\Services\CategoryResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * NAMOYISH ma'lumoti — interfeysni haqiqiy raqamlar bilan ko'rish uchun.
 *
 * BARCHA MA'LUMOT SUN'IY. Ismlar «DEMO» prefiksi bilan, JShShIR esa
 * ataylab yaroqsiz diapazonda (`99...`) — ular tasodifan haqiqiy shaxsga
 * to'g'ri kelmasligi kerak.
 *
 * ISHLAB CHIQARISHDA YURITILMAYDI: buyruq `APP_ENV=production` da darhol
 * to'xtaydi. Namoyish ma'lumoti prod reyestriga tushsa, u balansga
 * qo'shilib, hisobotni buzardi va uni ajratib olish qiyin bo'lardi.
 */
class SeedAyollarDemoCommand extends Command
{
    protected $signature = 'ayollar:demo
        {--mahallas=6 : Nechta MFY to‘ldirilsin}
        {--per-mahalla=40 : Har MFY uchun ayollar soni}
        {--fresh : Avvalgi DEMO yozuvlarini o‘chirib, qaytadan yaratadi}';

    protected $description = 'Namoyish uchun sun’iy anketa va balans ma’lumotini yaratadi (faqat lokal).';

    /** Toifa taqsimoti — haqiqiy hayotga yaqin nisbat (≈68% yashil). */
    private const EMPLOYMENT = [
        'rasmiy_davlat' => 10, 'rasmiy_xususiy' => 12, 'rasmiy_yatt' => 6,
        'ozini_ozi_band' => 8, 'fermer_dehqon' => 4, 'yoshga_doir_nafaqa' => 6,
        'norasmiy_band' => 12, 'ishsiz' => 10, 'uy_bekasi' => 14,
        'jiem' => 3, 'mehnatga_layoqatsiz' => 3, 'yoq' => 12,
    ];

    private const EDUCATION = ['yoq' => 70, 'oliy' => 10, 'professional' => 8, 'maktab' => 8, 'abituriyent' => 4];

    public function handle(
        AnketaService $anketaService,
        CategoryResolver $resolver,
        BalanceCalculator $calculator,
    ): int {
        if (app()->environment('production')) {
            $this->error('Namoyish ma’lumoti ishlab chiqarishda yaratilmaydi.');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->cleanup();
        }

        $mahallaCount = (int) $this->option('mahallas');
        $perMahalla = (int) $this->option('per-mahalla');

        $mahallas = DB::connection('master')->table('mahallas')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->limit($mahallaCount)
            ->get(['id', 'district_id', 'soato_code', 'name_lat']);

        if ($mahallas->isEmpty()) {
            $this->error('master.mahallas bo‘sh.');

            return self::FAILURE;
        }

        // Tuman raqami (1–13) — ro'yxat raqamining 2 xonali qismi.
        $districtCodes = DB::connection('master')->table('districts')->pluck('sort_order', 'id');

        $bar = $this->output->createProgressBar($mahallas->count() * $perMahalla);
        $bar->start();

        // KECHIKTIRILGAN rejim: 360 ta saqlashning har biri viloyat
        // balansini qayta hisoblasa, u 360 marta bir xil natija bilan
        // yangilanardi. Bu blokda o'zgargan MFY'lar to'planadi va
        // oxirida BIR marta yuviladi.
        app(BalanceRefresher::class)->defer(function () use (
            $mahallas, $perMahalla, $anketaService, $resolver, $districtCodes, $bar
        ): void {
            foreach ($mahallas as $mahalla) {
                for ($i = 0; $i < $perMahalla; $i++) {
                    $this->makeOne(
                        $anketaService,
                        $resolver,
                        (string) $mahalla->id,
                        (string) $mahalla->district_id,
                        (string) ($districtCodes[$mahalla->district_id] ?? '0'),
                        (string) ($mahalla->soato_code ?? '0'),
                    );
                    $bar->advance();
                }
            }
        });

        $bar->finish();
        $this->newLine(2);

        // Balanslarni hisoblaymiz — aks holda boshqaruv paneli bo'sh
        // ko'rinardi va «ma'lumot yaratildimi?» degan shubha qolardi.
        $this->info('Balanslar hisoblanmoqda…');

        $year = (int) now()->year;
        $month = (int) now()->month;

        foreach ($mahallas->pluck('id') as $id) {
            $calculator->calculateMahalla((string) $id, $year, $month);
        }

        foreach ($mahallas->pluck('district_id')->unique() as $districtId) {
            $ids = DB::connection('master')->table('mahallas')
                ->where('district_id', $districtId)->pluck('id')->map(fn ($v) => (string) $v)->all();
            $calculator->calculateDistrict((string) $districtId, $ids, $year, $month);
        }

        $regionId = (string) DB::connection('master')->table('regions')->value('id');
        $districtIds = DB::connection('master')->table('districts')->pluck('id')->map(fn ($v) => (string) $v)->all();
        $region = $calculator->calculateRegion($regionId, $districtIds, $year, $month);

        $this->table(['Ko‘rsatkich', 'Qiymat'], [
            ['Jami', $region->total],
            ['Yashil', $region->green],
            ['Sariq', $region->yellow],
            ['Qizil', $region->red],
            ['Tenglik (yashil+sariq=jami)', $region->green + $region->yellow === $region->total ? 'OK' : 'BUZILDI'],
        ]);

        return self::SUCCESS;
    }

    private function makeOne(
        AnketaService $service,
        CategoryResolver $resolver,
        string $mahallaId,
        string $districtId,
        string $districtCode,
        string $mahallaCode,
    ): void {
        $household = Household::query()->create([
            'mahalla_id' => $mahallaId,
            'district_id' => $districtId,
            'address' => 'DEMO ko‘cha, '.random_int(1, 90).'-uy',
            'in_social_registry' => random_int(1, 10) === 1,
        ]);

        // Yosh taqsimoti: aholi piramidasiga yaqin.
        $age = match (random_int(1, 100)) {
            default => random_int(18, 70),
            1, 2, 3, 4, 5 => random_int(0, 2),
            6, 7, 8, 9, 10 => random_int(3, 6),
            11, 12, 13, 14, 15, 16, 17, 18, 19, 20 => random_int(7, 17),
        };

        $woman = new Woman;
        $woman->fill([
            'household_id' => $household->id,
            'mahalla_id' => $mahallaId,
            'district_id' => $districtId,
            'full_name' => 'DEMO Ayol '.Str::upper(Str::random(5)),
            'full_name_norm' => 'demo ayol',
            'birth_date' => now()->subYears($age)->subDays(random_int(0, 300)),
            'age_group' => $resolver->ageGroup($age),
            'consent_signed_at' => now(),
        ]);
        // ATAYLAB yaroqsiz diapazon: `99` bilan boshlanadigan JShShIR yo'q.
        $woman->pinfl = '99'.str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT);
        $woman->save();

        $service->save(
            $woman,
            $this->answersFor($age),
            ['status' => Anketa::STATUS_COMPLETED, 'device_id' => 'DEMO'],
            $districtCode,
            $mahallaCode,
        );
    }

    /** @return array<string, mixed> */
    private function answersFor(int $age): array
    {
        $answers = [];

        for ($q = 1; $q <= 6; $q++) {
            $answers["q{$q}"] = 'demo';
        }

        if ($age >= 7) {
            $answers['q12'] = $age <= 17 ? 'maktab' : $this->weighted(self::EDUCATION);
        }

        if ($age >= 18) {
            $answers['q11'] = $this->weighted(self::EMPLOYMENT);
            $answers['q13'] = random_int(1, 12) === 1 ? 'tashqi' : 'yoq';
            // Ajrashgan/beva ulushi ≈12% — haqiqiy statistikaga yaqin.
            // Yuqoriroq qilinsa, namoyish paneli tashvishli ko'rinib,
            // taqdimotda noto'g'ri xulosaga olib kelardi.
            $answers['q7'] = random_int(1, 100) <= 12
                ? (random_int(1, 2) === 1 ? 'ajrashgan' : 'beva')
                : 'turmushda';
            $answers['q9'] = random_int(1, 14) === 1;
            $answers['q10'] = random_int(1, 20) === 1;
            $answers['q23'] = random_int(1, 8) === 1;
            $answers['q27'] = random_int(1, 9) === 1;

            // «Istak» savollari — ehtiyojlar xaritasi uchun.
            $answers['q16'] = ['Kasb-hunar kursi', 'Mikroqarz', 'Ish o‘rni', 'Bolalar bog‘chasi'][random_int(0, 3)];
            $answers['q17'] = ['Tikuvchilik', 'Sartaroshlik', 'Oshpazlik', 'IT', 'Qishloq xo‘jaligi'][random_int(0, 4)];
            $answers['q21'] = ['Tibbiy ko‘rik', 'Sanatoriy', 'Kerak emas'][random_int(0, 2)];

            if (random_int(1, 25) === 1) {
                $answers['q30'] = ['prevention' => true];
            }
            if (random_int(1, 30) === 1) {
                $answers['q31'] = ['violence' => true, 'protection_order' => random_int(1, 3) === 1];
            }
        }

        return $answers;
    }

    /** @param array<string, int> $weights */
    private function weighted(array $weights): string
    {
        $total = array_sum($weights);
        $roll = random_int(1, $total);

        foreach ($weights as $key => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $key;
            }
        }

        return array_key_first($weights);
    }

    /** DEMO yozuvlarini o'chiradi — haqiqiy ma'lumotga TEGMAYDI. */
    private function cleanup(): void
    {
        $ids = Woman::query()->where('full_name', 'like', 'DEMO %')->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        $anketaIds = Anketa::query()->whereIn('woman_id', $ids)->pluck('id');

        DB::connection('ayollar')->table('anketa_red_flags')->whereIn('anketa_id', $anketaIds)->delete();
        Anketa::query()->whereIn('id', $anketaIds)->forceDelete();

        $householdIds = Woman::query()->whereIn('id', $ids)->pluck('household_id');
        Woman::query()->whereIn('id', $ids)->forceDelete();
        Household::query()->whereIn('id', $householdIds)->delete();

        $this->warn("O‘chirildi: {$ids->count()} DEMO yozuv.");
    }
}
