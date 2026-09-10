<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Services;

use App\Domains\Mahalla\Support\MahallaAccess;
use App\Domains\Mahalla\Support\MahallaZones;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bitta mahalla sahifasi uchun statistika: dinamika, zona holati, so'nggi
 * o'zgarishlar va mahalla 5-ligi kesimi. `ExecutiveStats` tuman kesimi bilan
 * band — bular o'z faylida tursa tushunarliroq va alohida testlanadi.
 */
final class ExecutiveMahallaStats
{
    public function __construct(private readonly ExecutiveStats $stats)
    {
    }

    /**
     * Kunlar bo'yicha o'zgargan xonadonlar (oxirgi `$days` kun).
     *
     * Oyna MAHALLIY kun chegaralari bo'yicha quriladi va kuzatuv bo'lmagan
     * kunlar ham nol bilan qaytadi — aks holda diagrammada "har kuni ish
     * bo'lgan" degan yolg'on taassurot qolardi.
     *
     * @return array<int, array{date: string, count: int}>
     */
    public function dynamics(string $mahallaId, int $days = 30): array
    {
        return $this->dynamicsFor('h.mahalla_id', $mahallaId, $days);
    }

    /**
     * Dinamikaning umumiy amalga oshirilishi. Tuman va mahalla kesimlari
     * faqat filtr ustuni bilan farq qiladi — mantiqni ikki marta yozish
     * vaqt mintaqasi chegarasi bir joyda o'zgarib, ikkinchisida unutilishiga
     * olib kelardi.
     *
     * @return array<int, array{date: string, count: int}>
     */
    private function dynamicsFor(string $column, string $id, int $days): array
    {
        $tz = (string) config('mahalla.timezone', 'Asia/Tashkent');
        $from = Carbon::now($tz)->startOfDay()->subDays($days - 1);

        // `observed_at` — timestamp without time zone, ichida UTC. Kunlarga
        // ajratishdan oldin mahalliy vaqtga o'giriladi, aks holda kechqurun
        // 19:00 dan keyingi o'zgarishlar (Toshkent bo'yicha ertangi kun)
        // noto'g'ri kunga tushadi.
        // DIQQAT: `groupByRaw` bilan alohida `?` ishlatilsa (selectRaw dagi bilan
        // bir xil qiymat bo'lsa ham), PostgreSQL ularni ikkita MUSTAQIL bog'lam
        // sifatida ko'radi va SELECT ifodasi bilan GROUP BY ifodasini bir xil
        // deb TASDIQLAY OLMAYDI ("column must appear in GROUP BY" xatosi).
        // Shuning uchun bitta bog'lam (selectRaw) ishlatiladi, GROUP BY esa
        // SELECT'dagi "kun" nomi (output-ustun nomi) bo'yicha — PostgreSQL
        // buni maxsus ravishda qo'llab-quvvatlaydi.
        $rows = DB::connection('mahalla')->table('zone_observations as o')
            ->join('houses as h', 'h.id', '=', 'o.house_id')
            ->where($column, $id)
            ->where('o.is_change', true)
            ->where('o.observed_at', '>=', $from->copy()->utc())
            ->selectRaw("to_char(o.observed_at AT TIME ZONE 'UTC' AT TIME ZONE ?, 'YYYY-MM-DD') as kun", [$tz])
            ->selectRaw('count(distinct o.house_id) as soni')
            ->groupBy('kun')
            ->get();

        $byDay = [];
        foreach ($rows as $r) {
            $byDay[$r->kun] = (int) $r->soni;
        }

        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $from->copy()->addDays($i)->toDateString();
            $out[] = ['date' => $date, 'count' => $byDay[$date] ?? 0];
        }

        return $out;
    }

    /**
     * Zona bo'yicha holat taqsimoti + KUZATILMAGAN xonadonlar.
     *
     * `house_zone_states` da qator faqat xonadon birinchi marta kuzatilganda
     * paydo bo'ladi. Shuning uchun "kuzatilmagan" segmentisiz 837 xonadonli
     * mahallada 5 tasi kuzatilgan bo'lsa diagramma 100% yashil ko'rsatib
     * rahbarni chalg'itardi.
     *
     * @return array<int, array<string, mixed>>
     */
    public function zoneStatus(string $mahallaId, int $households): array
    {
        $rows = DB::connection('mahalla')->table('house_zone_states as s')
            ->join('houses as h', 'h.id', '=', 's.house_id')
            ->where('h.mahalla_id', $mahallaId)
            ->groupBy('s.zone', 's.status')
            ->select('s.zone', 's.status')
            ->selectRaw('count(*) as soni')
            ->get();

        $byZone = [];
        foreach ($rows as $r) {
            $byZone[$r->zone][$r->status] = (int) $r->soni;
        }

        $out = [];
        foreach (MahallaZones::zoneCodes() as $zone) {
            $statuses = [];
            $observed = 0;
            foreach (MahallaZones::statusCodes() as $status) {
                $count = $byZone[$zone][$status] ?? 0;
                $observed += $count;
                $statuses[] = [
                    'status' => $status,
                    'label' => MahallaZones::statusLabel($status),
                    'count' => $count,
                ];
            }

            $out[] = [
                'zone' => $zone,
                'label' => MahallaZones::zoneLabel($zone),
                'households' => $households,
                'statuses' => $statuses,
                'unobserved' => max(0, $households - $observed),
            ];
        }

        return $out;
    }

    /**
     * MAHALLA 5-LIGI kesimi: har bir hodim, uning lavozimi va biriktirilgan
     * ko'chalari bo'yicha ko'rsatkichlar.
     *
     * Ko'cha kesimi DARHOL qaytariladi (alohida so'rov bilan emas): mahallada
     * ko'pi bilan besh hodim va har birida bir nechta ko'cha bor, ya'ni hajm
     * kichik. Shu tufayli hodim ustiga bosilganda kutish bo'lmaydi.
     *
     * @return array<int, array<string, mixed>>
     */
    public function staff(string $mahallaId): array
    {
        $people = DB::connection('mahalla')->table('users')
            ->where('mahalla_id', $mahallaId)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name', 'position', 'is_active']);

        if ($people->isEmpty()) {
            return [];
        }

        // Biriktiruv `mahalla` schema'sida, ko'cha nomlari esa `master` da —
        // schema'lararo JOIN yozilmaydi, ikkalasi alohida so'raladi.
        $assignments = DB::connection('mahalla')->table('street_assignments')
            ->whereIn('user_id', $people->pluck('id'))
            ->get(['user_id', 'street_id']);

        $streetIds = $assignments->pluck('street_id')->unique()->values()->all();
        $streetNames = $streetIds === [] ? [] : DB::connection('master')->table('streets')
            ->whereIn('id', $streetIds)->pluck('name', 'id')->all();

        $households = $this->householdsByStreet($streetIds);
        $changes = $this->changesByStreet($streetIds);

        $byUser = [];
        foreach ($assignments as $a) {
            $byUser[$a->user_id][] = $a->street_id;
        }

        $out = [];
        foreach ($people as $p) {
            $streets = [];
            $totalHouseholds = 0;
            $week = 0;
            $today = 0;

            foreach ($byUser[$p->id] ?? [] as $sid) {
                $h = $households[$sid] ?? 0;
                $c = $changes[$sid] ?? ['week' => 0, 'today' => 0];

                $totalHouseholds += $h;
                $week += $c['week'];
                $today += $c['today'];

                $streets[] = [
                    'id' => $sid,
                    'name' => $streetNames[$sid] ?? '—',
                    'households' => $h,
                    'changed' => $c,
                ];
            }

            $out[] = [
                'id' => $p->id,
                'name' => $p->name,
                'position' => $p->position,
                'position_label' => MahallaAccess::positionLabel($p->position),
                'is_active' => (bool) $p->is_active,
                'households' => $totalHouseholds,
                // Ko'chalar bo'yicha yig'indi: bitta xonadon faqat bitta
                // ko'chaga tegishli, shuning uchun bu yerda ikki marta
                // sanash xavfi yo'q (tuman kesimidagi zonalardan farqli).
                'changed' => ['week' => $week, 'today' => $today],
                'streets' => $streets,
            ];
        }

        return $out;
    }

    /**
     * Ko'cha bo'yicha turar-joy binolari soni (kadastrdan).
     *
     * @param  array<int, string>  $streetIds
     * @return array<string, int>
     */
    private function householdsByStreet(array $streetIds): array
    {
        if ($streetIds === []) {
            return [];
        }

        $rows = DB::connection('master')->table('buildings')
            ->whereIn('street_id', $streetIds)
            ->where('type', 'residential')
            ->groupBy('street_id')
            ->select('street_id')
            ->selectRaw('count(*) as soni')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->street_id] = (int) $r->soni;
        }

        return $out;
    }

    /**
     * Ko'cha bo'yicha o'zgargan xonadonlar (hafta va bugun).
     *
     * @param  array<int, string>  $streetIds
     * @return array<string, array{week: int, today: int}>
     */
    private function changesByStreet(array $streetIds): array
    {
        if ($streetIds === []) {
            return [];
        }

        $period = $this->stats->period();

        $rows = DB::connection('mahalla')->table('zone_observations as o')
            ->join('houses as h', 'h.id', '=', 'o.house_id')
            ->whereIn('h.street_id', $streetIds)
            ->where('o.is_change', true)
            ->where('o.observed_at', '>=', $period['week_start_utc'])
            ->groupBy('h.street_id')
            ->select('h.street_id')
            ->selectRaw('count(distinct o.house_id) as week_count')
            ->selectRaw(
                'count(distinct case when o.observed_at >= ? then o.house_id end) as today_count',
                [$period['today_start_utc']],
            )
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->street_id] = [
                'week' => (int) $r->week_count,
                'today' => (int) $r->today_count,
            ];
        }

        return $out;
    }

    /**
     * So'nggi tasdiqlangan o'zgarishlar (AI tavsifi bilan).
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentChanges(string $mahallaId, int $limit = 10): array
    {
        $rows = DB::connection('mahalla')->table('zone_observations as o')
            ->join('houses as h', 'h.id', '=', 'o.house_id')
            ->where('h.mahalla_id', $mahallaId)
            ->where('o.is_change', true)
            ->orderByDesc('o.observed_at')
            ->limit($limit)
            ->get(['o.id', 'o.zone', 'o.observed_at', 'o.ai_result', 'h.address']);

        $out = [];
        foreach ($rows as $r) {
            $ai = is_string($r->ai_result) ? json_decode($r->ai_result, true) : (array) $r->ai_result;
            $description = is_array($ai) ? ($ai['change_description'] ?? null) : null;

            $out[] = [
                'id' => $r->id,
                'zone' => $r->zone,
                'zone_label' => MahallaZones::zoneLabel($r->zone),
                'address' => $r->address,
                // `observed_at` bazada UTC, lekin ustun timezone-siz saqlanadi —
                // xom qatorni qaytarsak frontend `new Date()` uni MAHALLIY vaqt
                // deb talqin qiladi (ECMAScript qoidasi). ISO-8601 offset bilan
                // bu noaniqlikni yo'q qiladi (konvensiya: ReviewController::index()).
                'observed_at' => Carbon::parse((string) $r->observed_at, 'UTC')->toIso8601String(),
                // Tavsif bo'sh bo'lsa ham qator ko'rsatiladi — o'zgarish faktini
                // yashirmaslik kerak, frontend "тавсиф йўқ" deb yozadi.
                'description' => $description !== '' ? $description : null,
            ];
        }

        return $out;
    }
}
