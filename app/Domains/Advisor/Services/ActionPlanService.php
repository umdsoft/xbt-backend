<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Services;

use App\Domains\Advisor\Models\ActionPlan;
use App\Domains\Advisor\Models\ActionPlanItem;
use App\Domains\Advisor\Models\ActionPlanProgress;
use App\Domains\Advisor\Support\AdvisorScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CHORA-TADBIRLAR moduli — yillik reja (action_plans) + bandlar (items) +
 * bajarilishi band×tuman kesimida (progress). KPI matritsa naqshi.
 *
 * Qamrov (AdvisorScope): viloyat/bo'linma barcha tuman kesimini (summary) ko'radi;
 * tuman FAQAT o'z tumani bajarilishini ko'radi/kiritadi (IDOR himoyasi). Viloyat
 * istalgan tuman bajarilishini tahrirlaydi; tuman — faqat o'zinikini.
 */
class ActionPlanService
{
    /** Bajarilishi holatlari. */
    public const STATUSES = ['not_started', 'in_progress', 'completed'];

    /** Berilgan yil uchun faol reja (yiliga bitta) — yoki null. */
    public function activePlan(int $year): ?ActionPlan
    {
        return ActionPlan::query()
            ->where('year', $year)
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Rejalar ro'yxati (jadval) — band soni + hujjat + EGA (tuman/umumiy).
     * Qamrov: tuman FAQAT o'z rejasi + umumiy (viloyat) rejalarni ko'radi;
     * viloyat/bo'linма barchani.
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
     *   - tuman: har band `my_progress` (o'z tumani; yo'q bo'lsa not_started/0), summary=null.
     *   - viloyat/bo'linma: har band `summary` (tuman kesimi agregat), my_progress=null.
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
                'id', 'section_title', 'item_number', 'title', 'mechanism',
                'deadline_text', 'deadline', 'responsible_text', 'scope', 'sort_order',
            ]);

        $today = Carbon::today();
        $districtCount = $this->districtCount();
        // Reja EGASI: null = umumiy (13 tuman); <uuid> = tuman o'z rejasi (1 tuman).
        $ownerDistrict = $plan->district_id;

        // Bandlar bo'yicha bajarilishi yozuvlari (bir marta yuklab, PHP'da taqsimlanadi).
        $progressByItem = $this->progressByItem($items->pluck('id')->all());

        $sections = [];
        $sectionIndex = [];

        foreach ($items as $item) {
            $rows = $progressByItem[$item->id] ?? [];

            $present = [
                'id' => $item->id,
                'item_number' => $item->item_number,
                'title' => $item->title,
                'mechanism' => $item->mechanism,
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
                $mine = $rows[$scope->districtId] ?? null;
                $present['my_progress'] = $this->presentProgress($mine);
                $present['overdue'] = $this->isOverdue($item->deadline, $today, $present['my_progress']['status'] === 'completed');
            } elseif ($ownerDistrict !== null) {
                // Tuman O'Z rejasi — viloyat/bo'linма uchun BITTA tuman kesimi (monitoring).
                $r = $rows[$ownerDistrict] ?? null;
                $completed = ($r->status ?? null) === 'completed' ? 1 : 0;
                $inProgress = ($r->status ?? null) === 'in_progress' ? 1 : 0;
                $present['summary'] = [
                    'total' => 1,
                    'completed' => $completed,
                    'in_progress' => $inProgress,
                    'not_started' => 1 - $completed - $inProgress,
                    'avg_progress' => (int) ($r->progress_percent ?? 0),
                ];
                $present['overdue'] = $this->isOverdue($item->deadline, $today, $completed === 1);
            } else {
                $total = $item->scope === 'viloyat' ? 1 : $districtCount;
                $summary = $this->summarize($rows, $item->scope, $total);
                $present['summary'] = $summary;
                $present['overdue'] = $this->isOverdue($item->deadline, $today, $summary['completed'] >= $summary['total'] && $summary['total'] > 0);
            }

            // Bo'limga guruhlash (tartibni saqlab).
            if (! isset($sectionIndex[$item->section_title])) {
                $sectionIndex[$item->section_title] = count($sections);
                $sections[] = ['title' => $item->section_title, 'items' => []];
            }
            $sections[$sectionIndex[$item->section_title]]['items'][] = $present;
        }

        return [
            'plan' => $this->presentPlan($plan),
            'sections' => $sections,
        ];
    }

    /**
     * Reja shakli (jadval/tafsilot uchun) — hujjat + EGA (district) ma'lumoti bilan.
     * `district` = null (umumiy/viloyat) yoki {id,name} (tuman o'z rejasi).
     *
     * @param  array<string,string>|null  $districts  id=>name xaritasi (ro'yxat uchun; null — bitta so'rov)
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

    // ------------------------------------------------------------- statistika

    /**
     * CHORA-TADBIR STATISTIKASI — har band bir "topshiriq" hisoblanadi:
     *   - all_districts band → 13 topshiriq (har tuman uchun bittadan);
     *   - viloyat band       → 1 topshiriq (viloyat darajasi).
     * Barcha rejalardagi bandlar bo'yicha yig'ma. Rolга qarab:
     *   - tuman: FAQAT o'z tumани bandlari yig'masi;
     *   - viloyat/bo'linма: umumiy yig'ma + tuman kesimi (per_district leaderboard).
     *
     * @return array<string, mixed>
     */
    public function stats(AdvisorScope $scope): array
    {
        // Bandlar + reja EGASи (plan_district): null=umumiy, <uuid>=tuman o'z rejasi.
        $items = DB::connection('advisor')->table('action_plan_items as i')
            ->join('action_plans as p', 'p.id', '=', 'i.plan_id')
            ->whereNull('i.deleted_at')
            ->whereNull('p.deleted_at')
            ->get(['i.id', 'i.scope', 'i.deadline', 'p.district_id as plan_district']);

        $progress = $this->progressByItem($items->pluck('id')->all());
        $today = Carbon::today();

        // Tuman: o'z rejasi bandlari + umumiy (viloyat) all_districts bandlari. Har biri 1 topshiriq.
        if ($scope->isTuman()) {
            $d = (string) $scope->districtId;
            $agg = $this->newTally();
            foreach ($items as $it) {
                $own = $it->plan_district === $d;
                $sharedAll = $it->plan_district === null && $it->scope === 'all_districts';
                if ($own || $sharedAll) {
                    $this->tally($agg, $progress[$it->id][$d] ?? null, $it->deadline, $today);
                }
            }

            return ['role' => 'tuman', 'overall' => $this->finishTally($agg)];
        }

        // Viloyat/bo'linма: umumiy + tuman kesimi.
        $districts = $this->districts();
        $overall = $this->newTally();
        $per = [];
        foreach ($districts as $id => $name) {
            $per[$id] = ['district' => ['id' => (string) $id, 'name' => $name], 'agg' => $this->newTally()];
        }

        $countAll = 0;
        $countViloyat = 0;
        $countOwn = 0;
        foreach ($items as $it) {
            if ($it->plan_district !== null) {
                // Tuman o'z rejasi bandi — 1 topshiriq (o'sha tuman).
                $countOwn++;
                $row = $progress[$it->id][$it->plan_district] ?? null;
                $this->tally($overall, $row, $it->deadline, $today);
                if (isset($per[$it->plan_district])) {
                    $this->tally($per[$it->plan_district]['agg'], $row, $it->deadline, $today);
                }
            } elseif ($it->scope === 'all_districts') {
                // Umumiy reja bandi — 13 topshiriq (har tuman).
                $countAll++;
                foreach ($districts as $id => $name) {
                    $row = $progress[$it->id][$id] ?? null;
                    $this->tally($overall, $row, $it->deadline, $today);
                    $this->tally($per[$id]['agg'], $row, $it->deadline, $today);
                }
            } else {
                // Viloyat darajасидаги band — 1 topshiriq (umumiy).
                $countViloyat++;
                $this->tally($overall, $progress[$it->id][''] ?? null, $it->deadline, $today);
            }
        }

        $perDistrict = array_map(
            fn ($p) => ['district' => $p['district']] + $this->finishTally($p['agg']),
            array_values($per),
        );
        usort($perDistrict, fn ($a, $b) => $b['completion'] <=> $a['completion']);

        return [
            'role' => $scope->role,
            'overall' => $this->finishTally($overall),
            'per_district' => $perDistrict,
            'bands' => ['all_districts' => $countAll, 'viloyat' => $countViloyat, 'district_own' => $countOwn],
        ];
    }

    /** @return array{total:int,completed:int,in_progress:int,not_started:int,overdue:int,_sum:int} */
    private function newTally(): array
    {
        return ['total' => 0, 'completed' => 0, 'in_progress' => 0, 'not_started' => 0, 'overdue' => 0, '_sum' => 0];
    }

    /** Bitta topshiriq (band×tuman) yozuvини yig'maга qo'shadi. */
    private function tally(array &$agg, ?object $row, ?string $deadline, Carbon $today): void
    {
        $agg['total']++;
        $status = $row->status ?? 'not_started';
        if ($status === 'completed') {
            $agg['completed']++;
        } elseif ($status === 'in_progress') {
            $agg['in_progress']++;
        } else {
            $agg['not_started']++;
        }
        if ($deadline !== null && $status !== 'completed' && Carbon::parse($deadline)->lt($today)) {
            $agg['overdue']++;
        }
        $agg['_sum'] += (int) ($row->progress_percent ?? 0);
    }

    /**
     * Yig'mани yakuniy shaklga keltiradi (avg_progress + completion% qo'shib).
     *
     * @return array{total:int,completed:int,in_progress:int,not_started:int,overdue:int,avg_progress:int,completion:int}
     */
    private function finishTally(array $agg): array
    {
        $total = $agg['total'];

        return [
            'total' => $total,
            'completed' => $agg['completed'],
            'in_progress' => $agg['in_progress'],
            'not_started' => $agg['not_started'],
            'overdue' => $agg['overdue'],
            'avg_progress' => $total > 0 ? (int) round($agg['_sum'] / $total) : 0,
            'completion' => $total > 0 ? (int) round($agg['completed'] / $total * 100) : 0,
        ];
    }

    // ------------------------------------------------------------- CRUD (viloyat)

    /**
     * Yangi reja yaratadi (tasdiqlovchi hujjat kontrollerда saqlanadi).
     *
     * @param  array<string, mixed>  $data  title, year, status?
     */
    public function createPlan(array $data, string $userId): ActionPlan
    {
        return ActionPlan::create([
            'year' => (int) $data['year'],
            'title' => $data['title'],
            'status' => $data['status'] ?? 'active',
            // null = umumiy (viloyat) reja; <uuid> = tuman o'z rejasi.
            'district_id' => $data['district_id'] ?? null,
            'created_by' => $userId,
        ]);
    }

    /** Tasdiqlovchi hujjatni maxfiy diskка saqlaydi va rejaga biriktiradi. */
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
     * Rejaga qo'lda band qo'shadi (viloyat).
     *
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
            'mechanism' => $data['mechanism'] ?? null,
            'deadline_text' => $data['deadline_text'] ?? null,
            'deadline' => $data['deadline'] ?? null,
            'responsible_text' => $data['responsible_text'] ?? null,
            'scope' => $data['scope'] ?? 'all_districts',
            'sort_order' => $maxSort + 10,
        ]);

        return $item->id;
    }

    /**
     * Bitta band bo'yicha tuman kesimi:
     *   - viloyat/bo'linma: all_districts band -> 13 tuman qatori; viloyat band -> 1 qator (district null).
     *   - tuman: FAQAT o'z tumani qatori.
     *
     * @return array<string, mixed>|null null = band topilmadi
     */
    public function itemRows(ActionPlanItem $item, AdvisorScope $scope): ?array
    {
        $rows = ($this->progressByItem([$item->id])[$item->id]) ?? [];

        $itemPresent = [
            'id' => $item->id,
            'item_number' => $item->item_number,
            'title' => $item->title,
            'mechanism' => $item->mechanism,
            'deadline_text' => $item->deadline_text,
            'deadline' => $item->deadline?->toDateString(),
            'responsible_text' => $item->responsible_text,
            'scope' => $item->scope,
            'sort_order' => $item->sort_order,
        ];

        $out = [];

        // Tuman O'Z rejasi bandi — FAQAT o'sha tuman qatori (viloyat monitoring qiladi).
        $ownerDistrict = DB::connection('advisor')->table('action_plans')
            ->where('id', $item->plan_id)->value('district_id');
        if ($ownerDistrict !== null) {
            $name = $this->districts()[$ownerDistrict] ?? null;

            return ['item' => $itemPresent, 'rows' => [$this->row($ownerDistrict, $name, $rows[$ownerDistrict] ?? null)]];
        }

        if ($item->scope === 'viloyat') {
            // Viloyat darajasidagi band — bitta qator (district null). Tuman ko'rmaydi.
            if ($scope->isTuman()) {
                return ['item' => $itemPresent, 'rows' => []];
            }
            $out[] = $this->row(null, 'Вилоят даражаси', $rows[''] ?? null);

            return ['item' => $itemPresent, 'rows' => $out];
        }

        // all_districts band.
        $districts = $this->districts();

        if ($scope->isTuman()) {
            $name = $districts[$scope->districtId] ?? null;
            if ($name !== null) {
                $out[] = $this->row($scope->districtId, $name, $rows[$scope->districtId] ?? null);
            }

            return ['item' => $itemPresent, 'rows' => $out];
        }

        foreach ($districts as $id => $name) {
            $out[] = $this->row($id, $name, $rows[$id] ?? null);
        }

        return ['item' => $itemPresent, 'rows' => $out];
    }

    /**
     * Band bajarilishini kiritish/yangilash (idempotent upsert). Tuman districtId'si
     * qamrovдан olinadi (boshqa tuman berса ham majburan o'z tumani). Viloyat istalgan
     * tuman (yoki viloyat-band uchun null).
     */
    public function upsertProgress(
        ActionPlanItem $item,
        ?string $districtId,
        string $status,
        ?string $report,
        ?int $progress,
        string $userId,
        AdvisorScope $scope,
    ): void {
        // Tuman FAQAT o'z tumani uchun (IDOR himoyasi).
        if ($scope->isTuman()) {
            $districtId = $scope->districtId;
        }

        // viloyat band -> district null; all_districts band -> district talab qilinadi
        // (viloyat kiritganda). Tuman uchun yuqorida majburlandi.
        if ($item->scope === 'viloyat') {
            $districtId = null;
        }

        $progress ??= match ($status) {
            'completed' => 100,
            'not_started' => 0,
            default => 0,
        };

        ActionPlanProgress::updateOrCreate(
            ['item_id' => $item->id, 'district_id' => $districtId],
            [
                'status' => $status,
                'report' => $report,
                'progress_percent' => max(0, min(100, $progress)),
                'updated_by' => $userId,
            ],
        );
    }

    // ------------------------------------------------------------- yordamchi

    /**
     * item_id -> [district_id|'' => progress row]. '' kaliti = district null (viloyat).
     *
     * @param  array<int, string>  $itemIds
     * @return array<string, array<string, object>>
     */
    private function progressByItem(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $rows = DB::connection('advisor')->table('action_plan_progress')
            ->whereIn('item_id', $itemIds)
            ->get(['item_id', 'district_id', 'status', 'report', 'progress_percent', 'updated_at']);

        $map = [];
        foreach ($rows as $r) {
            $map[$r->item_id][$r->district_id ?? ''] = $r;
        }

        return $map;
    }

    /**
     * Tuman kesimi agregat. Yozuvsiz tumanlar not_started/0 hisoblanadi.
     *
     * @param  array<string, object>  $rows  district_id|'' => row
     * @return array{total: int, completed: int, in_progress: int, not_started: int, avg_progress: int}
     */
    private function summarize(array $rows, string $itemScope, int $total): array
    {
        $completed = 0;
        $inProgress = 0;
        $sum = 0;

        if ($itemScope === 'viloyat') {
            $r = $rows[''] ?? null;
            if ($r !== null) {
                $completed = $r->status === 'completed' ? 1 : 0;
                $inProgress = $r->status === 'in_progress' ? 1 : 0;
                $sum = (int) $r->progress_percent;
            }
        } else {
            foreach ($rows as $key => $r) {
                if ($key === '') {
                    continue; // viloyat qatori all_districts agregatiga kirmaydi
                }
                if ($r->status === 'completed') {
                    $completed++;
                } elseif ($r->status === 'in_progress') {
                    $inProgress++;
                }
                $sum += (int) $r->progress_percent;
            }
        }

        $notStarted = max(0, $total - $completed - $inProgress);
        $avg = $total > 0 ? (int) round($sum / $total) : 0;

        return [
            'total' => $total,
            'completed' => $completed,
            'in_progress' => $inProgress,
            'not_started' => $notStarted,
            'avg_progress' => $avg,
        ];
    }

    /** Bitta bajarilishi yozuvини taqdim etadi (yo'q bo'lsa default). */
    private function presentProgress(?object $row): array
    {
        return [
            'status' => $row->status ?? 'not_started',
            'report' => $row->report ?? null,
            'progress_percent' => $row === null ? 0 : (int) $row->progress_percent,
        ];
    }

    /**
     * Tuman kesimi qatori (district + bajarilishi).
     *
     * @return array<string, mixed>
     */
    private function row(?string $districtId, ?string $name, ?object $r): array
    {
        return [
            'district' => $districtId === null ? null : ['id' => $districtId, 'name' => $name],
            'status' => $r->status ?? 'not_started',
            'report' => $r->report ?? null,
            'progress_percent' => $r === null ? 0 : (int) $r->progress_percent,
            'updated_at' => ($r->updated_at ?? null) === null ? null : Carbon::parse($r->updated_at)->toIso8601String(),
        ];
    }

    private function isOverdue(?string $deadline, Carbon $today, bool $done): bool
    {
        if ($deadline === null || $done) {
            return false;
        }

        return Carbon::parse($deadline)->lt($today);
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
        return (int) DB::connection('master')->table('districts')
            ->whereNotNull('soato_code')
            ->count();
    }
}
