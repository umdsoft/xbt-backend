<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Services;

use App\Domains\Advisor\Models\ActionPlan;
use App\Domains\Advisor\Models\ActionPlanEntry;
use App\Domains\Advisor\Models\ActionPlanItem;
use App\Domains\Advisor\Support\AdvisorScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CHORA-TADBIRLAR moduli — yillik reja (action_plans) + bandlar (items) +
 * bajarilishi JURNALI (action_plan_entries: arxiv modeli).
 *
 * Tuman «bajarildi» deb tasdiqlamaydi — faqat MA'LUMOT kiritadi (hisobot+sana+
 * ixtiyoriy foiz). Davriy topshiriqlar uchun bir nechta yozuv to'planadi; viloyat
 * butun tarixni (arxiv) monitoring qiladi. Qamrov (AdvisorScope): tuman FAQAT o'z
 * tumani; viloyat/bo'linма hammasi. Reja EGASI (district_id): null=umumiy, uuid=tuman.
 */
class ActionPlanService
{
    // ------------------------------------------------------------- ro'yxat

    /** Berilgan yil uchun eng so'nggi reja (yoki null). */
    public function activePlan(int $year): ?ActionPlan
    {
        return ActionPlan::query()->where('year', $year)->orderByDesc('created_at')->first();
    }

    /**
     * Rejalar ro'yxati — band soni + hujjat + EGA. Tuman FAQAT o'z + umumiy rejani.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listPlans(AdvisorScope $scope): array
    {
        $counts = DB::connection('advisor')->table('action_plan_items')
            ->whereNull('deleted_at')
            ->groupBy('plan_id')
            ->selectRaw('plan_id, count(*) as n')
            ->pluck('n', 'plan_id');

        $districts = $this->districts();

        $plans = ActionPlan::query()
            ->when($scope->isTuman(), fn ($q) => $q->where(function ($w) use ($scope) {
                $w->whereNull('district_id')->orWhere('district_id', $scope->districtId);
            }))
            ->orderByDesc('year')
            ->orderByDesc('created_at')
            ->get();

        return $plans->map(fn (ActionPlan $p) => $this->presentPlan($p, $districts) + [
            'items_count' => (int) ($counts[$p->id] ?? 0),
        ])->all();
    }

    /**
     * Reja tafsiloti (bo'limlar bo'yicha bandlar) qamrovga qarab:
     *   - tuman: har band `my_progress` (o'z jurnal xulosasi); summary=null.
     *   - viloyat/bo'линма: har band `summary` (tuman kesimi: kiritilган/jami).
     *
     * @return array{plan: array<string,mixed>, sections: array<int, array{title: string, items: array<int, array<string,mixed>>}>}
     */
    public function planOverview(ActionPlan $plan, AdvisorScope $scope): array
    {
        $items = DB::connection('advisor')->table('action_plan_items')
            ->where('plan_id', $plan->id)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->get([
                'id', 'section_title', 'item_number', 'title', 'mechanism', 'steps',
                'deadline_text', 'deadline', 'responsible_text', 'scope', 'sort_order',
            ]);

        $today = Carbon::today();
        $districtCount = $this->districtCount();
        $ownerDistrict = $plan->district_id;
        $entriesByItem = $this->entriesByItem($items->pluck('id')->all());

        $sections = [];
        $sectionIndex = [];

        foreach ($items as $item) {
            $byDistrict = $entriesByItem[$item->id] ?? [];

            $present = [
                'id' => $item->id,
                'section_title' => $item->section_title,
                'item_number' => $item->item_number,
                'title' => $item->title,
                'mechanism' => $item->mechanism,
                'steps' => $item->steps ? json_decode($item->steps, true) : null,
                'deadline_text' => $item->deadline_text,
                'deadline' => $item->deadline === null ? null : Carbon::parse($item->deadline)->toDateString(),
                'responsible_text' => $item->responsible_text,
                'scope' => $item->scope,
                'sort_order' => (int) $item->sort_order,
                'my_progress' => null,
                'summary' => null,
                'overdue' => false,
            ];

            if ($scope->isTuman()) {
                $agg = $this->districtAgg($byDistrict[$scope->districtId] ?? []);
                $present['my_progress'] = $agg;
                $present['overdue'] = $this->isOverdue($item->deadline, $today, $agg['reported']);
            } elseif ($ownerDistrict !== null) {
                // Tuman O'Z rejasi — bitta tuman kesimi.
                $agg = $this->districtAgg($byDistrict[$ownerDistrict] ?? []);
                $present['summary'] = [
                    'total' => 1,
                    'reported' => $agg['reported'] ? 1 : 0,
                    'not_reported' => $agg['reported'] ? 0 : 1,
                    'avg_progress' => $agg['progress_percent'],
                ];
                $present['overdue'] = $this->isOverdue($item->deadline, $today, $agg['reported']);
            } elseif ($item->scope === 'viloyat') {
                $agg = $this->districtAgg($byDistrict[''] ?? []);
                $present['summary'] = [
                    'total' => 1,
                    'reported' => $agg['reported'] ? 1 : 0,
                    'not_reported' => $agg['reported'] ? 0 : 1,
                    'avg_progress' => $agg['progress_percent'],
                ];
                $present['overdue'] = $this->isOverdue($item->deadline, $today, $agg['reported']);
            } else {
                // all_districts (umumiy) — 13 tuman kesimi.
                $reported = 0;
                $sum = 0;
                foreach ($this->districts() as $did => $name) {
                    $a = $this->districtAgg($byDistrict[$did] ?? []);
                    if ($a['reported']) {
                        $reported++;
                    }
                    $sum += $a['progress_percent'];
                }
                $present['summary'] = [
                    'total' => $districtCount,
                    'reported' => $reported,
                    'not_reported' => max(0, $districtCount - $reported),
                    'avg_progress' => $districtCount > 0 ? (int) round($sum / $districtCount) : 0,
                ];
                $present['overdue'] = $this->isOverdue($item->deadline, $today, $reported >= $districtCount && $districtCount > 0);
            }

            if (! isset($sectionIndex[$item->section_title])) {
                $sectionIndex[$item->section_title] = count($sections);
                $sections[] = ['title' => $item->section_title, 'items' => []];
            }
            $sections[$sectionIndex[$item->section_title]]['items'][] = $present;
        }

        return ['plan' => $this->presentPlan($plan), 'sections' => $sections];
    }

    /**
     * @param  array<string,string>|null  $districts
     * @return array<string, mixed>
     */
    private function presentPlan(ActionPlan $plan, ?array $districts = null): array
    {
        $owner = null;
        if ($plan->district_id !== null) {
            $name = $districts !== null
                ? ($districts[$plan->district_id] ?? null)
                : DB::connection('master')->table('districts')->where('id', $plan->district_id)->value('name_cyr');
            $owner = ['id' => $plan->district_id, 'name' => $name];
        }

        return [
            'id' => $plan->id,
            'year' => (int) $plan->year,
            'title' => $plan->title,
            'status' => $plan->status,
            'district' => $owner,
            'document' => $plan->document_path === null ? null : ['name' => $plan->document_name],
            'created_at' => $plan->created_at?->toIso8601String(),
        ];
    }

    // ------------------------------------------------------------- reja/band CRUD

    /**
     * @param  array<string, mixed>  $data
     */
    public function createPlan(array $data, string $userId): ActionPlan
    {
        return ActionPlan::create([
            'year' => (int) $data['year'],
            'title' => $data['title'],
            'status' => $data['status'] ?? 'active',
            'district_id' => $data['district_id'] ?? null,
            'created_by' => $userId,
        ]);
    }

    /**
     * Reja meta tahriri (title/year/status). Egalik kontrollerда tekshiriladi.
     *
     * @param  array<string, mixed>  $data
     */
    public function updatePlan(ActionPlan $plan, array $data): void
    {
        $plan->update(array_filter([
            'title' => $data['title'] ?? null,
            'year' => isset($data['year']) ? (int) $data['year'] : null,
            'status' => $data['status'] ?? null,
        ], fn ($v) => $v !== null));
    }

    public function storeDocument(ActionPlan $plan, \Illuminate\Http\UploadedFile $file): void
    {
        $disk = (string) config('advisor.files_disk', 'local');
        $path = $file->store("advisor/action-plans/{$plan->id}", $disk);
        $plan->update([
            'document_path' => $path,
            'document_name' => $file->getClientOriginalName(),
            'document_mime' => (string) $file->getClientMimeType(),
            'document_size' => $file->getSize(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return string band id
     */
    public function addItem(ActionPlan $plan, array $data): string
    {
        $maxSort = (int) DB::connection('advisor')->table('action_plan_items')
            ->where('plan_id', $plan->id)->max('sort_order');

        $item = ActionPlanItem::create([
            'plan_id' => $plan->id,
            'section_title' => $data['section_title'],
            'item_number' => $data['item_number'],
            'title' => $data['title'],
            'responsible_text' => $data['responsible_text'] ?? null,
            'scope' => $data['scope'] ?? 'all_districts',
            'sort_order' => $maxSort + 10,
        ] + $this->stepFields($data));

        return $item->id;
    }

    /**
     * Mexanizm bosqichlari (steps=[{text,deadline}]) berilса — steps saqlanadi, band
     * deadline = ENG KЕЧ bosqich sanаси (umumiy overdue uchun). Aks holда eski
     * mechanism/deadline matnи.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function stepFields(array $data): array
    {
        $steps = $data['steps'] ?? null;
        if (is_array($steps)) {
            $clean = [];
            foreach ($steps as $s) {
                $text = trim((string) ($s['text'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $clean[] = ['text' => $text, 'deadline' => ($s['deadline'] ?? null) ?: null];
            }
            if ($clean !== []) {
                $deadlines = array_values(array_filter(array_column($clean, 'deadline')));

                return [
                    'steps' => $clean,
                    'mechanism' => null,
                    'deadline_text' => null,
                    'deadline' => $deadlines === [] ? null : max($deadlines),
                ];
            }
        }

        return [
            'steps' => null,
            'mechanism' => $data['mechanism'] ?? null,
            'deadline_text' => $data['deadline_text'] ?? null,
            'deadline' => $data['deadline'] ?? null,
        ];
    }

    /**
     * Band tahriri (nomi/mexanizm/muddat/mas'ul/bo'lim/raqam).
     *
     * @param  array<string, mixed>  $data
     */
    public function updateItem(ActionPlanItem $item, array $data): void
    {
        if (array_key_exists('section_title', $data)) {
            $item->section_title = $data['section_title'];
        }
        if (array_key_exists('item_number', $data)) {
            $item->item_number = $data['item_number'];
        }
        if (array_key_exists('title', $data)) {
            $item->title = $data['title'];
        }
        if (array_key_exists('responsible_text', $data)) {
            $item->responsible_text = $data['responsible_text'];
        }
        // Bosqichlar/mexanizm+muddat.
        if (array_key_exists('steps', $data) || array_key_exists('mechanism', $data) || array_key_exists('deadline', $data)) {
            $sf = $this->stepFields($data);
            $item->steps = $sf['steps'];
            $item->mechanism = $sf['mechanism'];
            $item->deadline_text = $sf['deadline_text'];
            $item->deadline = $sf['deadline'];
        }
        $item->save();
    }

    /** Bandni o'chiradi (soft delete). */
    public function deleteItem(ActionPlanItem $item): void
    {
        $item->delete();
    }

    // ------------------------------------------------------------- bajarilishi (jurnal)

    /**
     * Bitta band bo'yicha tuman kesimi (viloyat/bo'линма: 13 tuman; tuman: o'zi) —
     * har tuman uchun JURNAL XULOSASI (kiritilgan/foiz/oxirgi sana/soni).
     *
     * @return array<string, mixed>|null
     */
    public function itemRows(ActionPlanItem $item, AdvisorScope $scope): ?array
    {
        $byDistrict = $this->entriesByItem([$item->id])[$item->id] ?? [];

        $itemPresent = [
            'id' => $item->id,
            'item_number' => $item->item_number,
            'title' => $item->title,
            'scope' => $item->scope,
        ];

        $ownerDistrict = DB::connection('advisor')->table('action_plans')
            ->where('id', $item->plan_id)->value('district_id');

        // Tuman O'Z rejasi bandi — FAQAT o'sha tuman qatori.
        if ($ownerDistrict !== null) {
            $name = $this->districts()[$ownerDistrict] ?? null;

            return ['item' => $itemPresent, 'rows' => [$this->row($ownerDistrict, $name, $byDistrict[$ownerDistrict] ?? [])]];
        }

        if ($item->scope === 'viloyat') {
            if ($scope->isTuman()) {
                return ['item' => $itemPresent, 'rows' => []];
            }

            return ['item' => $itemPresent, 'rows' => [$this->row(null, 'Вилоят даражаси', $byDistrict[''] ?? [])]];
        }

        $districts = $this->districts();
        if ($scope->isTuman()) {
            $name = $districts[$scope->districtId] ?? null;
            $rows = $name === null ? [] : [$this->row($scope->districtId, $name, $byDistrict[$scope->districtId] ?? [])];

            return ['item' => $itemPresent, 'rows' => $rows];
        }

        $out = [];
        foreach ($districts as $id => $name) {
            $out[] = $this->row($id, $name, $byDistrict[$id] ?? []);
        }

        return ['item' => $itemPresent, 'rows' => $out];
    }

    /**
     * Bitta (band × tuman) uchun to'liq JURNAL (arxiv) — barcha yozuvlar (yangi -> eski).
     *
     * @return array<int, array<string, mixed>>
     */
    public function itemArchive(ActionPlanItem $item, ?string $districtId): array
    {
        return ActionPlanEntry::query()
            ->where('item_id', $item->id)
            ->when($districtId !== null, fn ($q) => $q->where('district_id', $districtId))
            ->when($districtId === null, fn ($q) => $q->whereNull('district_id'))
            ->orderByDesc('occurred_at')->orderByDesc('created_at')
            ->get(['id', 'district_id', 'report', 'progress_percent', 'occurred_at', 'created_by', 'created_at'])
            ->map(fn ($e) => [
                'id' => $e->id,
                'report' => $e->report,
                'progress_percent' => $e->progress_percent === null ? null : (int) $e->progress_percent,
                'occurred_at' => Carbon::parse($e->occurred_at)->toDateString(),
                'created_at' => $e->created_at === null ? null : Carbon::parse($e->created_at)->toIso8601String(),
            ])->all();
    }

    /** Jurnalga yangi yozuv (ma'lumot kiritish). @return string entry id */
    public function addEntry(ActionPlanItem $item, ?string $districtId, string $report, ?int $progress, ?string $occurredAt, string $userId): string
    {
        $entry = ActionPlanEntry::create([
            'item_id' => $item->id,
            'district_id' => $districtId,
            'report' => $report,
            'progress_percent' => $progress === null ? null : max(0, min(100, $progress)),
            'occurred_at' => $occurredAt ?? Carbon::today()->toDateString(),
            'created_by' => $userId,
        ]);

        return $entry->id;
    }

    /** Yozuvni tahrirlaydi (egasi). @param  array<string, mixed>  $data */
    public function updateEntry(ActionPlanEntry $entry, array $data): void
    {
        if (array_key_exists('report', $data) && $data['report'] !== null) {
            $entry->report = $data['report'];
        }
        if (array_key_exists('progress_percent', $data)) {
            $entry->progress_percent = $data['progress_percent'] === null
                ? null : max(0, min(100, (int) $data['progress_percent']));
        }
        if (array_key_exists('occurred_at', $data) && $data['occurred_at'] !== null) {
            $entry->occurred_at = $data['occurred_at'];
        }
        $entry->save();
    }

    public function deleteEntry(ActionPlanEntry $entry): void
    {
        $entry->delete();
    }

    // ------------------------------------------------------------- statistika

    /**
     * Chora-tadbir statistikasi (arxiv modeli): birlik = (band × tuman). «Kiritilган»
     * = kamida bitta jurnal yozuvi bor. Tuman o'z bandlari; viloyat umumiy + tuman kesimi.
     *
     * @return array<string, mixed>
     */
    public function stats(AdvisorScope $scope): array
    {
        $items = DB::connection('advisor')->table('action_plan_items as i')
            ->join('action_plans as p', 'p.id', '=', 'i.plan_id')
            ->whereNull('i.deleted_at')
            ->whereNull('p.deleted_at')
            ->get(['i.id', 'i.scope', 'i.deadline', 'p.district_id as plan_district']);

        $byItem = $this->entriesByItem($items->pluck('id')->all());
        $today = Carbon::today();

        if ($scope->isTuman()) {
            $d = (string) $scope->districtId;
            $agg = $this->newTally();
            foreach ($items as $it) {
                $own = $it->plan_district === $d;
                $sharedAll = $it->plan_district === null && $it->scope === 'all_districts';
                if ($own || $sharedAll) {
                    $this->tally($agg, $byItem[$it->id][$d] ?? [], $it->deadline, $today);
                }
            }

            return ['role' => 'tuman', 'overall' => $this->finishTally($agg)];
        }

        $districts = $this->districts();
        $overall = $this->newTally();
        $per = [];
        foreach ($districts as $id => $name) {
            $per[$id] = ['district' => ['id' => (string) $id, 'name' => $name], 'agg' => $this->newTally()];
        }

        foreach ($items as $it) {
            if ($it->plan_district !== null) {
                $this->tally($overall, $byItem[$it->id][$it->plan_district] ?? [], $it->deadline, $today);
                if (isset($per[$it->plan_district])) {
                    $this->tally($per[$it->plan_district]['agg'], $byItem[$it->id][$it->plan_district] ?? [], $it->deadline, $today);
                }
            } elseif ($it->scope === 'all_districts') {
                foreach ($districts as $id => $name) {
                    $l = $byItem[$it->id][$id] ?? [];
                    $this->tally($overall, $l, $it->deadline, $today);
                    $this->tally($per[$id]['agg'], $l, $it->deadline, $today);
                }
            } else {
                $this->tally($overall, $byItem[$it->id][''] ?? [], $it->deadline, $today);
            }
        }

        $perDistrict = array_map(
            fn ($p) => ['district' => $p['district']] + $this->finishTally($p['agg']),
            array_values($per),
        );
        usort($perDistrict, fn ($a, $b) => $b['reported_pct'] <=> $a['reported_pct']);

        return ['role' => $scope->role, 'overall' => $this->finishTally($overall), 'per_district' => $perDistrict];
    }

    /** @return array{total:int,reported:int,overdue:int,_sum:int} */
    private function newTally(): array
    {
        return ['total' => 0, 'reported' => 0, 'overdue' => 0, '_sum' => 0];
    }

    /** @param  array<int, object>  $list */
    private function tally(array &$agg, array $list, ?string $deadline, Carbon $today): void
    {
        $agg['total']++;
        $reported = count($list) > 0;
        if ($reported) {
            $agg['reported']++;
            $latest = $list[0];
            $agg['_sum'] += (int) ($latest->progress_percent ?? 0);
        } elseif ($deadline !== null && Carbon::parse($deadline)->lt($today)) {
            $agg['overdue']++;
        }
    }

    /** @return array{total:int,reported:int,not_reported:int,overdue:int,avg_progress:int,reported_pct:int} */
    private function finishTally(array $agg): array
    {
        $total = $agg['total'];

        return [
            'total' => $total,
            'reported' => $agg['reported'],
            'not_reported' => max(0, $total - $agg['reported']),
            'overdue' => $agg['overdue'],
            'avg_progress' => $total > 0 ? (int) round($agg['_sum'] / $total) : 0,
            'reported_pct' => $total > 0 ? (int) round($agg['reported'] / $total * 100) : 0,
        ];
    }

    // ----------------------------------------------------------------- yordamchi

    /**
     * item_id -> [district_key('' = null) => [entries yangi->eski]].
     *
     * @param  array<int, string>  $itemIds
     * @return array<string, array<string, array<int, object>>>
     */
    private function entriesByItem(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $rows = DB::connection('advisor')->table('action_plan_entries')
            ->whereIn('item_id', $itemIds)
            ->orderByDesc('occurred_at')->orderByDesc('created_at')
            ->get(['id', 'item_id', 'district_id', 'report', 'progress_percent', 'occurred_at']);

        $map = [];
        foreach ($rows as $r) {
            $map[$r->item_id][$r->district_id ?? ''][] = $r;
        }

        return $map;
    }

    /**
     * Bir tuman jurnal xulosasi (oxirgi yozuv + soni).
     *
     * @param  array<int, object>  $list  yangi->eski
     * @return array{reported: bool, progress_percent: int, report: ?string, last_at: ?string, count: int}
     */
    private function districtAgg(array $list): array
    {
        $latest = $list[0] ?? null;

        return [
            'reported' => count($list) > 0,
            'progress_percent' => $latest !== null && $latest->progress_percent !== null ? (int) $latest->progress_percent : 0,
            'report' => $latest->report ?? null,
            'last_at' => isset($latest->occurred_at) ? Carbon::parse($latest->occurred_at)->toDateString() : null,
            'count' => count($list),
        ];
    }

    /**
     * Tuman kesimi qatori (drill-in) — jurnal xulosasi bilan.
     *
     * @param  array<int, object>  $list
     * @return array<string, mixed>
     */
    private function row(?string $districtId, ?string $name, array $list): array
    {
        $agg = $this->districtAgg($list);

        return [
            'district' => $districtId === null ? null : ['id' => $districtId, 'name' => $name],
            'reported' => $agg['reported'],
            'progress_percent' => $agg['progress_percent'],
            'report' => $agg['report'],
            'last_at' => $agg['last_at'],
            'count' => $agg['count'],
        ];
    }

    private function isOverdue(?string $deadline, Carbon $today, bool $reported): bool
    {
        if ($deadline === null || $reported) {
            return false;
        }

        return Carbon::parse($deadline)->lt($today);
    }

    /** 13 tuman: id => name_cyr. */
    private function districts(): array
    {
        return DB::connection('master')->table('districts')
            ->whereNotNull('soato_code')->orderBy('sort_order')->pluck('name_cyr', 'id')->all();
    }

    private function districtCount(): int
    {
        return (int) DB::connection('master')->table('districts')->whereNotNull('soato_code')->count();
    }
}
