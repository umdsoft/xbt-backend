<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Services;

use App\Domains\Advisor\Models\KpiEntry;
use App\Domains\Advisor\Models\KpiTarget;
use App\Domains\Advisor\Support\AdvisorScope;
use App\Domains\Advisor\Support\KpiCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * KPI moduli (spec §7). Katalog (Excel svodi — KpiCatalog) + maqsad (target/Reja) +
 * kiritilgan qiymat (entry/Bajarilish) + ijro% (value/target).
 *
 * Ijro% = value / target * 100 (target 0/yo'q bo'lsa null). Qamrov (AdvisorScope):
 * tuman FAQAT o'z tumani entrylarini ko'radi/kiritadi; viloyat/bo'linma hammasini
 * (IDOR himoyasi — advisor Task va Project modullari naqshi).
 *
 * FAQAT faol (active) KPIlar ko'rsatiladi/agregatlanadi — Excel svodiga moslashtirilgan.
 * (Avvalgi AVTO-derivatsiya — loyihalar/topshiriq soni — Excel modelida yo'q, olib
 * tashlangan; loyihalar alohida modul, topshiriqlar reytingда hisobga olinadi.)
 */
class KpiService
{
    public const SCOPES = ['viloyat', 'tuman'];

    public const ENTRY_STATUSES = ['draft', 'submitted', 'approved'];

    // ---------------------------------------------------------------- katalog

    /**
     * KPI katalogi — [{id,code,name,unit,scope}] (scope bo'yicha filtr).
     *
     * @return array<int, array{id: string, code: string, name: string, unit: ?string, scope: string}>
     */
    public function catalog(?string $scope = null): array
    {
        return Cache::remember($this->cacheKey('catalog', $scope ?? 'all'), $this->cacheSeconds(), fn () => DB::connection('advisor')->table('kpis')
            ->where('active', true)
            ->when($scope !== null, fn ($q) => $q->where('scope', $scope))
            ->orderBy('scope')
            ->orderBy('sort')
            ->get(['id', 'code', 'name', 'unit', 'scope'])
            ->map(fn ($k) => [
                'id' => $k->id,
                'code' => $k->code,
                'name' => $k->name,
                'unit' => $k->unit,
                'scope' => $k->scope,
            ])->all());
    }

    // -------------------------------------------------------------- entrylar

    /**
     * Kiritilgan qiymatlar (ijro% bilan) — qamrov + filtr. Tuman FAQAT o'z tumani;
     * viloyat/bo'linma hammasi (yoki district_id bo'yicha).
     *
     * @param  array<string, mixed>  $filters  period, district_id
     * @return array<int, array<string, mixed>>
     */
    public function entries(AdvisorScope $scope, array $filters): array
    {
        $period = $filters['period'] ?? null;
        // Tuman FAQAT o'z tumani; viloyat/bo'linma filtr bergan tumani (yoki hammasi).
        $districtId = $scope->isTuman() ? $scope->districtId : ($filters['district_id'] ?? null);

        // Tuman tumansiz (profil to'liq emas) — hech narsa ko'rmaydi.
        if ($scope->isTuman() && $districtId === null) {
            return [];
        }

        $rows = DB::connection('advisor')->table('kpi_entries as e')
            ->join('kpis as k', 'k.id', '=', 'e.kpi_id')
            ->where('k.active', true)
            ->leftJoin('districts as d', 'd.id', '=', 'e.district_id')
            ->when($period !== null, fn ($q) => $q->where('e.period', $period))
            ->when($districtId !== null, fn ($q) => $q->where('e.district_id', $districtId))
            ->orderBy('k.scope')
            ->orderBy('k.sort')
            ->get([
                'e.id', 'e.kpi_id', 'e.district_id', 'e.period', 'e.value',
                'e.status', 'e.source', 'k.name as kpi_name', 'k.unit as kpi_unit',
                'd.name_cyr as district_name',
            ]);

        $targets = $this->targetsMapForPeriods($rows->pluck('period')->all());

        return $rows->map(function ($r) use ($targets) {
            $target = $targets[$this->key($r->kpi_id, $r->district_id, $r->period)] ?? null;
            $value = (float) $r->value;

            return [
                'id' => $r->id,
                'kpi' => ['id' => $r->kpi_id, 'name' => $r->kpi_name, 'unit' => $r->kpi_unit],
                'district' => $r->district_id === null ? null
                    : ['id' => $r->district_id, 'name' => $r->district_name],
                'period' => $r->period,
                'target' => $target,
                'value' => $value,
                'fulfillment' => $this->fulfillment($value, $target),
                'status' => $r->status,
                'source' => $r->source,
            ];
        })->all();
    }

    /**
     * Qo'lda entry (create/update) — bir (kpi, district, period) uchun bitta yozuv.
     * Qayta kiritilsa qiymat yangilanadi va status 'submitted' (qayta tasdiq talab).
     *
     * @return string entry id
     */
    public function upsertEntry(
        string $kpiId,
        ?string $districtId,
        string $period,
        float $value,
        ?string $note,
        string $enteredByUserId,
    ): string {
        $entry = KpiEntry::updateOrCreate(
            ['kpi_id' => $kpiId, 'district_id' => $districtId, 'period' => $period],
            [
                'value' => $value,
                'note' => $note,
                'source' => 'manual',
                'status' => 'submitted',
                'entered_by' => $enteredByUserId,
                'approved_by' => null,
            ],
        );

        self::invalidateCache();

        return $entry->id;
    }

    /** Tasdiq (viloyat) — status 'approved', approved_by yoziladi. */
    public function approveEntry(KpiEntry $entry, string $approverUserId): void
    {
        $entry->update(['status' => 'approved', 'approved_by' => $approverUserId]);
        self::invalidateCache();
    }

    /**
     * Maqsadli qiymat (target) — bir (kpi, district, period) uchun bitta yozuv
     * (idempotent upsert). Hozircha endpoint YO'Q — service/seed/derivatsiya uchun.
     *
     * @return string target id
     */
    public function setTarget(string $kpiId, ?string $districtId, string $period, float $target): string
    {
        $t = KpiTarget::updateOrCreate(
            ['kpi_id' => $kpiId, 'district_id' => $districtId, 'period' => $period],
            ['target' => $target],
        );

        self::invalidateCache();

        return $t->id;
    }

    // ------------------------------------------------------------- xulosa (viloyat)

    /**
     * Tuman kesimidagi KPI xulosasi (viloyat/bo'linma): o'rtacha ijro %, kiritilgan
     * va tasdiqlangan entrylar soni. Faqat tuman-darajali entrylar (district not null).
     *
     * @return array<int, array{district: array{id: string, name: ?string}, avg_fulfillment: ?float, entered: int, approved: int}>
     */
    public function summary(string $period): array
    {
        return Cache::remember($this->cacheKey('summary', $period), $this->cacheSeconds(), fn () => array_map(fn ($a) => [
            'district' => ['id' => $a['id'], 'name' => $a['name']],
            'avg_fulfillment' => $a['fulfills'] === [] ? null
                : round(array_sum($a['fulfills']) / count($a['fulfills']), 1),
            'entered' => $a['entered'],
            'approved' => $a['approved'],
        ], $this->districtAggregate($period, true)));
    }

    // ------------------------------------------------------------- matritsa (viloyat)

    /**
     * TO'LIQ matritsa (viloyat/bo'linma): tuman KPIlari × 13 tuman, har katakда
     * Reja (target) + Bajarilish (value) + ijro% — Excel svodi ko'rinishi. Keshlanadi
     * (ma'lumot ~2 haftaда bir yangilanadi; yozuvда bekor qilinadi).
     *
     * @return array{districts: array<int,array{id:string,name:?string}>, rows: array<int, array{kpi: array{id:string,code:string,name:string,unit:?string}, cells: array<int, array{district_id:string, target: ?float, value: ?float, fulfillment: ?float}>}>}
     */
    public function matrix(string $period): array
    {
        return Cache::remember($this->cacheKey('matrix', $period), $this->cacheSeconds(), function () use ($period) {
            $districts = DB::connection('advisor')->table('districts')
                ->whereNotNull('soato_code')->orderBy('sort_order')
                ->get(['id', 'name_cyr']);

            $kpis = DB::connection('advisor')->table('kpis')
                ->where('active', true)->where('scope', 'tuman')
                ->orderBy('sort')
                ->get(['id', 'code', 'name', 'unit']);

            $values = [];
            foreach (DB::connection('advisor')->table('kpi_entries')
                ->where('period', $period)->whereNotNull('district_id')
                ->get(['kpi_id', 'district_id', 'value']) as $e) {
                $values[$e->kpi_id.'|'.$e->district_id] = (float) $e->value;
            }
            $targets = [];
            foreach (DB::connection('advisor')->table('kpi_targets')
                ->where('period', $period)->whereNotNull('district_id')
                ->get(['kpi_id', 'district_id', 'target']) as $t) {
                $targets[$t->kpi_id.'|'.$t->district_id] = (float) $t->target;
            }

            $rows = $kpis->map(function ($k) use ($districts, $values, $targets) {
                $cells = $districts->map(function ($d) use ($k, $values, $targets) {
                    $key = $k->id.'|'.$d->id;
                    $target = $targets[$key] ?? null;
                    $value = $values[$key] ?? null;

                    return [
                        'district_id' => $d->id,
                        'target' => $target,
                        'value' => $value,
                        'fulfillment' => $this->fulfillment($value ?? 0.0, $target) === null || $value === null
                            ? null
                            : $this->fulfillment($value, $target),
                    ];
                })->all();

                return [
                    'kpi' => ['id' => $k->id, 'code' => $k->code, 'name' => $k->name, 'unit' => $k->unit],
                    'cells' => $cells,
                ];
            })->all();

            return [
                'districts' => $districts->map(fn ($d) => ['id' => $d->id, 'name' => $d->name_cyr])->all(),
                'rows' => $rows,
            ];
        });
    }

    // ------------------------------------------------------ yillik kesim (tuman)

    /**
     * YILLIK kesim: bitta tuman (yoki viloyat, district null) KPIlari × 4 chorak,
     * har katakда Reja+Bajarilish+ijro% — Excel varag'i ko'rinishi. Tuman maslahatchisi
     * o'z tumanini butun yil bo'yicha ko'radi (chorak tanlashsiz). Keshlanadi.
     *
     * @return array{year: int, quarters: array<int,string>, district_id: ?string, rows: array<int, array{kpi: array{id:string,code:string,name:string,unit:?string}, cells: array<string, array{target:?float,value:?float,fulfillment:?float}>}>}
     */
    public function yearMatrix(?string $districtId, int $year): array
    {
        $suffix = ($districtId ?? 'viloyat').'.'.$year;

        return Cache::remember($this->cacheKey('year', $suffix), $this->cacheSeconds(), function () use ($districtId, $year) {
            $scope = $districtId === null ? 'viloyat' : 'tuman';
            $quarters = ["{$year}-Q1", "{$year}-Q2", "{$year}-Q3", "{$year}-Q4"];

            $kpis = DB::connection('advisor')->table('kpis')
                ->where('active', true)->where('scope', $scope)
                ->orderBy('sort')
                ->get(['id', 'code', 'name', 'unit']);

            $entryQ = DB::connection('advisor')->table('kpi_entries')->whereIn('period', $quarters);
            $targetQ = DB::connection('advisor')->table('kpi_targets')->whereIn('period', $quarters);
            if ($districtId === null) {
                $entryQ->whereNull('district_id');
                $targetQ->whereNull('district_id');
            } else {
                $entryQ->where('district_id', $districtId);
                $targetQ->where('district_id', $districtId);
            }

            $values = [];
            foreach ($entryQ->get(['kpi_id', 'period', 'value']) as $e) {
                $values[$e->kpi_id.'|'.$e->period] = (float) $e->value;
            }
            $targets = [];
            foreach ($targetQ->get(['kpi_id', 'period', 'target']) as $t) {
                $targets[$t->kpi_id.'|'.$t->period] = (float) $t->target;
            }

            $rows = $kpis->map(function ($k) use ($quarters, $values, $targets) {
                $cells = [];
                foreach ($quarters as $p) {
                    $key = $k->id.'|'.$p;
                    $target = $targets[$key] ?? null;
                    $value = $values[$key] ?? null;
                    $cells[$p] = [
                        'target' => $target,
                        'value' => $value,
                        'fulfillment' => ($target !== null && $target > 0 && $value !== null)
                            ? round($value / $target * 100, 1) : null,
                    ];
                }

                return [
                    'kpi' => ['id' => $k->id, 'code' => $k->code, 'name' => $k->name, 'unit' => $k->unit],
                    'cells' => $cells,
                ];
            })->all();

            return ['year' => $year, 'quarters' => $quarters, 'district_id' => $districtId, 'rows' => $rows];
        });
    }

    // ------------------------------------------------------------- kesh

    /**
     * KPI MA'LUMOT keshini bekor qiladi (data versiyasini oshiradi — summary/matrix/
     * year/entries eski kalitlari TTL bilan o'chadi). Har entry/target yozuvida
     * chaqiriladi. Katalog keshiga TEGMAYDI (katalog kamdan-kam o'zgaradi — faqat
     * KpiCatalog::seed bekor qiladi).
     */
    public static function invalidateCache(): void
    {
        $ver = (int) Cache::get('advisor.kpi.data.ver', 1);
        Cache::forever('advisor.kpi.data.ver', $ver + 1);
    }

    private function cacheKey(string $kind, string $suffix): string
    {
        // Katalog alohida versiyada (kamdan-kam o'zgaradi); qolgan (summary/matrix/
        // year/entries) ma'lumot versiyasida — entry/target yozuvida bekor bo'ladi.
        $verKey = $kind === 'catalog' ? 'advisor.kpi.catalog.ver' : 'advisor.kpi.data.ver';
        $ver = (int) Cache::get($verKey, 1);

        return "advisor.kpi.{$kind}.v{$ver}.{$suffix}";
    }

    private function cacheSeconds(): int
    {
        // Ma'lumot ~2 haftaда bir yangilanadi; yozuvда darhol bekor qilinadi.
        return (int) config('advisor.kpi_cache_ttl', 14 * 24 * 60 * 60);
    }

    // ----------------------------------------------------------------- yordamchi

    /**
     * Tuman kesimi agregati (period): id/name/sort + fulfills[]/entered/approved.
     *
     * @return array<int, array{id: string, name: ?string, sort: int, fulfills: array<int, float>, entered: int, approved: int}>
     */
    private function districtAggregate(string $period, bool $ordered = false): array
    {
        $entries = DB::connection('advisor')->table('kpi_entries as e')
            ->join('kpis as k', 'k.id', '=', 'e.kpi_id')
            ->where('k.active', true)
            ->leftJoin('districts as d', 'd.id', '=', 'e.district_id')
            ->where('e.period', $period)
            ->whereNotNull('e.district_id')
            ->get(['e.district_id', 'e.value', 'e.status', 'e.kpi_id', 'd.name_cyr as district_name', 'd.sort_order']);

        $targets = $this->targetsMapForPeriods([$period]);

        $agg = [];
        foreach ($entries as $e) {
            $did = (string) $e->district_id;
            $agg[$did] ??= [
                'id' => $did, 'name' => $e->district_name, 'sort' => (int) $e->sort_order,
                'fulfills' => [], 'entered' => 0, 'approved' => 0,
            ];
            $agg[$did]['entered']++;
            if ($e->status === 'approved') {
                $agg[$did]['approved']++;
            }
            $f = $this->fulfillment((float) $e->value, $targets[$this->key($e->kpi_id, $did, $period)] ?? null);
            if ($f !== null) {
                $agg[$did]['fulfills'][] = $f;
            }
        }

        if ($ordered) {
            uasort($agg, fn ($a, $b) => $a['sort'] <=> $b['sort']);
        }

        return array_values($agg);
    }

    /**
     * (kpi_id, district_id, period) -> target qiymati.
     *
     * @param  array<int, ?string>  $periods
     * @return array<string, float>
     */
    private function targetsMapForPeriods(array $periods): array
    {
        $periods = array_values(array_filter(array_unique($periods)));
        if ($periods === []) {
            return [];
        }

        $map = [];
        $rows = DB::connection('advisor')->table('kpi_targets')
            ->whereIn('period', $periods)
            ->get(['kpi_id', 'district_id', 'period', 'target']);

        foreach ($rows as $r) {
            $map[$this->key($r->kpi_id, $r->district_id, $r->period)] = (float) $r->target;
        }

        return $map;
    }

    private function key(string $kpiId, ?string $districtId, string $period): string
    {
        return $kpiId.'|'.($districtId ?? '_').'|'.$period;
    }

    private function fulfillment(float $value, ?float $target): ?float
    {
        if ($target === null || $target <= 0.0) {
            return null;
        }

        return round($value / $target * 100, 1);
    }
}
