<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Yoshlar\Models\Youth;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * FAQAT LOKAL SINOV UCHUN sun'iy reyestr ma'lumoti.
 *
 * NEGA KERAK: bo'sh dashboard'ni baholab bo'lmaydi — 1 ta yozuvda diagramma
 * ham, reyting ham, rang kodlash ham ma'nosini yo'qotadi. Interfeys real
 * hajmda qanday ko'rinishini ko'rish uchun yetarli ma'lumot kerak.
 *
 * MA'LUMOT SUN'IY: ismlar keng tarqalgan o'zbek ismlaridan tasodifiy
 * yig'iladi, PINFL/pasport esa ataylab YAROQSIZ formatda (`9` bilan
 * boshlanadi — real PINFL 1..6 bilan boshlanadi). Ya'ni bu yozuvlar
 * hech bir real shaxsga tegishli emas va real bazaga ham tushmaydi.
 *
 * PRODUCTION'DA ISHLATILMAYDI: `run()` boshida muhit tekshiriladi.
 */
class YoshlarDemoSeeder extends Seeder
{
    private const FIRST_M = ['Aziz', 'Bekzod', 'Doston', 'Elyor', 'Farrux', 'Gʻayrat', 'Jasur', 'Kamron', 'Lazizbek', 'Muhammad', 'Nodirbek', 'Otabek', 'Rustam', 'Sardor', 'Temur', 'Ulugʻbek', 'Shohruh', 'Islom'];

    private const FIRST_F = ['Aziza', 'Barno', 'Dilnoza', 'Feruza', 'Gulnora', 'Hilola', 'Iroda', 'Kamola', 'Laylo', 'Malika', 'Nigora', 'Ozoda', 'Rayhona', 'Sevara', 'Shahnoza', 'Umida', 'Zilola', 'Nilufar'];

    private const LAST = ['Abdullayev', 'Bozorov', 'Davletov', 'Ergashev', 'Fayzullayev', 'Gʻaniyev', 'Hasanov', 'Ibragimov', 'Jumaniyozov', 'Karimov', 'Matyoqubov', 'Norboyev', 'Otajonov', 'Qurbonov', 'Ruzmetov', 'Safarov', 'Toshpoʻlatov', 'Yusupov', 'Xudoyberganov', 'Allaberganov'];

    private const MIDDLE = ['oʻgʻli', 'qizi'];

    /**
     * Har tuman uchun nechta yozuv — teng emas: Urganch shahri kabi yirik
     * hududda ko'proq. Reyting sahifasi bir xil sonlarda mazmunsiz bo'lardi.
     */
    private const PER_DISTRICT_MIN = 18;

    private const PER_DISTRICT_MAX = 64;

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('YoshlarDemoSeeder production muhitida ishlamaydi.');

            return;
        }

        $districts = DB::connection('master')->table('districts')
            ->select('id', 'name_lat')->orderBy('name_lat')->get();

        if ($districts->isEmpty()) {
            $this->command?->error('master.districts boʻsh — avval kadastr importini bajaring.');

            return;
        }

        $orgByDistrict = DB::connection('yoshlar')->table('organizations')
            ->whereNotNull('district_id')->pluck('id', 'district_id');

        // `mahalla_id` NOT NULL — reyestr yozuvi doim aniq mahallaga tegishli.
        // Tuman boʻyicha real mahallalar roʻyxatini oldindan olib qoʻyamiz,
        // aks holda har yozuv uchun alohida soʻrov ketardi (N+1).
        $mahallasByDistrict = DB::connection('master')->table('mahallas')
            ->select('id', 'district_id')->get()
            ->groupBy('district_id')
            ->map(fn ($rows) => $rows->pluck('id')->all());

        // Tasodifiylik URUGʻI QAT'IY: seeder qayta ishga tushirilganda bir xil
        // manzara chiqadi, ya'ni dizayn o'zgarishlarini solishtirish mumkin.
        mt_srand(20260823);

        // IDEMPOTENT: reyestr allaqachon to'lgan bo'lsa qayta yaratilmaydi.
        // Aks holda seederni ikkinchi marta chaqirish yozuvlarni IKKILANTIRARDI
        // (PINFL serial nomeri nolga qaytadi, lekin hash boshqa — unique
        // indeks ham to'xtata olmasdi).
        if (Youth::query()->count() >= self::PER_DISTRICT_MIN * $districts->count()) {
            $this->command?->info('Reyestr allaqachon toʻldirilgan — faqat otaliq bosqichi.');
            $this->seedPatronage();

            return;
        }

        $created = 0;

        foreach ($districts as $district) {
            $count = mt_rand(self::PER_DISTRICT_MIN, self::PER_DISTRICT_MAX);

            for ($i = 0; $i < $count; $i++) {
                $mahallas = $mahallasByDistrict[$district->id] ?? [];
                if ($mahallas === []) {
                    continue;
                }

                $this->makeYouth(
                    $district->id,
                    $mahallas[array_rand($mahallas)],
                    $orgByDistrict[$district->id] ?? null,
                    $created,
                );
                $created++;
            }
        }

        $this->command?->info("Sunʼiy reyestr: {$created} ta yozuv, {$districts->count()} tumanda.");

        $this->seedPatronage();
    }

    /**
     * Otaliq — REYESTR BAYROGʻI EMAS, alohida yozuv.
     *
     * Har biriktirish uchun jurnal yozuvlari ham yaratiladi, aks holda
     * «faollik» koʻrsatkichi 0% boʻlib qolardi va rahbariyat paneli
     * oʻzi bilan ziddiyatga tushardi.
     */
    private function seedPatronage(): void
    {
        // Murabbiy — tashkilotga, tashkilot esa tumanga bogʻlangan.
        $mentors = DB::connection('yoshlar')->table('staff as s')
            ->join('organizations as o', 'o.id', '=', 's.org_id')
            ->where('s.is_active', true)
            ->whereNotNull('o.district_id')
            ->get(['s.id as staff_id', 's.user_id', 'o.district_id']);

        if ($mentors->isEmpty()) {
            $this->command?->warn('Otaliq oʻtkazib yuborildi: tumanga bogʻlangan faol xodim yoʻq.');

            return;
        }

        $pairs = 0;
        $logs = 0;

        foreach ($mentors as $mentor) {
            // Faqat NEET yoshlar biriktiriladi — otaliqning maqsadi shu.
            $youth = Youth::query()
                ->where('district_id', $mentor->district_id)
                ->where('is_neet', true)
                ->where('verification_status', 'verified')
                ->where('in_patronage', false)
                ->limit(mt_rand(4, 10))
                ->get();

            foreach ($youth as $item) {
                $startedAt = now()->subDays(mt_rand(30, 300));

                $patronageId = (string) \Illuminate\Support\Str::uuid7();

                DB::connection('yoshlar')->table('patronage')->insert([
                    'id' => $patronageId,
                    'youth_id' => $item->id,
                    'mentor_staff_id' => $mentor->staff_id,
                    'district_id' => $mentor->district_id,
                    'started_at' => $startedAt,
                    'is_active' => true,
                    'created_by' => $mentor->user_id,
                    'created_at' => $startedAt,
                    'updated_at' => now(),
                ]);

                $item->forceFill(['in_patronage' => true])->saveQuietly();
                $pairs++;

                // Uchdan biri ataylab «sovuq»: 30 kundan beri yozuvsiz.
                // Faollik 100% boʻlsa koʻrsatkichning maʼnosi qolmasdi.
                $recent = mt_rand(0, 2) > 0;

                for ($i = 0, $n = mt_rand(1, 4); $i < $n; $i++) {
                    DB::connection('yoshlar')->table('patronage_logs')->insert([
                        'id' => (string) \Illuminate\Support\Str::uuid7(),
                        'patronage_id' => $patronageId,
                        'log_date' => $recent
                            ? now()->subDays(mt_rand(1, 28))->toDateString()
                            : now()->subDays(mt_rand(45, 200))->toDateString(),
                        'kind' => ['uchrashuv', 'harakat', 'izoh'][mt_rand(0, 2)],
                        'note' => 'Sunʼiy sinov yozuvi.',
                        'created_by' => $mentor->user_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $logs++;
                }
            }
        }

        $this->command?->info("Otaliq: {$pairs} biriktirish, {$logs} jurnal yozuvi.");
    }

    private function makeYouth(string $districtId, string $mahallaId, ?string $orgId, int $serial): void
    {
        $female = mt_rand(0, 100) < 48;
        $first = $female ? self::FIRST_F[array_rand(self::FIRST_F)] : self::FIRST_M[array_rand(self::FIRST_M)];

        // Yosh taqsimoti tekis emas: 18–22 oraligʻi eng katta guruh, chunki
        // reyestrga koʻpincha oʻqishni tugatgan yoshlar tushadi.
        $age = match (true) {
            mt_rand(0, 100) < 22 => mt_rand(14, 17),
            mt_rand(0, 100) < 55 => mt_rand(18, 22),
            mt_rand(0, 100) < 75 => mt_rand(23, 26),
            default => mt_rand(27, 30),
        };

        $birth = now()->subYears($age)->subDays(mt_rand(0, 364))->toDateString();

        [$education, $employment] = $this->statuses($age);
        $neet = $education === 'oqimaydi' && in_array($employment, ['band_emas', 'migratsiya'], true);

        // Tasdiq holati: koʻpi tasdiqlangan, kichik qismi navbatda —
        // «Tasdiq kutmoqda» kartochkasi nolga tushib qolmasin.
        $roll = mt_rand(0, 100);
        $verification = $roll < 84 ? 'verified' : ($roll < 96 ? 'pending' : 'rejected');

        Youth::query()->create([
            'last_name' => self::LAST[array_rand(self::LAST)],
            'first_name' => $first,
            'middle_name' => self::LAST[array_rand(self::LAST)].' '.($female ? self::MIDDLE[1] : self::MIDDLE[0]),
            'birth_date' => $birth,
            'gender' => $female ? 'ayol' : 'erkak',
            'district_id' => $districtId,
            'mahalla_id' => $mahallaId,
            'address' => mt_rand(1, 40).'-mahalla, '.mt_rand(1, 120).'-uy',
            'phone' => '+9989'.mt_rand(0, 9).mt_rand(1000000, 9999999),

            // Ataylab yaroqsiz: real PINFL 9 bilan boshlanmaydi.
            'pinfl' => '9'.str_pad((string) $serial, 13, '0', STR_PAD_LEFT),
            'passport_series' => 'ZZ',
            'passport_number' => str_pad((string) (9000000 + $serial), 7, '0', STR_PAD_LEFT),

            'education_status' => $education,
            'employment_status' => $employment,
            'is_neet' => $neet,
            'is_graduate_unemployed' => $education === 'bitiruvchi' && $employment === 'band_emas',
            // `in_patronage` BU YERDA qo'yilmaydi: u `patronage` jadvalining
            // hosilasi. Bayroqni qo'lda yoqish denormalizatsiya siljishiga
            // olib kelardi — «Otaliqda 31 / faollik 0%» kabi qarama-qarshi
            // ko'rsatkich. Ikkinchi bosqichda real yozuv bilan birga yoqiladi.
            'in_patronage' => false,
            'in_youth_book' => $neet && mt_rand(0, 100) < 30,
            'is_entrepreneur' => $employment === 'tadbirkor',

            'registry_status' => 'active',
            'verification_status' => $verification,
            'verified_at' => $verification === 'verified' ? now()->subDays(mt_rand(1, 200)) : null,
            'created_by_org_id' => $orgId,
        ]);
    }

    /**
     * Ta'lim va bandlik BOG'LIQ tanlanadi: 15 yoshli «tadbirkor» yoki
     * maktab o'quvchisi «rasman band» bo'lishi mantiqsiz bo'lardi va
     * diagrammalar ishonchsiz ko'rinardi.
     *
     * @return array{0: string, 1: string}
     */
    private function statuses(int $age): array
    {
        if ($age <= 17) {
            return ['maktab', 'oqiydi'];
        }

        if ($age <= 22) {
            return match (mt_rand(0, 9)) {
                0, 1, 2 => ['kollej', 'oqiydi'],
                3, 4, 5 => ['otm', 'oqiydi'],
                6, 7 => ['bitiruvchi', 'band_emas'],
                8 => ['bitiruvchi', 'band'],
                default => ['oqimaydi', 'band_emas'],
            };
        }

        return match (mt_rand(0, 9)) {
            0, 1, 2, 3 => ['bitiruvchi', 'band'],
            4 => ['bitiruvchi', 'band_emas'],
            5 => ['otm', 'oqiydi'],
            6 => ['oqimaydi', 'tadbirkor'],
            7 => ['oqimaydi', 'migratsiya'],
            default => ['oqimaydi', 'band_emas'],
        };
    }
}
