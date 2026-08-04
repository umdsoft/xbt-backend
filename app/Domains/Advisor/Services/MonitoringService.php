<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Services;

use App\Domains\Advisor\Models\MonitoringEntry;
use App\Domains\Advisor\Models\MonitoringSheet;
use App\Domains\Advisor\Models\MonitoringValue;
use App\Domains\Advisor\Support\AdvisorScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * «СВОД ЖАДВАЛЛАР» moduli — qaror/farmon ijrosi monitoringi (spec: docs/svod-jadvallar-spec).
 *
 * Har svod = qaror/farmon; ustunlari og'irlikли; (svod × tuman) satri tasdiq-oqimи bilan.
 * UMUMIY TAYYORLIK (tuman) = Σ(value×weight)/Σ(weight) (weightlar 0 -> oddiy o'rtacha).
 * Ijro holati %dан avtomatik: 0 -> not_started; ≥threshold -> done; aks holда in_progress.
 * JAMI/O'RTACHA = 13 tuman o'rtachasi (yozuvsiz tuman = 0).
 */
class MonitoringService
{
    public const REVIEW_STATUSES = ['draft', 'submitted', 'confirmed', 'returned'];

    // ----------------------------------------------------------------- ro'yxat

    /**
     * Svodlar ro'yxati (filtr + har svodда umumiy% + tasdiq holati).
     *
     * @param  array<string, mixed>  $filters  category, q, status
     * @return array<int, array<string, mixed>>
     */
    public function listSheets(array $filters = []): array
    {
        $sheets = MonitoringSheet::query()
            ->when(($filters['category'] ?? null) !== null, fn ($q) => $q->where('category', $filters['category']))
            ->when(($filters['status'] ?? null) !== null, fn ($q) => $q->where('status', $filters['status']))
            ->when(($filters['q'] ?? null) !== null, fn ($q) => $q->where(function ($w) use ($filters) {
                $w->where('title', 'ilike', '%'.$filters['q'].'%')
                    ->orWhere('reference_no', 'ilike', '%'.$filters['q'].'%');
            }))
            ->orderByDesc('created_at')
            ->get();

        if ($sheets->isEmpty()) {
            return [];
        }

        $districtCount = $this->districtCount();
        $sheetIds = $sheets->pluck('id')->all();

        // Har svod bo'yicha entrylar (tasdiq holati sanog'i) + qiymatlar (umumiy% uchun).
        $metricsBySheet = $this->metricsBySheet($sheetIds);
        $entries = MonitoringEntry::query()->whereIn('sheet_id', $sheetIds)->get();
        $valueMap = $this->valueMapForEntries($entries->pluck('id')->all());

        $bySheet = [];
        foreach ($entries as $e) {
            $bySheet[$e->sheet_id][] = $e;
        }

        return $sheets->map(function (MonitoringSheet $s) use ($bySheet, $metricsBySheet, $valueMap, $districtCount) {
            $rows = $bySheet[$s->id] ?? [];
            $metrics = $metricsBySheet[$s->id] ?? [];

            $confirmed = 0;
            $submitted = 0;
            $sumOverall = 0.0;
            foreach ($rows as $e) {
                if ($e->review_status === 'confirmed') {
                    $confirmed++;
                } elseif ($e->review_status === 'submitted') {
                    $submitted++;
                }
                $sumOverall += $this->overallFor($valueMap[$e->id] ?? [], $metrics);
            }

            return [
                'id' => $s->id,
                'title' => $s->title,
                'basis' => $s->basis,
                'reference_no' => $s->reference_no,
                'category' => $s->category,
                'as_of_date' => $s->as_of_date?->toDateString(),
                'status' => $s->status,
                'metrics_count' => count($metrics),
                'overall' => $districtCount > 0 ? round($sumOverall / $districtCount, 1) : 0,
                'confirmed_count' => $confirmed,
                'submitted_count' => $submitted,
                'district_count' => $districtCount,
                'created_at' => $s->created_at?->toIso8601String(),
            ];
        })->all();
    }

    // ----------------------------------------------------------------- yaratish

    /**
     * Yangi svod + ustunlar (viloyat). Ustun og'irliklari saqlanadi.
     *
     * @param  array<string, mixed>  $data  sheet maydonlari
     * @param  array<int, array{name:string,weight?:float,unit?:string}>  $metrics
     */
    public function createSheet(array $data, array $metrics, string $userId): MonitoringSheet
    {
        $sheet = MonitoringSheet::create([
            'title' => $data['title'],
            'basis' => $data['basis'] ?? null,
            'reference_no' => $data['reference_no'] ?? null,
            'reference_date' => $data['reference_date'] ?? null,
            'category' => $data['category'] ?? 'other',
            'as_of_date' => $data['as_of_date'] ?? Carbon::today()->toDateString(),
            'completion_threshold' => (int) ($data['completion_threshold'] ?? 100),
            'status' => 'active',
            'created_by' => $userId,
        ]);

        $this->syncMetrics($sheet, $metrics);

        return $sheet;
    }

    /**
     * Ustunlarni almashtiradi (svod tahririда) — sort_order tartibда.
     *
     * @param  array<int, array{name:string,weight?:float,unit?:string}>  $metrics
     */
    public function syncMetrics(MonitoringSheet $sheet, array $metrics): void
    {
        $sheet->metrics()->delete();
        $sort = 10;
        foreach ($metrics as $m) {
            if (trim((string) ($m['name'] ?? '')) === '') {
                continue;
            }
            $sheet->metrics()->create([
                'name' => $m['name'],
                'unit' => $m['unit'] ?? '%',
                'weight' => (float) ($m['weight'] ?? 0),
                'sort_order' => $sort,
            ]);
            $sort += 10;
        }
    }

    // ----------------------------------------------------------------- tafsilot

    /**
     * Svod tafsiloti — 13 tuman × ustunlar (qiymat) + UMUMIY + JAMI/O'RTACHA + tasdiq holati.
     * Barcha rol butun svodни ko'radi (tuman FAQAT o'z satrини tahrirlaydi — kontroller/UI).
     *
     * @return array<string, mixed>
     */
    public function sheetDetail(MonitoringSheet $sheet): array
    {
        $metrics = $sheet->metrics()->get(['id', 'name', 'unit', 'weight', 'sort_order']);
        $districts = $this->districts(); // id => name

        $entries = MonitoringEntry::query()->where('sheet_id', $sheet->id)->get()->keyBy('district_id');
        $valueMap = $this->valueMapForEntries($entries->pluck('id')->all());

        $rows = [];
        $metricSums = [];
        foreach ($metrics as $m) {
            $metricSums[$m->id] = 0.0;
        }
        $overallSum = 0.0;
        $count = count($districts);

        foreach ($districts as $did => $name) {
            $entry = $entries->get($did);
            $vals = $entry !== null ? ($valueMap[$entry->id] ?? []) : [];
            $overall = $this->overallFor($vals, $metrics);

            $cells = [];
            foreach ($metrics as $m) {
                $v = (float) ($vals[$m->id] ?? 0);
                $cells[] = ['metric_id' => $m->id, 'value' => $v];
                $metricSums[$m->id] += $v;
            }
            $overallSum += $overall;

            $rows[] = [
                'district' => ['id' => $did, 'name' => $name],
                'cells' => $cells,
                'overall' => $overall,
                'exec_status' => $this->execStatus($overall, (int) $sheet->completion_threshold),
                'review_status' => $entry->review_status ?? 'draft',
                'note' => $entry->note ?? null,
                'return_comment' => $entry->return_comment ?? null,
                'submitted_at' => $entry?->submitted_at?->toIso8601String(),
                'confirmed_at' => $entry?->confirmed_at?->toIso8601String(),
                'updated_at' => $entry?->updated_at?->toIso8601String(),
            ];
        }

        // JAMI/O'RTACHA (13 tuman o'rtachasi; yozuvsiz = 0).
        $totalsPerMetric = [];
        foreach ($metrics as $m) {
            $totalsPerMetric[] = [
                'metric_id' => $m->id,
                'avg' => $count > 0 ? round($metricSums[$m->id] / $count, 1) : 0,
            ];
        }

        return [
            'sheet' => $this->presentSheet($sheet),
            'metrics' => $metrics->map(fn ($m) => [
                'id' => $m->id, 'name' => $m->name, 'unit' => $m->unit,
                'weight' => (float) $m->weight, 'sort_order' => (int) $m->sort_order,
            ])->all(),
            'rows' => $rows,
            'totals' => [
                'per_metric' => $totalsPerMetric,
                'overall' => $count > 0 ? round($overallSum / $count, 1) : 0,
            ],
        ];
    }

    // ------------------------------------------------------------- kiritish/tasdiq

    /**
     * Tuman O'Z satrини kiritadi/yuboradi. Qiymatlar (metric_id=>value) upsert;
     * $submit=true -> review_status 'submitted' (tasdiq navbati), aks holда 'draft'.
     * Qayta yuborilса — confirmed/returned tozalanadi.
     *
     * @param  array<string, float>  $values  metric_id => value (0..100)
     */
    public function upsertEntry(
        MonitoringSheet $sheet,
        string $districtId,
        array $values,
        ?string $note,
        bool $submit,
        string $userId,
    ): void {
        $entry = MonitoringEntry::updateOrCreate(
            ['sheet_id' => $sheet->id, 'district_id' => $districtId],
            [
                'note' => $note,
                'review_status' => $submit ? 'submitted' : 'draft',
                'submitted_at' => $submit ? now() : null,
                'submitted_by' => $submit ? $userId : null,
                'confirmed_at' => null,
                'confirmed_by' => null,
                'return_comment' => null,
                'updated_by' => $userId,
            ],
        );

        // Faqat shu svod ustunlari uchun qiymat (begona metric_id e'tiborsiz).
        $validMetricIds = $sheet->metrics()->pluck('id')->all();
        foreach ($values as $metricId => $value) {
            if (! in_array($metricId, $validMetricIds, true)) {
                continue;
            }
            MonitoringValue::updateOrCreate(
                ['entry_id' => $entry->id, 'metric_id' => $metricId],
                ['value' => max(0, min(100, (float) $value))],
            );
        }
    }

    /** Viloyat tasdiqlaydi (submitted -> confirmed). */
    public function confirmEntry(MonitoringSheet $sheet, string $districtId, string $userId): bool
    {
        $entry = MonitoringEntry::where('sheet_id', $sheet->id)->where('district_id', $districtId)->first();
        if ($entry === null) {
            return false;
        }
        $entry->update([
            'review_status' => 'confirmed',
            'confirmed_at' => now(),
            'confirmed_by' => $userId,
            'return_comment' => null,
        ]);

        return true;
    }

    /** Viloyat qaytaradi (izoh bilan) — tuman qayta kiritadi. */
    public function returnEntry(MonitoringSheet $sheet, string $districtId, string $comment, string $userId): bool
    {
        $entry = MonitoringEntry::where('sheet_id', $sheet->id)->where('district_id', $districtId)->first();
        if ($entry === null) {
            return false;
        }
        $entry->update([
            'review_status' => 'returned',
            'return_comment' => $comment,
            'confirmed_at' => null,
            'confirmed_by' => null,
            'updated_by' => $userId,
        ]);

        return true;
    }

    // ------------------------------------------------------------- nusxa / tahrir

    /** Svodни nusxalaydi (ustunlar ko'chiriladi; satrlar YO'Q) — shablon sifatida tez yaratish. */
    public function duplicateSheet(MonitoringSheet $sheet, string $userId): MonitoringSheet
    {
        $copy = MonitoringSheet::create([
            'title' => $sheet->title.' (нусха)',
            'basis' => $sheet->basis,
            'reference_no' => null,
            'reference_date' => null,
            'category' => $sheet->category,
            'as_of_date' => Carbon::today()->toDateString(),
            'completion_threshold' => (int) $sheet->completion_threshold,
            'status' => 'active',
            'created_by' => $userId,
        ]);

        $metrics = $sheet->metrics()->orderBy('sort_order')->get(['name', 'unit', 'weight'])
            ->map(fn ($m) => ['name' => $m->name, 'unit' => $m->unit, 'weight' => (float) $m->weight])->all();
        $this->syncMetrics($copy, $metrics);

        return $copy;
    }

    /**
     * Svod meta'sini yangilaydi. Ustunlarni FAQAT satrlar (entry) hali yo'q bo'lса
     * almashtiradi (ma'lumot kiritilгач ustun o'zgartirish qiymatларни buzади).
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array{name:string,weight?:float,unit?:string}>|null  $metrics
     * @return bool  metrics almashtirildimi
     */
    public function updateSheet(MonitoringSheet $sheet, array $data, ?array $metrics = null): bool
    {
        $sheet->update(array_filter([
            'title' => $data['title'] ?? null,
            'basis' => $data['basis'] ?? null,
            'reference_no' => $data['reference_no'] ?? null,
            'reference_date' => $data['reference_date'] ?? null,
            'category' => $data['category'] ?? null,
            'as_of_date' => $data['as_of_date'] ?? null,
            'completion_threshold' => $data['completion_threshold'] ?? null,
            'status' => $data['status'] ?? null,
        ], fn ($v) => $v !== null));

        if ($metrics !== null && ! $this->hasEntries($sheet)) {
            $this->syncMetrics($sheet, $metrics);

            return true;
        }

        return false;
    }

    private function hasEntries(MonitoringSheet $sheet): bool
    {
        return MonitoringEntry::where('sheet_id', $sheet->id)->exists();
    }

    // ------------------------------------------------------------- Excel eksport

    /**
     * Excel grid — sizning svod formatida (T/r, Tuman, Holat, ustunlar, UMUMIY) + JAMI.
     *
     * @return array{title: string, headers: array<int,string>, rows: array<int,array<int,string>>}
     */
    public function exportGrid(MonitoringSheet $sheet): array
    {
        $d = $this->sheetDetail($sheet);

        $headers = ['Т/р', 'Туман (шаҳар)', 'Жорий ҳолати'];
        foreach ($d['metrics'] as $m) {
            $headers[] = $m['name'].($m['unit'] ? ', '.$m['unit'] : '');
        }
        $headers[] = 'УМУМИЙ ТАЙЁРЛИК, %';

        $rows = [];
        $i = 1;
        foreach ($d['rows'] as $r) {
            $row = [(string) $i++, (string) $r['district']['name'], $this->execLabel($r['exec_status'])];
            foreach ($r['cells'] as $c) {
                $row[] = $this->num($c['value']);
            }
            $row[] = $this->num($r['overall']);
            $rows[] = $row;
        }

        $jami = ['', 'ЖАМИ / Ўртача', ''];
        foreach ($d['totals']['per_metric'] as $t) {
            $jami[] = $this->num($t['avg']);
        }
        $jami[] = $this->num($d['totals']['overall']);
        $rows[] = $jami;

        return ['title' => $sheet->title, 'headers' => $headers, 'rows' => $rows];
    }

    private function execLabel(string $status): string
    {
        return match ($status) {
            'done' => 'Ишга тушган',
            'in_progress' => 'Жараёнда',
            default => 'Бошланмаган',
        };
    }

    private function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
    }

    // ------------------------------------------------------------- statistika

    /**
     * Svod statistikasi — rolга qarab. Birlik = (svod × tuman) satri.
     *   - tuman: o'z satrlari (o'rtacha tayyorlik + tasdiq holati sanog'i);
     *   - viloyat/bo'линма: umumiy o'rtacha + tuman kesimi (leaderboard) + tasdiq holati.
     *
     * @return array<string, mixed>
     */
    public function stats(AdvisorScope $scope): array
    {
        $sheets = MonitoringSheet::query()->where('status', 'active')->get(['id', 'completion_threshold']);
        $sheetIds = $sheets->pluck('id')->all();
        $metricsBySheet = $this->metricsBySheet($sheetIds);
        $entries = MonitoringEntry::query()->whereIn('sheet_id', $sheetIds)->get();
        $valueMap = $this->valueMapForEntries($entries->pluck('id')->all());

        // (sheet_id, district_id) -> entry
        $entryMap = [];
        foreach ($entries as $e) {
            $entryMap[$e->sheet_id][$e->district_id] = $e;
        }

        if ($scope->isTuman()) {
            $d = (string) $scope->districtId;
            $sum = 0.0;
            $counts = ['confirmed' => 0, 'submitted' => 0, 'returned' => 0, 'not_entered' => 0];
            foreach ($sheets as $s) {
                $e = $entryMap[$s->id][$d] ?? null;
                $sum += $this->overallFor($e !== null ? ($valueMap[$e->id] ?? []) : [], $metricsBySheet[$s->id] ?? []);
                $status = $e->review_status ?? 'not_entered';
                if ($status === 'draft') {
                    $status = 'not_entered';
                }
                $counts[$status] = ($counts[$status] ?? 0) + 1;
            }
            $n = $sheets->count();

            return [
                'role' => 'tuman',
                'sheets' => $n,
                'avg_readiness' => $n > 0 ? round($sum / $n, 1) : 0,
                'confirmed' => $counts['confirmed'],
                'submitted' => $counts['submitted'],
                'returned' => $counts['returned'],
                'not_entered' => $counts['not_entered'],
            ];
        }

        // Viloyat/bo'линма.
        $districts = $this->districts();
        $overallSum = 0.0;
        $cells = 0;
        $per = [];
        foreach ($districts as $id => $name) {
            $per[$id] = ['district' => ['id' => (string) $id, 'name' => $name], 'sum' => 0.0, 'confirmed' => 0];
        }
        $conf = ['confirmed' => 0, 'submitted' => 0, 'returned' => 0, 'not_entered' => 0];

        foreach ($sheets as $s) {
            foreach ($districts as $id => $name) {
                $e = $entryMap[$s->id][$id] ?? null;
                $overall = $this->overallFor($e !== null ? ($valueMap[$e->id] ?? []) : [], $metricsBySheet[$s->id] ?? []);
                $overallSum += $overall;
                $cells++;
                $per[$id]['sum'] += $overall;
                $status = $e->review_status ?? 'not_entered';
                if ($status === 'draft') {
                    $status = 'not_entered';
                }
                if ($status === 'confirmed') {
                    $per[$id]['confirmed']++;
                }
                $conf[$status] = ($conf[$status] ?? 0) + 1;
            }
        }

        $n = $sheets->count();
        $perDistrict = array_map(fn ($p) => [
            'district' => $p['district'],
            'avg_readiness' => $n > 0 ? round($p['sum'] / $n, 1) : 0,
            'confirmed' => $p['confirmed'],
        ], array_values($per));
        usort($perDistrict, fn ($a, $b) => $b['avg_readiness'] <=> $a['avg_readiness']);

        return [
            'role' => $scope->role,
            'sheets' => $n,
            'avg_readiness' => $cells > 0 ? round($overallSum / $cells, 1) : 0,
            'confirmation' => $conf,
            'per_district' => $perDistrict,
        ];
    }

    // ----------------------------------------------------------------- yordamchi

    /** UMUMIY TAYYORLIK — Σ(value×weight)/Σ(weight); weightlar 0 -> oddiy o'rtacha. */
    private function overallFor(array $valueMap, $metrics): float
    {
        $wSum = 0.0;
        $vwSum = 0.0;
        $vSum = 0.0;
        $n = 0;
        foreach ($metrics as $m) {
            $w = (float) $m->weight;
            $v = (float) ($valueMap[$m->id] ?? 0);
            $wSum += $w;
            $vwSum += $v * $w;
            $vSum += $v;
            $n++;
        }
        if ($n === 0) {
            return 0;
        }

        return $wSum > 0 ? round($vwSum / $wSum, 1) : round($vSum / $n, 1);
    }

    /** Ijro holati (rang) — %dан avtomatik. */
    private function execStatus(float $overall, int $threshold): string
    {
        if ($overall <= 0) {
            return 'not_started';
        }

        return $overall >= $threshold ? 'done' : 'in_progress';
    }

    /** @return array<string, mixed> */
    private function presentSheet(MonitoringSheet $sheet): array
    {
        return [
            'id' => $sheet->id,
            'title' => $sheet->title,
            'basis' => $sheet->basis,
            'reference_no' => $sheet->reference_no,
            'reference_date' => $sheet->reference_date?->toDateString(),
            'category' => $sheet->category,
            'as_of_date' => $sheet->as_of_date?->toDateString(),
            'completion_threshold' => (int) $sheet->completion_threshold,
            'status' => $sheet->status,
            'created_at' => $sheet->created_at?->toIso8601String(),
        ];
    }

    /**
     * entry_id -> [metric_id => value].
     *
     * @param  array<int, string>  $entryIds
     * @return array<string, array<string, float>>
     */
    private function valueMapForEntries(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }
        $map = [];
        foreach (MonitoringValue::whereIn('entry_id', $entryIds)->get(['entry_id', 'metric_id', 'value']) as $v) {
            $map[$v->entry_id][$v->metric_id] = (float) $v->value;
        }

        return $map;
    }

    /**
     * sheet_id -> metrics collection (id,weight).
     *
     * @param  array<int, string>  $sheetIds
     * @return array<string, \Illuminate\Support\Collection>
     */
    private function metricsBySheet(array $sheetIds): array
    {
        return DB::connection('advisor')->table('monitoring_metrics')
            ->whereIn('sheet_id', $sheetIds)
            ->orderBy('sort_order')
            ->get(['id', 'sheet_id', 'weight'])
            ->groupBy('sheet_id')
            ->all();
    }

    /** 13 tuman: id => name_cyr (soato bor, tartibda). */
    private function districts(): array
    {
        return DB::connection('master')->table('districts')
            ->whereNotNull('soato_code')
            ->orderBy('sort_order')
            ->pluck('name_cyr', 'id')
            ->all();
    }

    private function districtCount(): int
    {
        return (int) DB::connection('master')->table('districts')->whereNotNull('soato_code')->count();
    }
}
