<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Services;

use App\Domains\Mahalla\Support\MahallaZones;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rahbariyat dashboard'i uchun agregat hisoblash.
 *
 * MAXRAJ (jami xonadon) kadastrdan — `master.buildings`. Operatsion
 * `mahalla.houses` ishlatilmaydi: u kerak bo'lganda to'ladi (HouseProvisioner),
 * shuning uchun undan olingan son har doim haqiqatdan kam bo'ladi.
 *
 * SURAT (o'zgarganlar) `mahalla.zone_observations` dan, `COUNT(DISTINCT house_id)`
 * bilan — bitta xonadon hafta ichida ikki marta o'zgarsa ham 1 marta sanaladi,
 * aks holda son maxrajdan oshib ketishi mumkin.
 *
 * Schema'lararo JOIN ataylab qilinmaydi: har so'rov o'z ulanishida qoladi,
 * birlashtirish PHP'da (52 qator — farqsiz tez).
 */
final class ExecutiveStats
{
    /**
     * Davr chegaralari. Mahalliy (Toshkent) kun/hafta boshi UTC ga o'giriladi,
     * chunki `observed_at` bazada UTC da saqlanadi.
     *
     * @return array{timezone: string, today: string, week_start: string,
     *               today_start_utc: Carbon, week_start_utc: Carbon}
     */
    public function period(): array
    {
        $tz = (string) config('mahalla.timezone', 'Asia/Tashkent');
        $now = Carbon::now($tz);

        return [
            'timezone' => $tz,
            'today' => $now->toDateString(),
            'week_start' => $now->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
            'today_start_utc' => $now->copy()->startOfDay()->utc(),
            'week_start_utc' => $now->copy()->startOfWeek(Carbon::MONDAY)->startOfDay()->utc(),
        ];
    }

    /**
     * Tuman kesimi: har mahalla uchun jami xonadon + zona bo'yicha o'zgarishlar.
     *
     * @return array{rows: array<int, array<string, mixed>>,
     *               totals: array<string, mixed>,
     *               unassigned_households: int,
     *               summary: array{changed_today: int, changed_week: int, active_mahallas: int,
     *                              total_mahallas: int, pending_reviews: int}}
     */
    public function district(string $districtId): array
    {
        $period = $this->period();
        $households = $this->householdsByMahalla($districtId);
        $changes = $this->changesByMahallaZone($districtId, $period);
        $changed = $this->changedByMahalla($districtId, $period);
        $indicators = $this->indicatorsByMahalla($districtId);

        // Nofaol mahalla jadvalda ham, ЖАМИ hisobida ham ko'rinmaydi —
        // aks holda tugatilgan/qayta tashkil etilgan mahalla rahbarni
        // "ish qilinmagan hudud" deb chalg'itardi.
        $mahallas = DB::connection('master')->table('mahallas')
            ->where('district_id', $districtId)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name_cyr')
            ->get(['id', 'name_cyr']);

        $rows = [];
        $totals = $this->emptyZoneCounters();
        $totalHouseholds = 0;

        foreach ($mahallas as $m) {
            $zones = $this->emptyZoneCounters();
            foreach (MahallaZones::zoneCodes() as $zone) {
                $zones[$zone] = $changes[$m->id][$zone] ?? ['week' => 0, 'today' => 0];
                $totals[$zone]['week'] += $zones[$zone]['week'];
                $totals[$zone]['today'] += $zones[$zone]['today'];
            }

            $count = $households[$m->id] ?? 0;
            $totalHouseholds += $count;

            $rows[] = [
                'mahalla' => ['id' => $m->id, 'name' => $m->name_cyr],
                'households' => $count,
                // `zones` — jadval uchun, zona kesimida (bitta uy necha zonada
                // o'zgargan bo'lsa, shuncha marta hisoblanadi — bu ataylab).
                'zones' => $zones,
                // `changed` — xarita/card uchun, zonadan qat'i nazar DISTINCT
                // xonadon soni. `zones` yig'indisidan farqli: bitta uy to'rtta
                // zonada o'zgarsa ham bu yerda 1 (jadvaldagi cell'lar 1 dan bo'ladi).
                'changed' => $changed[$m->id] ?? ['week' => 0, 'today' => 0],
                // Rasmiy ko'rsatkichlar (aholi, oila, kambag'allik). Yuqoridagi
                // `households` bilan ATAYLAB aralashtirilmaydi: u kadastr binosi,
                // bu ma'muriy xo'jalik — turli narsani sanaydi.
                'indicators' => $indicators[$m->id] ?? null,
            ];
        }

        return [
            'rows' => $rows,
            'totals' => ['households' => $totalHouseholds, 'zones' => $totals],
            'unassigned_households' => $this->unassignedHouseholds($districtId),
            'summary' => $this->summary($districtId, $period, count($mahallas)),
            'ranking' => $this->ranking($rows),
        ];
    }

    /**
     * Shu haftaning eng yaxshi va eng yomon mahallalari.
     *
     * Saralash ULUSH bo'yicha, mutlaq son bo'yicha emas: Shovotda mahallalar
     * 341 dan 1156 xonadongacha, ya'ni katta mahalla har doim ko'proq mutlaq
     * o'zgarish beradi va reyting shunchaki "kim kattaroq" ro'yxatiga aylanardi.
     *
     * Teng ulushda ajratish:
     *  - eng yaxshilar orasida — mutlaq soni ko'p bo'lgani yuqori;
     *  - eng yomonlar orasida — xonadoni KO'P bo'lgani yuqori, chunki ish
     *    boshlanmagan katta mahalla kichigidan ko'ra jiddiyroq muammo.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{top: array<int, array<string, mixed>>, bottom: array<int, array<string, mixed>>}
     */
    private function ranking(array $rows, int $topN = 3, int $bottomN = 5): array
    {
        // Xonadoni yo'q mahalla (kadastr bog'lanmagan) reytingga kirmaydi —
        // uning ulushi ma'nosiz bo'lardi.
        $items = [];
        foreach ($rows as $r) {
            if ($r['households'] <= 0) {
                continue;
            }

            $week = $r['changed']['week'];
            $items[] = [
                'mahalla' => $r['mahalla'],
                'households' => $r['households'],
                'changed_week' => $week,
                'percent' => round(100 * $week / $r['households'], 2),
            ];
        }

        $best = $items;
        usort($best, fn ($a, $b) => [$b['percent'], $b['changed_week']] <=> [$a['percent'], $a['changed_week']]);
        $top = array_slice($best, 0, $topN);

        $topIds = array_column(array_column($top, 'mahalla'), 'id');
        $worst = array_values(array_filter($items, fn ($i) => ! in_array($i['mahalla']['id'], $topIds, true)));
        usort($worst, fn ($a, $b) => [$a['percent'], $b['households']] <=> [$b['percent'], $a['households']]);

        return ['top' => $top, 'bottom' => array_slice($worst, 0, $bottomN)];
    }

    /**
     * Mahalla kesimi: zonalar bo'yicha (qo'lyozma jadval shakli).
     *
     * @return array{households: int, rows: array<int, array<string, mixed>>}
     */
    public function mahalla(string $mahallaId): array
    {
        $period = $this->period();

        $households = (int) DB::connection('master')->table('buildings')
            ->where('mahalla_id', $mahallaId)
            ->where('type', 'residential')
            ->count();

        $changes = $this->changesByZone($mahallaId, $period);

        $rows = [];
        foreach (MahallaZones::zoneCodes() as $zone) {
            $c = $changes[$zone] ?? ['week' => 0, 'today' => 0];
            $rows[] = [
                'zone' => $zone,
                'label' => MahallaZones::zoneLabel($zone),
                'households' => $households,
                'week' => $c['week'],
                'today' => $c['today'],
            ];
        }

        return ['households' => $households, 'rows' => $rows];
    }

    /** Butun son sifatida o'qiladigan ko'rsatkichlar. */
    private const INT_INDICATORS = [
        'population', 'households', 'families',
        'social_registry_families', 'social_registry_members',
        'state_supported_families', 'state_supported_members',
        'poor_families', 'poor_members',
        'borderline_families', 'borderline_members',
    ];

    /** Kasr son sifatida o'qiladiganlar. */
    private const DEC_INDICATORS = ['social_registry_rate', 'poverty_rate'];

    /**
     * Mahalla bo'yicha rasmiy ko'rsatkichlar — HAR USTUN uchun oxirgi ma'lum qiymat.
     *
     * "Eng oxirgi davr qatorini olish" ishlamaydi. Ko'rsatkichlar turli manbadan
     * turli qadamda keladi: kambag'allik oyiga ikki marta yangilanadi, aholi soni
     * esa yiliga bir marta. Oxirgi davr qatorida aholi ustuni bo'sh bo'lib chiqadi
     * va panel "ma'lumot yo'q" deb ko'rsatadi — holbuki u oldingi davrda bor.
     *
     * Shuning uchun har ustun mustaqil ravishda o'zining eng so'nggi TO'LDIRILGAN
     * qiymatini oladi. `array_agg(... order by period desc) filter (where ... is
     * not null)` — PostgreSQL'da shuning eng ixcham ifodasi.
     *
     * `period` esa qaysi sanaga tegishli ekanini bildiradi — eng katta davr.
     *
     * @return array<string, array<string, mixed>>
     */
    private function indicatorsByMahalla(string $districtId): array
    {
        $cols = [];
        foreach ([...self::INT_INDICATORS, ...self::DEC_INDICATORS] as $c) {
            $cols[] = "(array_agg(i.{$c} order by i.period desc) filter (where i.{$c} is not null))[1] as {$c}";
        }
        foreach (['is_ogir', 'is_yangi_uzbekiston'] as $c) {
            $cols[] = "bool_or(i.{$c}) as {$c}";
        }

        $rows = DB::connection('master')
            ->table('mahalla_indicators as i')
            ->join('mahallas as m', 'm.id', '=', 'i.mahalla_id')
            ->where('m.district_id', $districtId)
            ->groupBy('i.mahalla_id')
            ->select(DB::connection('master')->raw(
                'i.mahalla_id, max(i.period) as period, '.implode(', ', $cols)
            ))
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $item = ['period' => $r->period];
            foreach (self::INT_INDICATORS as $c) {
                $item[$c] = $r->{$c} === null ? null : (int) $r->{$c};
            }
            foreach (self::DEC_INDICATORS as $c) {
                $item[$c] = $r->{$c} === null ? null : (float) $r->{$c};
            }
            $item['is_ogir'] = (bool) $r->is_ogir;
            $item['is_yangi_uzbekiston'] = (bool) $r->is_yangi_uzbekiston;

            $out[$r->mahalla_id] = $item;
        }

        return $out;
    }

    /**
     * Mahalla bo'yicha turar-joy binolari soni (maxraj).
     *
     * @return array<string, int>
     */
    private function householdsByMahalla(string $districtId): array
    {
        $rows = DB::connection('master')->table('buildings')
            ->where('district_id', $districtId)
            ->where('type', 'residential')
            ->whereNotNull('mahalla_id')
            ->groupBy('mahalla_id')
            ->select('mahalla_id', DB::raw('count(*) as households'))
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->mahalla_id] = (int) $r->households;
        }

        return $out;
    }

    /** Mahallaga biriktirilmagan turar-joy binolari (jadval ostidagi izoh uchun). */
    private function unassignedHouseholds(string $districtId): int
    {
        return DB::connection('master')->table('buildings')
            ->where('district_id', $districtId)
            ->where('type', 'residential')
            ->whereNull('mahalla_id')
            ->count();
    }

    /**
     * KPI cardlar uchun yig'ma ko'rsatkichlar.
     *
     * DIQQAT: `changed_week` ni zona bo'yicha sonlarni qo'shib olib bo'LMAYDI —
     * ikki zonasi o'zgargan bitta uy ikki marta sanalib, xonadon sonidan oshib
     * ketardi. Shuning uchun zonadan qat'i nazar alohida DISTINCT so'rov.
     *
     * @param  array{today_start_utc: Carbon, week_start_utc: Carbon}  $period
     * @return array{changed_today: int, changed_week: int, active_mahallas: int,
     *               total_mahallas: int, pending_reviews: int}
     */
    private function summary(string $districtId, array $period, int $totalMahallas): array
    {
        $row = DB::connection('mahalla')->table('zone_observations as o')
            ->join('houses as h', 'h.id', '=', 'o.house_id')
            ->where('h.district_id', $districtId)
            ->where('o.is_change', true)
            ->where('o.observed_at', '>=', $period['week_start_utc'])
            ->selectRaw('count(distinct o.house_id) as week_count')
            ->selectRaw(
                'count(distinct case when o.observed_at >= ? then o.house_id end) as today_count',
                [$period['today_start_utc']],
            )
            ->selectRaw('count(distinct h.mahalla_id) as active_mahallas')
            ->first();

        return [
            'changed_today' => (int) ($row->today_count ?? 0),
            'changed_week' => (int) ($row->week_count ?? 0),
            'active_mahallas' => (int) ($row->active_mahallas ?? 0),
            'total_mahallas' => $totalMahallas,
            'pending_reviews' => $this->pendingReviews($districtId),
        ];
    }

    /** Masul hodim tekshiruvini kutayotgan kuzatuvlar (tuman bo'yicha). */
    private function pendingReviews(string $districtId): int
    {
        return DB::connection('mahalla')->table('zone_observations as o')
            ->join('houses as h', 'h.id', '=', 'o.house_id')
            ->where('h.district_id', $districtId)
            ->where('o.decision', 'flagged')
            ->whereNull('o.reviewed_by')
            ->count();
    }

    /**
     * @param  array{today_start_utc: Carbon, week_start_utc: Carbon}  $period
     * @return array<string, array<string, array{week: int, today: int}>>
     */
    private function changesByMahallaZone(string $districtId, array $period): array
    {
        // DIQQAT: `select()` EMAS, `addSelect()` — `select()` changeQuery() dagi
        // hisoblangan ustunlarni (week_count/today_count) o'chirib yuborardi.
        $rows = $this->changeQuery($period)
            ->where('h.district_id', $districtId)
            ->groupBy('h.mahalla_id', 'o.zone')
            ->addSelect('h.mahalla_id', 'o.zone')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->mahalla_id][$r->zone] = [
                'week' => (int) $r->week_count,
                'today' => (int) $r->today_count,
            ];
        }

        return $out;
    }

    /**
     * Mahalla bo'yicha, ZONADAN QAT'I NAZAR, DISTINCT o'zgargan xonadonlar.
     *
     * `changesByMahallaZone()` dan farqi: bu yerda `o.zone` bo'yicha
     * guruhlanmaydi, shuning uchun to'rt zonasi o'zgargan bitta uy bir marta
     * sanaladi. Xarita va KPI cardlar shu bilan ishlaydi — jadvaldagi zona
     * ustunlari bilan ZID bo'lmasligi uchun (u ataylab zona kesimida qoladi).
     *
     * @param  array{today_start_utc: Carbon, week_start_utc: Carbon}  $period
     * @return array<string, array{week: int, today: int}>
     */
    private function changedByMahalla(string $districtId, array $period): array
    {
        $rows = $this->changeQuery($period)
            ->where('h.district_id', $districtId)
            ->groupBy('h.mahalla_id')
            ->addSelect('h.mahalla_id')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->mahalla_id] = ['week' => (int) $r->week_count, 'today' => (int) $r->today_count];
        }

        return $out;
    }

    /**
     * @param  array{today_start_utc: Carbon, week_start_utc: Carbon}  $period
     * @return array<string, array{week: int, today: int}>
     */
    private function changesByZone(string $mahallaId, array $period): array
    {
        // `addSelect()` — yuqoridagi izohga qarang.
        $rows = $this->changeQuery($period)
            ->where('h.mahalla_id', $mahallaId)
            ->groupBy('o.zone')
            ->addSelect('o.zone')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->zone] = ['week' => (int) $r->week_count, 'today' => (int) $r->today_count];
        }

        return $out;
    }

    /**
     * Umumiy o'zgarish so'rovi. `FILTER (WHERE ...)` emas, `CASE` — standart SQL.
     *
     * @param  array{today_start_utc: Carbon, week_start_utc: Carbon}  $period
     */
    private function changeQuery(array $period): \Illuminate\Database\Query\Builder
    {
        return DB::connection('mahalla')->table('zone_observations as o')
            ->join('houses as h', 'h.id', '=', 'o.house_id')
            ->where('o.is_change', true)
            ->where('o.observed_at', '>=', $period['week_start_utc'])
            ->selectRaw('count(distinct o.house_id) as week_count')
            ->selectRaw(
                'count(distinct case when o.observed_at >= ? then o.house_id end) as today_count',
                [$period['today_start_utc']],
            );
    }

    /**
     * @return array<string, array{week: int, today: int}>
     */
    private function emptyZoneCounters(): array
    {
        $out = [];
        foreach (MahallaZones::zoneCodes() as $zone) {
            $out[$zone] = ['week' => 0, 'today' => 0];
        }

        return $out;
    }
}
