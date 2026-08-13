<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Services;

use App\Domains\Qurilish\Models\ConstructionObject;
use App\Domains\Qurilish\Support\QurilishScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard agregatsiyalari — manbadagi 22 ta СВОД pivotining JONLI o'rnini bosadi.
 *
 * Pivotlar import QILINMAYDI: ular hosila. Excel'da har o'zgarishdan keyin
 * pivotni qayta hisoblash kerak edi (va manbada ular obyekt qatorlari bilan
 * mos kelmay qolgan — masalan `СВОД ЗАКАЗЧИК` bitta obyektni boshqa
 * buyurtmachiga qo'shib hisoblaydi). Bu yerda ular har so'rovda SQL bilan
 * hisoblanadi, ya'ni har doim obyekt qatorlari bilan mos.
 *
 * Har so'rovga `QurilishScope` qo'llanadi — buyurtmachi faqat o'z
 * portfelining jamlanmasini ko'radi.
 */
class DashboardService
{
    public function __construct(private readonly QurilishScope $scope) {}

    /** Kesim nomlari -> (jadval, ustun, nom ustuni). */
    private const DIMENSIONS = [
        'dastur' => ['qurilish.programs', 'program_id', 'name_cyr', 'sort_order'],
        'soha' => ['qurilish.sectors', 'sector_id', 'name_cyr', 'sort_order'],
        'tuman' => ['master.districts', 'district_id', 'name_cyr', 'sort_order'],
        'buyurtmachi' => ['qurilish.organizations', 'customer_org_id', 'name_cyr', 'name_cyr'],
        'pudratchi' => ['qurilish.organizations', 'contractor_org_id', 'name_cyr', 'name_cyr'],
        'boshqarma' => ['qurilish.organizations', 'department_org_id', 'name_cyr', 'name_cyr'],
    ];

    /**
     * Yuqori qator ko'rsatkichlari.
     *
     * DIQQAT — agregat ustunlari `_cnt` qo'shimchasi bilan nomlanadi.
     * `base()` Eloquent Builder qaytaradi, ya'ni natija `ConstructionObject`
     * modeliga aylanadi va modeldagi `$casts` QO'LLANADI. `handover_done`
     * nomli agregatni model boolean deb o'giradi: 125 -> true -> 1.
     * Alohida nom bu to'qnashuvni butunlay yo'q qiladi.
     *
     * @return array<string, mixed>
     */
    public function summary(User $user): array
    {
        $row = $this->base($user)
            ->selectRaw('
                count(*) as objects,
                coalesce(sum(limit_amount), 0) as limit_total,
                coalesce(sum(tender_amount), 0) as tender_total,
                coalesce(sum(contract_amount), 0) as contract_total,
                coalesce(sum(disbursed_amount), 0) as disbursed_total,
                coalesce(sum(financed_amount), 0) as financed_total,
                count(*) filter (where handover_planned) as handover_planned_cnt,
                count(*) filter (where handover_done) as handover_done_cnt,
                count(*) filter (where deadline_date < current_date and not handover_done) as overdue,
                count(*) filter (where tender_amount > limit_amount) as tender_over_limit
            ')->first();

        // Qoralamalar `base()` dan CHETLATILGAN — ularni alohida sanaymiz.
        // (Aks holda bu ko'rsatkich har doim 0 bo'lardi.)
        $drafts = $this->scope->apply(ConstructionObject::query(), $user)
            ->where('lifecycle', 'qoralama')->count();

        $contract = (float) $row->contract_total;
        $limit = (float) $row->limit_total;

        return [
            'objects' => (int) $row->objects,
            'limit_total' => $limit,
            'tender_total' => (float) $row->tender_total,
            'contract_total' => $contract,
            'disbursed_total' => (float) $row->disbursed_total,
            'financed_total' => (float) $row->financed_total,
            // DIQQAT: «tender iqtisodi = limit - tender» KO'RSATILMAYDI.
            // Manbadagi `Лимит суммаси` — obyektning 2026-yilgi limiti, loyihaning
            // to'liq qiymati EMAS. Ko'p yillik loyihalarda tender qiymati yillik
            // limitdan katta bo'lishi normal (611 obyektdan 69 tasi shunday), shuning
            // uchun ayirma «tejamkorlik» sifatida ma'noga ega emas — u manfiy chiqadi.
            // O'rniga nazorat uchun haqiqiy signal: yillik limitdan oshgan shartnomalar.
            'tender_over_limit' => (int) $row->tender_over_limit,
            'disbursed_pct' => $contract > 0 ? round((float) $row->disbursed_total / $contract * 100, 1) : 0.0,
            'financed_pct' => $contract > 0 ? round((float) $row->financed_total / $contract * 100, 1) : 0.0,
            'handover_planned' => (int) $row->handover_planned_cnt,
            'handover_done' => (int) $row->handover_done_cnt,
            'handover_left' => (int) $row->handover_planned_cnt - (int) $row->handover_done_cnt,
            'overdue' => (int) $row->overdue,
            'drafts' => $drafts,
        ];
    }

    /**
     * Kesim bo'yicha jamlanma (СВОД). `dastur|soha|tuman|buyurtmachi|pudratchi|boshqarma`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function svod(User $user, string $dimension): array
    {
        if (! isset(self::DIMENSIONS[$dimension])) {
            abort(404, 'Бундай кесим йўқ.');
        }

        [$table, $column, $nameColumn, $sortColumn] = self::DIMENSIONS[$dimension];

        $rows = $this->base($user)
            ->leftJoin($table.' as d', 'd.id', '=', 'objects.'.$column)
            ->selectRaw("
                objects.{$column} as key,
                max(d.{$nameColumn}) as name,
                min(d.{$sortColumn}::text) as sort_key,
                count(*) as objects,
                coalesce(sum(limit_amount), 0) as limit_total,
                coalesce(sum(contract_amount), 0) as contract_total,
                coalesce(sum(disbursed_amount), 0) as disbursed_total,
                count(*) filter (where handover_done) as handover_done_cnt,
                count(*) filter (where deadline_date < current_date and not handover_done) as overdue
            ")
            ->groupBy('objects.'.$column)
            ->orderByDesc('objects')
            ->get();

        return $rows->map(function ($r): array {
            $contract = (float) $r->contract_total;

            return [
                'key' => $r->key,
                'name' => $r->name ?? 'Аниқланмаган',
                'objects' => (int) $r->objects,
                'limit_total' => (float) $r->limit_total,
                'contract_total' => $contract,
                'disbursed_total' => (float) $r->disbursed_total,
                'disbursed_pct' => $contract > 0 ? round((float) $r->disbursed_total / $contract * 100, 1) : 0.0,
                'handover_done' => (int) $r->handover_done_cnt,
                'overdue' => (int) $r->overdue,
            ];
        })->all();
    }

    /**
     * Loyiha tayyorlik voronkasi — 8 bosqich bo'ylab obyekt oqimi.
     *
     * @return array<int, array<string, mixed>>
     */
    public function funnel(User $user): array
    {
        $ids = $this->base($user)->select('objects.id');

        $rows = DB::connection('qurilish')->table('object_stages')
            ->whereIn('object_id', $ids)
            ->selectRaw('stage_code, status, count(*) as cnt')
            ->groupBy('stage_code', 'status')
            ->get();

        $byStage = [];
        foreach ($rows as $r) {
            $byStage[$r->stage_code][$r->status] = (int) $r->cnt;
        }

        $out = [];
        foreach (ConstructionObject::STAGES as $i => $code) {
            $s = $byStage[$code] ?? [];
            $done = ($s['tasdiqlangan'] ?? 0) + ($s['talab_etilmaydi'] ?? 0);

            $out[] = [
                'stage_code' => $code,
                'order' => $i + 1,
                'tasdiqlangan' => $s['tasdiqlangan'] ?? 0,
                'talab_etilmaydi' => $s['talab_etilmaydi'] ?? 0,
                'qoralama' => $s['qoralama'] ?? 0,
                // Voronkada «prokuraturada» bitta ustun: yuborilgan va ko'rilayotgan
                // bosqich rahbariyat uchun bir xil ma'noda — javob kutilmoqda.
                'tasdiqlash_kutilmoqda' => ($s['tasdiqlash_kutilmoqda'] ?? 0) + ($s['korib_chiqilmoqda'] ?? 0),
                'rad_etilgan' => $s['rad_etilgan'] ?? 0,
                // «Boshlanmagan» = navbat kelmagan + ochilgan-u to'ldirilmagan.
                'kutilmoqda' => ($s['kutilmoqda'] ?? 0) + ($s['ochilgan'] ?? 0),
                'done_total' => $done,
            ];
        }

        return $out;
    }

    /**
     * Oylik ijro grafigi jamlanmasi (reja vs amalda).
     *
     * @return array<int, array<string, mixed>>
     */
    public function monthly(User $user, int $year): array
    {
        $ids = $this->base($user)->select('objects.id');

        $rows = DB::connection('qurilish')->table('object_monthly_plan')
            ->whereIn('object_id', $ids)
            ->where('year', $year)
            ->selectRaw('month, sum(planned_amount) as planned, sum(actual_amount) as actual')
            ->groupBy('month')
            ->pluck('planned', 'month');

        $actual = DB::connection('qurilish')->table('object_monthly_plan')
            ->whereIn('object_id', $ids)
            ->where('year', $year)
            ->selectRaw('month, sum(actual_amount) as actual')
            ->groupBy('month')
            ->pluck('actual', 'month');

        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $out[] = [
                'month' => $m,
                'planned_amount' => (float) ($rows[$m] ?? 0),
                'actual_amount' => (float) ($actual[$m] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Tuman xoropleti — xaritada rang uchun.
     *
     * @return array<int, array<string, mixed>>
     */
    public function map(User $user): array
    {
        return $this->svod($user, 'tuman');
    }

    /**
     * Shartnoma bajarilishi taqsimoti — nechta obyekt qaysi % oralig'ida.
     *
     * @return array<int, array{bucket: string, objects: int}>
     */
    public function executionBuckets(User $user): array
    {
        $rows = $this->base($user)
            ->selectRaw("
                case
                    when contract_amount <= 0 then 'shartnomasiz'
                    when disbursed_amount / contract_amount >= 1 then '100'
                    when disbursed_amount / contract_amount >= 0.75 then '75-99'
                    when disbursed_amount / contract_amount >= 0.5 then '50-74'
                    when disbursed_amount / contract_amount >= 0.25 then '25-49'
                    when disbursed_amount > 0 then '1-24'
                    else '0'
                end as bucket,
                count(*) as cnt
            ")
            ->groupBy('bucket')
            ->pluck('cnt', 'bucket');

        $order = ['0', '1-24', '25-49', '50-74', '75-99', '100', 'shartnomasiz'];

        return array_map(
            fn (string $b): array => ['bucket' => $b, 'objects' => (int) ($rows[$b] ?? 0)],
            $order,
        );
    }

    /**
     * Barcha agregatsiyaning yagona boshlanish nuqtasi: scope + qoralama chetlatilgan.
     * Qoralama obyekt hali dasturda yo'q — u jamlanmani buzmasligi kerak.
     *
     * @return Builder<ConstructionObject>
     */
    private function base(User $user): Builder
    {
        return $this->scope->apply(ConstructionObject::query(), $user)
            ->where('lifecycle', '!=', 'qoralama');
    }
}
