<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Services;

use App\Domains\Qurilish\Models\ConstructionObject;
use App\Domains\Qurilish\Support\QurilishScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Rahbariyat paneli (hokimlik/prokuratura) agregatsiyalari.
 *
 * `DashboardService` dan alohida: u umumiy СВОД kesimlarini beradi, bu esa
 * bitta ekranga mo'ljallangan TO'LIQ jamlanmani — tender iqtisodi, tender
 * holati, topshirish rejasi, loyiha tayyorlik pog'onalari va shartnoma ijrosi.
 * Bitta so'rovda hammasi qaytadi: panel 8 ta parallel so'rov yubormasin.
 *
 * DIQQAT — agregat ustunlari `_cnt`/`_sum` qo'shimchasi bilan nomlanadi.
 * Eloquent Builder natijasi modelga aylanadi va `$casts` qo'llanadi:
 * `handover_done` nomli agregat boolean'ga o'girilib, 125 -> true -> 1
 * bo'lib qolardi.
 */
class ExecutiveDashboardService
{
    public function __construct(private readonly QurilishScope $scope) {}

    /** @return array<string, mixed> */
    public function build(User $user, int $year): array
    {
        return [
            'overview' => $this->overview($user),
            'savings' => $this->savings($user),
            'pending_tender' => $this->pendingTender($user),
            'tender_status' => $this->tenderStatus($user),
            'handover' => $this->handover($user),
            'programs' => $this->byProgram($user),
            'sectors' => $this->bySector($user),
            'districts' => $this->byDistrict($user),
            'readiness' => $this->readiness($user),
            'contracts' => $this->contracts($user),
            'year' => $year,
        ];
    }

    /** Yuqori qator: jami / qiymat / yangidan boshlanuvchi / yildan-yilga o'tuvchi. */
    private function overview(User $user): array
    {
        $r = $this->base($user)->selectRaw('
            count(*) as objects_cnt,
            coalesce(sum(limit_amount), 0) as limit_sum,
            count(*) filter (where not is_carryover) as fresh_cnt,
            coalesce(sum(limit_amount) filter (where not is_carryover), 0) as fresh_sum,
            count(*) filter (where is_carryover) as carry_cnt,
            coalesce(sum(limit_amount) filter (where is_carryover), 0) as carry_sum
        ')->first();

        return [
            'objects' => (int) $r->objects_cnt,
            'limit_total' => (float) $r->limit_sum,
            'fresh_objects' => (int) $r->fresh_cnt,
            'fresh_amount' => (float) $r->fresh_sum,
            'carryover_objects' => (int) $r->carry_cnt,
            'carryover_amount' => (float) $r->carry_sum,
        ];
    }

    /**
     * Tender iqtisodi — dastur kesimida.
     *
     * FAQAT tenderi limitdan ARZON tushgan obyektlar hisoblanadi. Aks holda
     * ko'p yillik loyihalar (shartnomasi yillik limitdan katta) jamlanmani
     * manfiyga tortib, «tejamkorlik» ko'rsatkichini ma'nosiz qilardi.
     */
    private function savings(User $user): array
    {
        $rows = $this->base($user)
            ->leftJoin('qurilish.programs as p', 'p.id', '=', 'objects.program_id')
            ->where('tender_amount', '>', 0)
            ->whereColumn('tender_amount', '<', 'limit_amount')
            ->selectRaw('
                objects.program_id as key,
                max(p.name_lat) as name,
                min(p.sort_order) as sort_order,
                count(*) as objects_cnt,
                coalesce(sum(limit_amount - tender_amount), 0) as saved_sum
            ')
            ->groupBy('objects.program_id')
            ->orderBy('sort_order')
            ->get();

        return [
            'total_amount' => round((float) $rows->sum('saved_sum'), 3),
            'total_objects' => (int) $rows->sum('objects_cnt'),
            'rows' => $rows->map(fn ($r) => [
                'key' => $r->key,
                'name' => $r->name ?? 'Aniqlanmagan',
                'objects' => (int) $r->objects_cnt,
                'amount' => round((float) $r->saved_sum, 3),
            ])->all(),
        ];
    }

    /**
     * Tenderi hali o'tmagan loyihalar — «iqtisod qilish imkoniyati» qolgan portfel.
     * Dastur kesimida: tender bosqichi yakunlanmagan obyektlar.
     */
    private function pendingTender(User $user): array
    {
        $rows = $this->base($user)
            ->leftJoin('qurilish.programs as p', 'p.id', '=', 'objects.program_id')
            ->whereIn('objects.id', $this->stageIds($user, 'tender', ['boshlanmagan', 'jarayonda']))
            ->selectRaw('
                objects.program_id as key,
                max(p.name_lat) as name,
                min(p.sort_order) as sort_order,
                count(*) as objects_cnt,
                coalesce(sum(limit_amount), 0) as limit_sum
            ')
            ->groupBy('objects.program_id')
            ->orderByDesc('limit_sum')
            ->get();

        return [
            'total_amount' => round((float) $rows->sum('limit_sum'), 3),
            'total_objects' => (int) $rows->sum('objects_cnt'),
            'rows' => $rows->map(fn ($r) => [
                'key' => $r->key,
                'name' => $r->name ?? 'Aniqlanmagan',
                'objects' => (int) $r->objects_cnt,
                'amount' => round((float) $r->limit_sum, 3),
            ])->all(),
        ];
    }

    /** Tender holati: pudratchi aniqlangan / jarayonda / e'lon berilmagan. */
    private function tenderStatus(User $user): array
    {
        $map = [
            'done' => ['yakunlangan'],
            'process' => ['jarayonda'],
            'not_announced' => ['boshlanmagan', 'talab_etilmaydi'],
        ];

        $out = [];
        $total = 0;
        foreach ($map as $key => $statuses) {
            $r = $this->base($user)
                ->whereIn('objects.id', $this->stageIds($user, 'tender', $statuses))
                ->selectRaw('count(*) as c, coalesce(sum(limit_amount), 0) as s')
                ->first();

            $out[$key] = ['objects' => (int) $r->c, 'amount' => round((float) $r->s, 3)];
            $total += (int) $r->c;
        }

        $out['total'] = $total;

        return $out;
    }

    /** Yil oxirigacha topshirish rejasi. */
    private function handover(User $user): array
    {
        $r = $this->base($user)->selectRaw('
            count(*) filter (where handover_planned) as plan_cnt,
            coalesce(sum(limit_amount) filter (where handover_planned), 0) as plan_sum,
            count(*) filter (where handover_done) as done_cnt,
            coalesce(sum(limit_amount) filter (where handover_done), 0) as done_sum,
            count(*) filter (where handover_planned and not handover_done) as left_cnt,
            coalesce(sum(limit_amount) filter (where handover_planned and not handover_done), 0) as left_sum,
            count(*) filter (where is_carryover) as carry_cnt,
            coalesce(sum(limit_amount) filter (where is_carryover), 0) as carry_sum
        ')->first();

        $plan = (int) $r->plan_cnt;
        $done = (int) $r->done_cnt;

        return [
            'plan_objects' => $plan,
            'plan_amount' => (float) $r->plan_sum,
            'done_objects' => $done,
            'done_amount' => (float) $r->done_sum,
            'left_objects' => (int) $r->left_cnt,
            'left_amount' => (float) $r->left_sum,
            'carryover_objects' => (int) $r->carry_cnt,
            'carryover_amount' => (float) $r->carry_sum,
            'done_pct' => $plan > 0 ? round($done / $plan * 100, 1) : 0.0,
        ];
    }

    /**
     * Loyiha tayyorlik pog'onalari — voronkaning «hujjat» qismi.
     * Har pog'ona: nechta obyekt va qancha mablag'.
     */
    private function readiness(User $user): array
    {
        $steps = [
            ['key' => 'required', 'stage' => null, 'statuses' => []],
            ['key' => 'design_process', 'stage' => 'design_estimate', 'statuses' => ['jarayonda']],
            ['key' => 'design_done', 'stage' => 'design_estimate', 'statuses' => ['yakunlangan']],
            ['key' => 'expertise_done', 'stage' => 'urban_planning', 'statuses' => ['yakunlangan']],
            ['key' => 'expertise_process', 'stage' => 'urban_planning', 'statuses' => ['jarayonda']],
        ];

        $out = [];
        foreach ($steps as $s) {
            $q = $this->base($user);
            if ($s['stage'] !== null) {
                $q->whereIn('objects.id', $this->stageIds($user, $s['stage'], $s['statuses']));
            }
            $r = $q->selectRaw('count(*) as c, coalesce(sum(limit_amount), 0) as s')->first();
            $out[] = [
                'key' => $s['key'],
                'objects' => (int) $r->c,
                'amount' => round((float) $r->s, 3),
            ];
        }

        return $out;
    }

    /** Shartnomalar ijrosi. */
    private function contracts(User $user): array
    {
        $r = $this->base($user)->selectRaw('
            coalesce(sum(contract_amount), 0) as contract_sum,
            coalesce(sum(disbursed_amount), 0) as disbursed_sum,
            coalesce(sum(financed_amount), 0) as financed_sum,
            count(*) filter (where contract_amount > 0) as with_contract_cnt
        ')->first();

        $contract = (float) $r->contract_sum;

        return [
            'contract_total' => $contract,
            'disbursed_total' => (float) $r->disbursed_sum,
            'financed_total' => (float) $r->financed_sum,
            'objects_with_contract' => (int) $r->with_contract_cnt,
            'done_pct' => $contract > 0 ? round((float) $r->disbursed_sum / $contract * 100, 1) : 0.0,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function byProgram(User $user): array
    {
        return $this->dimension($user, 'qurilish.programs', 'program_id', 'sort_order');
    }

    /** @return array<int, array<string, mixed>> */
    private function bySector(User $user): array
    {
        return $this->dimension($user, 'qurilish.sectors', 'sector_id', 'sort_order', withCode: true);
    }

    /** @return array<int, array<string, mixed>> */
    private function byDistrict(User $user): array
    {
        return $this->dimension($user, 'master.districts', 'district_id', 'sort_order');
    }

    /**
     * Kesim: soni + limit + o'zlashtirish.
     *
     * @return array<int, array<string, mixed>>
     */
    private function dimension(
        User $user,
        string $table,
        string $column,
        string $sortColumn,
        bool $withCode = false,
    ): array {
        $codeSelect = $withCode ? ', max(d.code) as code' : '';

        $rows = $this->base($user)
            ->leftJoin($table.' as d', 'd.id', '=', 'objects.'.$column)
            ->selectRaw("
                objects.{$column} as key,
                max(d.name_lat) as name,
                min(d.{$sortColumn}) as sort_order,
                count(*) as objects_cnt,
                coalesce(sum(limit_amount), 0) as limit_sum,
                coalesce(sum(contract_amount), 0) as contract_sum,
                coalesce(sum(disbursed_amount), 0) as disbursed_sum
                {$codeSelect}
            ")
            ->groupBy('objects.'.$column)
            ->orderByDesc('limit_sum')
            ->get();

        return $rows->map(function ($r) use ($withCode): array {
            $contract = (float) $r->contract_sum;
            $row = [
                'key' => $r->key,
                'name' => $r->name ?? 'Aniqlanmagan',
                'objects' => (int) $r->objects_cnt,
                'amount' => (float) $r->limit_sum,
                'disbursed_pct' => $contract > 0 ? round((float) $r->disbursed_sum / $contract * 100, 1) : 0.0,
            ];

            if ($withCode) {
                $row['code'] = $r->code;
            }

            return $row;
        })->all();
    }

    /**
     * Berilgan bosqichda berilgan holatdagi obyekt id lari (sub-query).
     *
     * @param  array<int, string>  $statuses
     */
    private function stageIds(User $user, string $stageCode, array $statuses): \Illuminate\Database\Query\Builder
    {
        return DB::connection('qurilish')->table('object_stages')
            ->select('object_id')
            ->where('stage_code', $stageCode)
            ->whereIn('status', $statuses);
    }

    /**
     * Barcha agregatsiyaning yagona boshlanish nuqtasi: scope + qoralama chetlatilgan.
     *
     * @return Builder<ConstructionObject>
     */
    private function base(User $user): Builder
    {
        return $this->scope->apply(ConstructionObject::query(), $user)
            ->where('lifecycle', '!=', 'qoralama');
    }
}
