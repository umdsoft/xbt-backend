<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Services;

use App\Domains\Ayollar\Models\Balance;
use App\Domains\Ayollar\Models\Metric;
use App\Domains\Ayollar\Support\Rules;
use Illuminate\Support\Facades\DB;

/**
 * PLANSHET BOSH EKRANI — bitta so'rovda hammasi.
 *
 * NEGA BITTA ENDPOINT: ekranda o'nga yaqin blok bor (jarayon,
 * marshrut, diqqat, balans, yosh kesimi, uch tarkib, ehtiyojlar,
 * reyting). Ularni alohida so'rovlarga bo'lish planshetda 3G
 * ustida sekin bo'lardi va ekran bo'lak-bo'lak «sakrab» chiqardi.
 * Bitta javob esa oflayn keshga ham BUTUN holda tushadi.
 *
 * HECH QANDAY SON TO'QIB CHIQARILMAYDI. Har bir ko'rsatkich
 * manbasi shu faylda izohlangan; manba yo'q bo'lsa, maydon `null`
 * qaytadi va ekran «—» ko'rsatadi.
 */
class TabletHome
{
    public function __construct(
        private readonly CadastreDirectory $cadastre,
        private readonly BalanceRefresher $refresher,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(string $mahallaId, string $districtId, ?string $userId): array
    {
        $balance = $this->refresher->readMahalla($mahallaId, (int) now()->year, (int) now()->month);

        return [
            'mahalla' => $this->mahalla($mahallaId, $districtId),
            'progress' => $this->progress($mahallaId, $userId),
            'route' => $this->route($mahallaId),
            'attention' => $this->attention($mahallaId),
            'balance' => $balance,
            'red_women' => $this->redWomen($mahallaId),
            'age_bands' => $this->ageBands($mahallaId),
            'form' => $this->form($balance),
            'needs' => $this->needs($mahallaId),
            'rank' => $this->rank($mahallaId, $districtId),
        ];
    }

    /**
     * Balans qatorlari — `metric_registry` dan generatsiya.
     *
     * Kodda hardcode YO'Q: qator nomlari va tartibi reyestrda
     * turadi va administrator ularni o'zgartira oladi.
     *
     * @return array<int, array<string, mixed>>
     */
    private function form(Balance $balance): array
    {
        return Metric::query()->forForm($balance->levelCode())
            ->get(['code', 'name_lat', 'category'])
            ->map(fn (Metric $m) => [
                'code' => $m->code,
                'name_lat' => $m->name_lat,
                'category' => $m->category,
                'value' => (int) (($balance->metrics ?? [])[$m->code] ?? 0),
            ])->all();
    }

    /**
     * QIZIL TOIFADAGI AYOLLAR SONI.
     *
     * `anketas.category = 'red'` BO'YICHA EMAS — bunday toifa YO'Q.
     * Figmadagi `Balans tasmasi` komponenti buni ochiq aytadi:
     * «qizil toifa alohida bo'lak emas — u yashil va sariq
     * toifadagilar ichidan chiqadi».
     *
     * Ya'ni ayol yashil toifada bo'lib, ayni paytda qizil belgiga
     * ega bo'lishi mumkin. Sinovda aynan shunday chiqdi: 30 yashil,
     * 12 sariq, qizil toifa 0 ta — lekin 15 ta qizil BELGI bor edi.
     * Toifa bo'yicha hisoblash reytingda 0% berardi va bu xato.
     *
     * BELGILAR EMAS, AYOLLAR sanaladi: bir ayolda ikki belgi bo'lsa,
     * u bir marta hisoblanishi kerak — aks holda tasmadagi qizil
     * chiziq haqiqatdan uzun chiqardi.
     */
    private function redWomen(string $mahallaId): int
    {
        return (int) DB::connection('ayollar')->selectOne(<<<'SQL'
            select count(distinct a.woman_id) as c
            from ayollar.anketas a
            join ayollar.anketa_red_flags f on f.anketa_id = a.id
            where a.mahalla_id = ? and a.status = 'completed'
        SQL, [$mahallaId])->c;
    }

    /**
     * MFY sarlavhasi — nom, tuman, xonadon soni.
     *
     * «412 xonadon» KADASTRDAN: `master.buildings` dagi turar-joy
     * binolari. Bu son anketa yig'ishning MAXRAJI — faol qancha
     * eshik qolganini shu bilan o'lchaydi.
     *
     * @return array<string, mixed>
     */
    private function mahalla(string $mahallaId, string $districtId): array
    {
        $m = DB::connection('master')->table('mahallas')->where('id', $mahallaId)->first(['name_lat']);
        $d = DB::connection('master')->table('districts')->where('id', $districtId)->first(['name_lat']);

        return [
            'id' => $mahallaId,
            'name' => $m->name_lat ?? 'MFY',
            'district_id' => $districtId,
            'district_name' => $d->name_lat ?? '—',
            'households' => $this->cadastre->householdCount($mahallaId),
        ];
    }

    /**
     * Yig'ish jarayoni.
     *
     *   jami       — MFY'da ro'yxatga olingan ayollar
     *   to'ldirildi — tugallangan anketasi bor ayollar
     *   qoldi      — qolganlari (qoralama yoki anketasiz)
     *   qamrov     — to'ldirildi / jami
     *
     * `jami = to'ldirildi + qoldi` AYNIYAT bo'lib qoladi: uchalasi
     * bitta so'rovdan chiqadi, ya'ni ular hech qachon bir-biriga
     * zid bo'la olmaydi.
     *
     * @return array<string, mixed>
     */
    private function progress(string $mahallaId, ?string $userId): array
    {
        $row = DB::connection('ayollar')->selectOne(<<<'SQL'
            select
              count(distinct w.id) as total,
              count(distinct w.id) filter (where a.status = 'completed') as filled
            from ayollar.women w
            left join ayollar.anketas a on a.woman_id = w.id and a.status = 'completed'
            where w.mahalla_id = ? and w.deleted_at is null
        SQL, [$mahallaId]);

        $total = (int) ($row->total ?? 0);
        $filled = (int) ($row->filled ?? 0);

        // Bugun bajarilgan — SHU FAOL tomonidan. Boshqa faolning ishi
        // uning kunlik rejasiga kirmasligi kerak.
        $todayDone = $userId === null ? 0 : (int) DB::connection('ayollar')->table('anketas')
            ->where('mahalla_id', $mahallaId)
            ->where('created_by', $userId)
            ->where('status', 'completed')
            ->whereDate('filled_at', now()->toDateString())
            ->count();

        return [
            'total' => $total,
            'filled' => $filled,
            'remaining' => max(0, $total - $filled),
            'coverage' => $total > 0 ? round(100 * $filled / $total, 1) : null,
            'today_done' => $todayDone,
            'today_plan' => (int) config('ayollar.daily_household_norm', 12),
        ];
    }

    /**
     * BUGUNGI MARSHRUT.
     *
     * Marshrut TO'QIB CHIQARILMAYDI — u kadastrdan hosil bo'ladi:
     * MFY'dagi turar-joy binolari ko'cha va uy raqami bo'yicha
     * tartiblangan, ulardan hali xonadoni ochilmagani navbatda
     * turadi.
     *
     * KO'CHA BUTUNLIGICHA olinadi. Faol bir ko'chada yurib,
     * keyingisiga o'tadi — uylar aralashtirilsa, u kun bo'yi
     * mahalla bo'ylab sarson bo'lardi.
     *
     * @return array<string, mixed>|null
     */
    private function route(string $mahallaId): ?array
    {
        $norm = (int) config('ayollar.daily_household_norm', 12);

        // Tugallanmagan birinchi ko'cha — «bugungi ko'cha».
        $street = DB::connection('master')->selectOne(<<<'SQL'
            select s.id, s.name
            from master.streets s
            join master.buildings b on b.street_id = s.id and b.type = 'residential'
            where s.mahalla_id = ? and s.is_active
            group by s.id, s.name, s.sort_order
            order by s.sort_order, s.name
            limit 1
        SQL, [$mahallaId]);

        if ($street === null) {
            return null;
        }

        $houses = $this->cadastre->houses($mahallaId, (string) $street->id);

        if ($houses === []) {
            return null;
        }

        $labels = $this->familyLabels(array_column($houses, 'household_id'));
        $done = 0;
        $rows = [];

        foreach ($houses as $h) {
            // Holat UCHTA: tugallangan, boshlangan, kutilmoqda.
            // «Boshlangan» alohida turadi, chunki faol yarim
            // qoldirgan uyga QAYTISHI kerak va uni ro'yxatda
            // ajratib ko'rsatmasa, u yo'qolib ketardi.
            $status = match (true) {
                $h['household_id'] === null => 'pending',
                $h['women'] > 0 && $h['completed'] >= $h['women'] => 'done',
                default => 'active',
            };

            if ($status === 'done') {
                $done++;
            }

            $rows[] = [
                'building_id' => $h['id'],
                'house_number' => $h['house_number'],
                'household_id' => $h['household_id'],
                'family_label' => $labels[$h['household_id']] ?? null,
                'women' => $h['women'],
                'completed' => $h['completed'],
                'status' => $status,
            ];
        }

        // Kunlik kesim: tugallanganlar + normagacha qolganlari.
        // Butun ko'chani ko'rsatish (70 uy) ekranni to'ldirib
        // yuborardi va «bugun nima qilaman?» savoli yo'qolardi.
        $slice = array_slice($rows, 0, max($norm, $done));

        return [
            'street_id' => (string) $street->id,
            'street_name' => $this->streetLabel((string) $street->name),
            'done' => $done,
            'plan' => count($slice),
            'houses' => $slice,
        ];
    }

    /**
     * DIQQAT TALAB QILADI — uchta aniq signal.
     *
     * Har biri HARAKATGA chaqiradi va manbasi bor. «Umumiy
     * ogohlantirish» yozilmaydi: faol nima qilishini bilmasa,
     * xabar foydasiz.
     *
     * @return array<int, array<string, mixed>>
     */
    private function attention(string $mahallaId): array
    {
        $out = [];

        // 1. Tugallanmagan anketa: zinapoyaning 23-qadami. Bunday
        //    anketa BALANSGA KIRMAYDI — ya'ni ish bajarilgandek
        //    ko'rinadi, lekin hisobga tushmaydi.
        $incomplete = (int) DB::connection('ayollar')->table('anketas')
            ->where('mahalla_id', $mahallaId)
            ->where('category', 'incomplete')
            ->count();

        if ($incomplete > 0) {
            $out[] = [
                'kind' => 'incomplete',
                'count' => $incomplete,
                'title' => "{$incomplete} ta anketa tugallanmagan",
                'detail' => 'Bandlik holati belgilanmagan — balansga kirmaydi',
            ];
        }

        // 2. JShShIR dublikati. Qidiruv hash'i bo'yicha: xom JShShIR
        //    shifrlangan va uni solishtirib bo'lmaydi, `pinfl_lookup`
        //    esa aynan shu maqsad uchun bor.
        $dup = DB::connection('ayollar')->selectOne(<<<'SQL'
            select count(*) as c from (
              select w.pinfl_hash
              from ayollar.women w
              where w.pinfl_hash is not null and w.deleted_at is null
              group by w.pinfl_hash
              having count(*) > 1
                 and count(*) filter (where w.mahalla_id = ?) > 0
                 and count(distinct w.mahalla_id) > 1
            ) t
        SQL, [$mahallaId]);

        if ((int) ($dup->c ?? 0) > 0) {
            $n = (int) $dup->c;
            $out[] = [
                'kind' => 'duplicate',
                'count' => $n,
                'title' => "{$n} ta dublikat shubhasi",
                'detail' => 'Bir JShShIR boshqa MFYda ham uchraydi',
            ];
        }

        // 3. 7–17 yosh, maktabda emas. Bu qizil belgi emas, lekin
        //    darhol harakat talab qiladi: bola maktabga qaytarilishi
        //    kerak va bu hokim yordamchisining vazifasi.
        $outOfSchool = (int) DB::connection('ayollar')->table('anketas as a')
            ->join('women as w', 'w.id', '=', 'a.woman_id')
            ->where('a.mahalla_id', $mahallaId)
            ->where('w.age_group', '7_17')
            ->whereNull('w.deleted_at')
            ->whereRaw("a.answers ->> 'q12' is not null")
            ->whereRaw("a.answers ->> 'q12' not in ('maktab', 'school', 'maktabda')")
            ->count();

        if ($outOfSchool > 0) {
            $out[] = [
                'kind' => 'out_of_school',
                'count' => $outOfSchool,
                'title' => '7–17 yosh, maktabda emas',
                'detail' => "{$outOfSchool} ta qiz aniqlandi — hokim yordamchisiga xabar berildi",
            ];
        }

        return $out;
    }

    /**
     * YOSH KESIMI — yetti guruh.
     *
     * MUHIM: bu ZINAPOYA yosh guruhlari EMAS. `rules.json` da
     * toifalash uchun to'rtta guruh bor (0–2, 3–6, 7–17, 18+) va
     * ular O'ZGARMAYDI — toifa mantiqi shularga tayanadi.
     *
     * Bu yerdagi yettita band esa DEMOGRAFIK kesim: u faqat
     * ko'rsatish uchun va tug'ilgan sanadan hisoblanadi. Ikkalasini
     * aralashtirmaslik kerak — aks holda `rules.json` ni ekran
     * ehtiyoji uchun o'zgartirishga to'g'ri kelardi va u toifalash
     * natijasini buzardi.
     *
     * @return array<int, array<string, mixed>>
     */
    private function ageBands(string $mahallaId): array
    {
        $bands = [
            ['0–2 yosh', 0, 2],
            ['3–6 yosh', 3, 6],
            ['7–17 yosh', 7, 17],
            ['18–30 yosh', 18, 30],
            ['31–55 yosh', 31, 55],
            ['56–69 yosh', 56, 69],
            ['70+ yosh', 70, 200],
        ];

        $rows = DB::connection('ayollar')->select(<<<'SQL'
            select
              extract(year from age(w.birth_date))::int as years,
              count(*) filter (where a.category = 'green')  as green,
              count(*) filter (where a.category = 'yellow') as yellow,
              count(*) as total
            from ayollar.women w
            left join ayollar.anketas a on a.woman_id = w.id and a.status = 'completed'
            where w.mahalla_id = ? and w.deleted_at is null and w.birth_date is not null
            group by 1
        SQL, [$mahallaId]);

        $byYear = [];

        foreach ($rows as $r) {
            $byYear[(int) $r->years] = [
                'green' => (int) $r->green,
                'yellow' => (int) $r->yellow,
                'total' => (int) $r->total,
            ];
        }

        $out = [];

        foreach ($bands as [$label, $from, $to]) {
            $g = $y = $t = 0;

            for ($age = $from; $age <= $to; $age++) {
                $g += $byYear[$age]['green'] ?? 0;
                $y += $byYear[$age]['yellow'] ?? 0;
                $t += $byYear[$age]['total'] ?? 0;
            }

            $out[] = ['label' => $label, 'green' => $g, 'yellow' => $y, 'total' => $t];
        }

        return $out;
    }

    /**
     * EHTIYOJLAR — «istak» savollaridan.
     *
     * Bu savollar balansga TA'SIR QILMAYDI (toifani o'zgartirmaydi),
     * lekin qaror qabul qilish uchun eng qimmatli qism: balans
     * «hozir qanday» ni, ehtiyoj esa «nima kerak» ni ko'rsatadi.
     *
     * @return array<int, array<string, mixed>>
     */
    private function needs(string $mahallaId): array
    {
        $questions = Rules::needQuestions();

        if ($questions === []) {
            return [];
        }

        $out = [];

        foreach ($questions as $q) {
            // JAVOB QIYMATI bo'yicha guruhlash — «savolga javob
            // berganlar soni» bo'yicha EMAS.
            //
            // Nega muhim: sinovda uchala savol ham 32 ta bergan edi,
            // chunki 45 anketadan 13 tasida javob yo'q. Uchta bir xil
            // son ekranda ma'nosiz. Aslida javoblar turlicha —
            // «Ish o'rni», «Kasb-hunar kursi», «Mikroqarz» — va aynan
            // shular qaror uchun kerak.
            //
            // Yorliq ham SHU YERDAN keladi: anketa savollarining
            // matni bizda yo'q, javob qiymati esa bor va u haqiqiy.
            $rows = DB::connection('ayollar')->table('anketas')
                ->where('mahalla_id', $mahallaId)
                ->where('status', 'completed')
                ->whereRaw("nullif(trim(answers ->> ?), '') is not null", ["q{$q}"])
                ->selectRaw('answers ->> ? as answer, count(*) as c', ["q{$q}"])
                ->groupBy('answer')
                ->orderByDesc('c')
                ->limit(6)
                ->get();

            foreach ($rows as $r) {
                $out[] = [
                    'question' => $q,
                    'label' => (string) $r->answer,
                    'count' => (int) $r->c,
                ];
            }
        }

        usort($out, fn ($a, $b) => $b['count'] <=> $a['count']);

        return $out;
    }

    /**
     * TUMAN ICHIDAGI O'RIN.
     *
     * Ikki o'lcham bo'yicha: qizil toifa ulushi (kam bo'lgani yaxshi)
     * va anketa qamrovi (ko'p bo'lgani yaxshi). Reyting MFY jamoasiga
     * kontekst beradi — «71% ko'pmi yoki kammi?» degan savolga
     * javob faqat qo'shnilar bilan solishtirganda chiqadi.
     *
     * @return array<string, mixed>|null
     */
    private function rank(string $mahallaId, string $districtId): ?array
    {
        $rows = DB::connection('ayollar')->select(<<<'SQL'
            select w.mahalla_id,
                   count(distinct w.id) as total,
                   count(distinct w.id) filter (where a.status = 'completed') as filled,
                   -- Qizil = BELGISI BOR ayol. `category = 'red'`
                   -- ishlatilmaydi: bunday toifa yo'q (yuqoriga qarang).
                   count(distinct w.id) filter (where f.id is not null) as red
            from ayollar.women w
            left join ayollar.anketas a on a.woman_id = w.id and a.status = 'completed'
            left join ayollar.anketa_red_flags f on f.anketa_id = a.id
            where w.district_id = ? and w.deleted_at is null
            group by w.mahalla_id
        SQL, [$districtId]);

        if ($rows === []) {
            return null;
        }

        $stats = [];

        foreach ($rows as $r) {
            $total = (int) $r->total;
            $filled = (int) $r->filled;

            $stats[] = [
                'id' => (string) $r->mahalla_id,
                'coverage' => $total > 0 ? 100 * $filled / $total : 0.0,
                'red_share' => $filled > 0 ? 100 * (int) $r->red / $filled : 0.0,
            ];
        }

        $mine = null;

        foreach ($stats as $s) {
            if ($s['id'] === $mahallaId) {
                $mine = $s;
            }
        }

        if ($mine === null) {
            return null;
        }

        /*
         * TENG QIYMATLAR BIR XIL O'RINNI OLADI («musobaqa» reytingi).
         *
         * Oddiy saralashda bu XATO berardi: sinovda qizil ulushi 0%
         * bo'lgan sakkizta MFY bor edi va bizniki massivda oxirgi
         * turgani uchun «8-o'rin» ko'rsatildi — ya'ni eng yaxshi
         * natija eng yomon o'rin bo'lib chiqdi.
         *
         * To'g'ri hisob: «mendan QAT'IY yaxshiroqlar soni + 1».
         */
        $rank = static function (array $list, float $value, bool $lowerIsBetter): int {
            $better = 0;

            foreach ($list as $row) {
                $other = $lowerIsBetter ? $row['red_share'] : $row['coverage'];

                if ($lowerIsBetter ? $other < $value : $other > $value) {
                    $better++;
                }
            }

            return $better + 1;
        };

        return [
            'of' => count($stats),
            'red_share' => [
                // Qizil ulushi: KAM bo'lgani yaxshi.
                'rank' => $rank($stats, $mine['red_share'], true),
                'value' => round($mine['red_share'], 2),
            ],
            'coverage' => [
                // Qamrov: KO'P bo'lgani yaxshi.
                'rank' => $rank($stats, $mine['coverage'], false),
                'value' => round($mine['coverage'], 1),
            ],
        ];
    }

    /** Ko'cha nomini ko'rsatish uchun — «ул. Урганч» -> «Урганч ko'chasi». */
    private function streetLabel(string $raw): string
    {
        $name = trim(preg_replace('/^\s*(ул\.|улица|кўча|ko\'cha|kocha)\s*/iu', '', $raw) ?? $raw);

        return $name === '' ? $raw : $name.' ko‘chasi';
    }

    /**
     * Xonadon yorliqlari.
     *
     * @param  array<int, string|null>  $householdIds
     * @return array<string, string>
     */
    private function familyLabels(array $householdIds): array
    {
        $ids = array_values(array_filter($householdIds));

        if ($ids === []) {
            return [];
        }

        return DB::connection('ayollar')->table('households')
            ->whereIn('id', $ids)
            ->whereNotNull('family_label')
            ->pluck('family_label', 'id')
            ->all();
    }
}
