<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Services;

use App\Domains\Ayollar\Models\Anketa;
use App\Domains\Ayollar\Support\AreaFilter;
use App\Domains\Ayollar\Support\AyollarAccess;
use App\Domains\Ayollar\Support\AyollarScope;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * KUNLIK O'ZGARISH — hudud kesimida.
 *
 * «Tumanlar kesimi» jadvali JAMI sonni ko'rsatadi va u savolga javob
 * bermaydi: raqam BUGUN qancha o'sdi? Rahbar uchun aynan shu muhim —
 * qaysi tuman ishlayapti, qaysi biri to'xtab qolgan.
 *
 * NEGA `created_at`, `filled_at` EMAS.
 *
 * `filled_at` — faol anketani TO'LDIRGAN vaqt (planshet soati).
 * `created_at` — yozuv serverga TUSHGAN vaqt.
 *
 * Oflayn ishlagan faol uch kunlik anketani bir kunda yuboradi. O'shanda
 * `filled_at` bo'yicha hisoblansa, bugungi o'sish NOL ko'rinardi, jami
 * son esa uch yuzga sakrardi — va ikki raqam bir-biriga zid bo'lardi.
 * `created_at` esa aynan jadvaldagi jami sonning qachon o'zgarganini
 * ko'rsatadi, shuning uchun ikkalasi doim mos keladi.
 *
 * (Hozircha 5 660 yozuvning HAMMASIDA ikkala sana bir xil kun —
 * ya'ni bu tanlov bugungi raqamni o'zgartirmaydi, faqat oflayn
 * sinxronizatsiya boshlanganda farqni oldini oladi.)
 */
final class DailyChange
{
    private const MAX_DAYS = 60;

    private const DEFAULT_DAYS = 7;

    public function __construct(
        private readonly AyollarAccess $access,
        private readonly AyollarScope $scope,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Request $request): array
    {
        $days = self::days($request);
        $from = Carbon::today()->subDays($days - 1);

        [$column, $table, $level] = $this->cut($request);

        $ids = Anketa::query()->countable()->select('id');
        $this->scope->apply($ids, $request->user());

        // Yaroqsiz UUID SQL darajasida 500 berardi — `AreaFilter` ga qara.
        AreaFilter::apply($ids, $request, 'district_id');

        $kunlar = $this->dayLabels($from, $days);
        $perDay = $this->perDay($ids, $column, $from);
        $jami = $this->totals($ids, $column);
        $nomlar = $this->names($table, array_keys($jami));

        $rows = [];

        foreach ($jami as $id => $total) {
            $counts = array_map(fn (string $kun) => $perDay[$id][$kun] ?? 0, $kunlar);

            $rows[] = $this->row((string) $id, $nomlar[$id] ?? '—', $total, $counts, $level);
        }

        // Tartib: BUGUN eng ko'p qo'shgan hudud tepada. Rahbar ekranga
        // «bugun nima bo'ldi» deb qaraydi, alifbo tartibi unga hech
        // narsa aytmaydi.
        usort($rows, fn ($a, $b) => [$b['today'], $b['total']] <=> [$a['today'], $a['total']]);

        return [
            'days' => $kunlar,
            'level' => $level,
            'rows' => $rows,
            'totals' => $this->row(
                'jami',
                'Jami',
                array_sum($jami),
                $this->sumColumns(array_column($rows, 'counts'), count($kunlar)),
                $level,
            ),
        ];
    }

    /**
     * DAVR — kunlarda.
     *
     * Kamida 2 kun: «kecha» ustuni bo'lmasa, farqni hisoblab
     * bo'lmaydi va ekranning asosiy ma'nosi yo'qoladi.
     *
     * Ma'nosiz qiymat (`days=abc`) DEFAULTga qaytadi. Avval
     * `integer()` uni 0 deb o'qib, eng kichik chegaraga — 2 kunga
     * tushirardi: foydalanuvchi 7 kunlik ekranni so'rab, ikki
     * ustunli jadval olardi va sababini bilmasdi.
     */
    public static function days(Request $request): int
    {
        $raw = $request->query('days');
        $asked = is_numeric($raw) ? (int) $raw : self::DEFAULT_DAYS;

        if ($asked < 1) {
            $asked = self::DEFAULT_DAYS;
        }

        return max(2, min($asked, self::MAX_DAYS));
    }

    /**
     * QAYSI KESIM: tuman yoki MFY.
     *
     * Viloyat rahbari tumanlarni ko'radi; tuman tanlansa yoki
     * foydalanuvchining o'zi tuman darajasida bo'lsa — MFYlarni.
     * MFY faoli uchun kesim baribir bitta qatordan iborat, lekin
     * unga ham o'z sur'atini ko'rish foydali.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function cut(Request $request): array
    {
        $level = $this->access->scopeLevel($request->user());
        $byMahalla = $level !== AyollarAccess::SCOPE_REGION || $request->filled('district_id');

        return $byMahalla
            ? ['mahalla_id', 'master.mahallas', 'mahalla']
            : ['district_id', 'master.districts', 'district'];
    }

    /** @return array<int, string> */
    private function dayLabels(Carbon $from, int $days): array
    {
        return array_map(
            fn (int $i) => $from->copy()->addDays($i)->toDateString(),
            range(0, $days - 1),
        );
    }

    /**
     * Hudud × kun kesimi.
     *
     * DIQQAT: taxallus `area_id`, `id` EMAS. `anketas` jadvalining
     * o'zida `id` ustuni bor va PostgreSQL `group by` da chiqish
     * taxallusini emas, KIRISH ustunini oladi — har yozuv alohida
     * guruh bo'lib qolardi.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Anketa>  $ids
     * @return array<string, array<string, int>>
     */
    private function perDay($ids, string $column, Carbon $from): array
    {
        $rows = DB::connection('ayollar')->table('anketas')
            ->whereIn('id', $ids)
            ->where('created_at', '>=', $from)
            ->whereNotNull($column)
            ->selectRaw("{$column} as area_id, date(created_at) as kun, count(*) as c")
            ->groupBy($column, DB::raw('date(created_at)'))
            ->get();

        $out = [];

        foreach ($rows as $r) {
            $out[(string) $r->area_id][(string) $r->kun] = (int) $r->c;
        }

        return $out;
    }

    /**
     * Hududning JAMI soni — «Tumanlar kesimi» dagi raqam bilan bir xil.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Anketa>  $ids
     * @return array<string, int>
     */
    private function totals($ids, string $column): array
    {
        $rows = DB::connection('ayollar')->table('anketas')
            ->whereIn('id', $ids)
            ->whereNotNull($column)
            ->selectRaw("{$column} as area_id, count(*) as c")
            ->groupBy($column)
            ->get();

        return $rows->mapWithKeys(fn ($r) => [(string) $r->area_id => (int) $r->c])->all();
    }

    /**
     * @param  array<int, string>  $ids
     * @return array<string, string>
     */
    private function names(string $table, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::connection('master')->table($table)
            ->whereIn('id', $ids)
            ->pluck('name_lat', 'id')
            ->all();
    }

    /**
     * Bitta qator — bugun, kecha va farq.
     *
     * `change` ATAYLAB ishorali: manfiy son «sur'at tushdi» degani va
     * uni ko'rsatmaslik eng muhim signalni yashirardi.
     *
     * @param  array<int, int>  $counts
     * @return array<string, mixed>
     */
    private function row(string $id, string $name, int $total, array $counts, string $level): array
    {
        $today = $counts[count($counts) - 1] ?? 0;
        $yesterday = $counts[count($counts) - 2] ?? 0;

        return [
            'id' => $id,
            'name' => $name,
            'level' => $level,
            'total' => $total,
            'counts' => $counts,
            'today' => $today,
            'yesterday' => $yesterday,
            'change' => $today - $yesterday,
            // Davr davomida qo'shilgan — «7 kunda nechta» savoliga javob.
            'period' => array_sum($counts),
        ];
    }

    /**
     * Ustunlar bo'yicha yig'indi — «Jami» qatorining kunlik ustunlari.
     *
     * @param  array<int, array<int, int>>  $matrix
     * @return array<int, int>
     */
    private function sumColumns(array $matrix, int $width): array
    {
        $out = array_fill(0, $width, 0);

        foreach ($matrix as $counts) {
            foreach ($counts as $i => $c) {
                $out[$i] += $c;
            }
        }

        return $out;
    }
}
