<?php

declare(strict_types=1);

namespace App\Domains\Murojaat\Services;

use App\Domains\Murojaat\Support\MurojaatScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * MurojAAT tahlil — barcha agregatsiya SERVERda (SQL) hisoblanadi. Faqat is_active
 * sessiya(lar) + scope (tuman). Sayyor qabullar asosiy statistikadan CHIQARILADI.
 * Biznes-qoidalar (kechikkan/PF-51/PVQ-XQ) HTMLdagidek.
 */
class AnalyticsService
{
    private const MONTHS = ['', 'Yanvar', 'Fevral', 'Mart', 'Aprel', 'May', 'Iyun',
        'Iyul', 'Avgust', 'Sentyabr', 'Oktyabr', 'Noyabr', 'Dekabr'];

    /** @return array<int, string> */
    public function activeSessionIds(MurojaatScope $scope): array
    {
        return DB::connection('murojaat')->table('import_sessions')
            ->where('is_active', true)
            ->when(! $scope->seesAllDistricts(), fn ($q) => $q->where('district_id', $scope->districtId))
            ->pluck('id')->all();
    }

    private function base(MurojaatScope $scope, bool $includeSayyor = false): Builder
    {
        $q = DB::connection('murojaat')->table('appeals')
            ->whereIn('session_id', $this->activeSessionIds($scope));
        if (! $includeSayyor) {
            $q->where('is_sayyor', false);
        }

        return $q;
    }

    // ------------------------------------------------------------- dashboard

    /** @return array<string, mixed> */
    public function dashboard(MurojaatScope $scope): array
    {
        $ids = $this->activeSessionIds($scope);
        if ($ids === []) {
            return ['empty' => true];
        }

        $agg = (clone $this->base($scope))->selectRaw(
            "count(*) total,
             count(*) filter (where is_kechikkan) kechikkan,
             count(*) filter (where natija_holat_norm='jarayon') jarayon,
             count(*) filter (where natija_holat_norm='hal') hal,
             count(*) filter (where natija_holat_norm='rad') rad,
             count(*) filter (where takroriylik='Takroriy') takroriy,
             count(*) filter (where jamoaviy='Ha') jamoaviy,
             count(*) filter (where jamoaviy='Ha' and natija_holat_norm<>'hal') jamoaviy_ochiq"
        )->first();

        $total = (int) $agg->total;
        $sayyor = (int) (clone $this->base($scope, true))->where('is_sayyor', true)->count();
        $sayyorOchiq = (int) (clone $this->base($scope, true))->where('is_sayyor', true)
            ->where('natija_holat_norm', '<>', 'hal')->count();

        // Mahalla klaster (RZ-04): mahalla+masala guruhi ≥5.
        $klaster = DB::connection('murojaat')->table(function ($q) use ($scope) {
            $q->from('appeals')->whereIn('session_id', $this->activeSessionIds($scope))
                ->where('is_sayyor', false)
                ->whereNotNull('mahalla')->where('mahalla', '<>', '')
                ->selectRaw('mahalla, masala, count(*) c')
                ->groupBy('mahalla', 'masala')->havingRaw('count(*) >= 5');
        }, 'k')->count();

        return [
            'empty' => false,
            'kpi' => [
                'total' => $total,
                'kechikkan' => (int) $agg->kechikkan,
                'jarayon' => (int) $agg->jarayon,
                'hal' => (int) $agg->hal,
                'takroriy' => (int) $agg->takroriy,
                'jamoaviy' => (int) $agg->jamoaviy,
                'hal_pct' => $total ? (int) round($agg->hal / $total * 100) : 0,
                'kechikkan_pct' => $total ? (int) round($agg->kechikkan / $total * 100) : 0,
            ],
            'status' => $this->distribution($scope, 'natija_holat_norm'),
            'manba' => $this->distribution($scope, 'qaerdan', 10),
            'yonalish' => $this->distributionExpr($scope, "coalesce(nullif(yonalish,''), masala, 'Boshqa')", 8),
            'redzone' => [
                'rz01' => (int) $agg->kechikkan,
                'rz03' => (int) $agg->takroriy,
                'rz04' => $klaster,
                'rz05' => (int) $agg->jamoaviy_ochiq,
                'rz06' => $sayyorOchiq,
                'rz08' => (int) $agg->rad,
            ],
            'top_mahalla' => $this->distribution($scope, 'mahalla', 8),
            'ranking' => array_slice($this->kpi($scope), 0, 7),
            'sayyor_count' => $sayyor,
        ];
    }

    /** @return array<int, array{label:string,value:int}> */
    private function distribution(MurojaatScope $scope, string $col, int $limit = 0): array
    {
        return $this->distributionExpr($scope, "coalesce(nullif({$col},''), 'Noma''lum')", $limit);
    }

    /** @return array<int, array{label:string,value:int}> */
    private function distributionExpr(MurojaatScope $scope, string $expr, int $limit = 0): array
    {
        $q = (clone $this->base($scope))
            ->selectRaw("{$expr} as label, count(*) as value")
            ->groupByRaw($expr)->orderByDesc('value');
        if ($limit > 0) {
            $q->limit($limit);
        }

        return $q->get()->map(fn ($r) => ['label' => (string) $r->label, 'value' => (int) $r->value])->all();
    }

    // ------------------------------------------------------------- appeals list

    /** @return array<string, mixed> */
    public function appeals(MurojaatScope $scope, array $f): array
    {
        $page = max(1, (int) ($f['page'] ?? 1));
        $per = min(100, max(5, (int) ($f['per_page'] ?? 15)));

        $q = $this->base($scope)
            ->when(($f['holat'] ?? '') !== '', fn ($x) => $x->where('natija_holat_norm', $f['holat']))
            ->when(($f['manba'] ?? '') !== '', fn ($x) => $x->where('manba_type', $f['manba']))
            ->when(($f['mahalla'] ?? '') !== '', fn ($x) => $x->where('mahalla', $f['mahalla']))
            ->when(($f['tashkilot'] ?? '') !== '', fn ($x) => $x->where('ijrochi', $f['tashkilot']))
            ->when(($f['masala_raqami'] ?? '') !== '', fn ($x) => $x->where(function ($w) use ($f) {
                $w->where('masala_raqami', 'ilike', '%'.$f['masala_raqami'].'%')
                    ->orWhere('murojaat_raqami', 'ilike', '%'.$f['masala_raqami'].'%');
            }))
            ->when(($f['muddat'] ?? '') === 'buzgan', fn ($x) => $x->where('is_kechikkan', true))
            ->when(($f['muddat'] ?? '') === '30dan', fn ($x) => $x->where('kechikish_30dan', '>', 30))
            ->when(($f['muddat'] ?? '') === 'normal', fn ($x) => $x->where('is_kechikkan', false))
            ->when(($f['q'] ?? '') !== '', function ($x) use ($f) {
                $s = '%'.mb_strtolower($f['q']).'%';
                $x->where(function ($w) use ($s) {
                    foreach (['murojaat_raqami', 'masala_raqami', 'familiya', 'ism', 'mahalla', 'ijrochi', 'yonalish'] as $c) {
                        $w->orWhereRaw("lower({$c}) like ?", [$s]);
                    }
                });
            });

        $total = (clone $q)->count();
        $rows = $q->orderByDesc('kelgan_sana_d')->orderBy('id')
            ->forPage($page, $per)
            ->get([
                'id', 'murojaat_raqami', 'masala_raqami', 'familiya', 'ism', 'murojaat_turi',
                'mahalla', 'yonalish', 'masala', 'qaerdan', 'kelgan_sana', 'muddat_kun',
                'natija_holat_norm', 'jamoaviy', 'ijrochi', 'korib_chiqish_kun', 'kechikish_30dan',
                'takroriylik', 'javob_kiritilgan', 'javob_yuborilgan', 'sayyor_tashkilot',
            ]);

        return ['data' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per];
    }

    // ------------------------------------------------------------- KPI (PF-51 3(g))

    /** @return array<int, array<string, mixed>> */
    public function kpi(MurojaatScope $scope): array
    {
        $w = config('murojaat.kpi_weights');
        $rows = (clone $this->base($scope))
            ->whereNotNull('ijrochi')->where('ijrochi', '<>', '')
            ->selectRaw(
                "ijrochi,
                 count(*) jami,
                 count(*) filter (where natija_holat_norm='hal') hal,
                 count(*) filter (where is_kechikkan) kechik,
                 count(*) filter (where takroriylik ilike '%qayta%') qayta,
                 count(*) filter (where takroriylik='Takroriy') takror"
            )->groupBy('ijrochi')->get();

        $list = $rows->map(function ($r) use ($w) {
            $n = max(1, (int) $r->jami);
            $halB = (int) round($r->hal / $n * 100);
            $muddatB = (int) round((1 - $r->kechik / $n) * 100);
            $qaytaB = (int) round((1 - $r->qayta / $n) * 100);
            $takrorB = (int) round((1 - $r->takror / $n) * 100);
            $umumiy = (int) round($halB * $w['hal'] + $muddatB * $w['muddat'] + $qaytaB * $w['qayta'] + $takrorB * $w['takror']);
            $grade = $umumiy >= 85 ? 'A' : ($umumiy >= 70 ? 'B' : ($umumiy >= 50 ? 'C' : 'D'));

            return [
                'name' => (string) $r->ijrochi,
                'jami' => (int) $r->jami, 'hal' => (int) $r->hal, 'kechik' => (int) $r->kechik,
                'qaytaIjro' => (int) $r->qayta, 'takroriy' => (int) $r->takror,
                'halB' => $halB, 'muddatB' => $muddatB, 'qaytaB' => $qaytaB, 'takrorB' => $takrorB,
                'umumiy' => $umumiy, 'grade' => $grade,
            ];
        })->sortByDesc('umumiy')->values()->all();

        return $list;
    }

    // ------------------------------------------------------------- mahalla

    /** @return array<int, array<string, mixed>> */
    public function mahalla(MurojaatScope $scope): array
    {
        $expr = "coalesce(nullif(mahalla,''), 'Mahallasi aniqlanmadi')";
        $rows = (clone $this->base($scope))
            ->selectRaw(
                "{$expr} as mahalla, max(sektor) sektor,
                 count(*) jami,
                 count(*) filter (where natija_holat_norm='hal') hal,
                 count(*) filter (where is_kechikkan) kechik,
                 count(*) filter (where takroriylik='Takroriy') takroriy"
            )->groupByRaw($expr)->orderByDesc('jami')->get();

        // Top masala har mahalla uchun.
        $masala = (clone $this->base($scope))
            ->selectRaw("{$expr} as mahalla, coalesce(nullif(masala,''), nullif(yonalish,''),'Boshqa') as masala, count(*) c")
            ->groupByRaw("{$expr}, coalesce(nullif(masala,''), nullif(yonalish,''),'Boshqa')")
            ->get()->groupBy('mahalla');

        return $rows->map(function ($r) use ($masala) {
            $top = ($masala[$r->mahalla] ?? collect())->sortByDesc('c')->first();

            return [
                'mahalla' => (string) $r->mahalla, 'sektor' => (string) ($r->sektor ?? '—'),
                'jami' => (int) $r->jami, 'hal' => (int) $r->hal,
                'kechik' => (int) $r->kechik, 'takroriy' => (int) $r->takroriy,
                'top_masala' => $top ? (string) $top->masala : '—',
                'top_masala_soni' => $top ? (int) $top->c : 0,
            ];
        })->all();
    }

    // ------------------------------------------------------------- sayyor

    /** @return array<string, mixed> */
    public function sayyor(MurojaatScope $scope): array
    {
        $q = fn () => DB::connection('murojaat')->table('appeals')
            ->whereIn('session_id', $this->activeSessionIds($scope))->where('is_sayyor', true);

        $agg = $q()->selectRaw(
            "count(*) total,
             count(*) filter (where natija_holat_norm='hal') hal,
             count(*) filter (where is_kechikkan) kechik,
             count(distinct sayyor_rahbar) rahbarlar,
             count(distinct sayyor_tashkilot) tashkilotlar"
        )->first();

        $rahbar = $q()->selectRaw("coalesce(nullif(sayyor_rahbar,''),'Noma''lum') label, count(*) value")
            ->groupByRaw("coalesce(nullif(sayyor_rahbar,''),'Noma''lum')")->orderByDesc('value')->limit(8)
            ->get()->map(fn ($r) => ['label' => (string) $r->label, 'value' => (int) $r->value])->all();

        $holat = $q()->selectRaw("coalesce(nullif(natija_holat_norm,''),'jarayon') label, count(*) value")
            ->groupByRaw("coalesce(nullif(natija_holat_norm,''),'jarayon')")->get()
            ->map(fn ($r) => ['label' => (string) $r->label, 'value' => (int) $r->value])->all();

        $list = $q()->orderByDesc('kelgan_sana_d')->limit(200)->get([
            'id', 'murojaat_raqami', 'familiya', 'ism', 'mahalla', 'yonalish', 'masala',
            'sayyor_tashkilot', 'sayyor_rahbar', 'rahbar_lavozim', 'kelgan_sana',
            'natija_holat_norm', 'korib_chiqish_kun', 'kechikish_30dan',
        ]);

        return [
            'kpi' => [
                'total' => (int) $agg->total, 'hal' => (int) $agg->hal, 'kechik' => (int) $agg->kechik,
                'rahbarlar' => (int) $agg->rahbarlar, 'tashkilotlar' => (int) $agg->tashkilotlar,
            ],
            'rahbar' => $rahbar, 'holat' => $holat, 'list' => $list,
        ];
    }

    // ------------------------------------------------------------- statistika (PVQ/XQ)

    /** @return array<string, mixed> */
    public function statistika(MurojaatScope $scope, array $f): array
    {
        $manba = $f['manba'] ?? '';
        $tumanExpr = "coalesce(nullif(yashash_tuman,''), nullif(ijrochi_tuman,''), 'Urganch tumani')";

        $q = fn () => (clone $this->base($scope))
            ->when($manba === 'pvq' || $manba === 'xq', fn ($x) => $x->where('manba_type', $manba))
            ->when($manba === '', fn ($x) => $x->whereIn('manba_type', ['pvq', 'xq']))
            ->when(($f['yil'] ?? '') !== '', fn ($x) => $x->where('kelgan_yil', (int) $f['yil']))
            ->when(($f['oy'] ?? '') !== '', fn ($x) => $x->where('kelgan_oy', (int) $f['oy']));

        $rows = $q()->selectRaw(
            "{$tumanExpr} as tuman,
             count(*) masala,
             count(*) filter (where natija_holat_norm <> 'jarayon' and natija_holat_norm <> '') korilgan,
             count(*) filter (where stat_holat='ijobiy') ijobiy,
             count(*) filter (where stat_holat='huquqiy') huquqiy,
             count(*) filter (where stat_holat='uzoq') uzoq,
             count(*) filter (where stat_holat='tushuntirish') tushuntirish,
             count(*) filter (where stat_holat='rad') rad,
             count(*) filter (where stat_holat='kormasdan') kormasdan,
             count(*) filter (where stat_holat='tugatilgan') tugatilgan,
             count(*) filter (where stat_holat='malumot') malumot,
             count(*) filter (where takroriylik='Takroriy') takroriy,
             count(*) filter (where is_kechikkan) muddat,
             count(*) filter (where kechikish_30dan > 0) kechik,
             count(*) filter (where kechikish_30dan > 30) kechik30,
             count(*) filter (where kechikib_yopilgan > 0) kechik_yop"
        )->groupByRaw($tumanExpr)->orderBy('tuman')->get();

        $out = $rows->map(function ($r) {
            $pct = $r->korilgan > 0 ? (int) round($r->ijobiy / $r->korilgan * 100) : 0;

            return [
                'tuman' => (string) $r->tuman, 'masala' => (int) $r->masala, 'korilgan' => (int) $r->korilgan,
                'ijobiy' => (int) $r->ijobiy, 'pct' => $pct, 'huquqiy' => (int) $r->huquqiy,
                'uzoq' => (int) $r->uzoq, 'tushuntirish' => (int) $r->tushuntirish, 'rad' => (int) $r->rad,
                'kormasdan' => (int) $r->kormasdan, 'tugatilgan' => (int) $r->tugatilgan, 'malumot' => (int) $r->malumot,
                'takroriy' => (int) $r->takroriy, 'muddat' => (int) $r->muddat, 'kechik' => (int) $r->kechik,
                'kechik30' => (int) $r->kechik30, 'kechik_yop' => (int) $r->kechik_yop,
            ];
        })->all();

        return ['rows' => $out];
    }

    // ------------------------------------------------------------- dynamics

    /** @return array<string, mixed> */
    public function dynamics(MurojaatScope $scope, string $period): array
    {
        $rows = (clone $this->base($scope))->whereNotNull('kelgan_sana_d')
            ->get(['kelgan_sana_d', 'kelgan_yil', 'kelgan_oy', 'natija_holat_norm', 'is_kechikkan']);

        $groups = [];
        foreach ($rows as $r) {
            [$key, $sort] = $this->periodKey($r, $period);
            if ($key === null) {
                continue;
            }
            $groups[$key] ??= ['key' => $key, 'sort' => $sort, 'kelgan' => 0, 'hal' => 0, 'kechik' => 0];
            $groups[$key]['kelgan']++;
            if ($r->natija_holat_norm === 'hal') {
                $groups[$key]['hal']++;
            }
            if ($r->is_kechikkan) {
                $groups[$key]['kechik']++;
            }
        }
        usort($groups, fn ($a, $b) => strcmp($a['sort'], $b['sort']));

        return ['period' => $period, 'groups' => array_values($groups)];
    }

    /** @return array{0: ?string, 1: string} */
    private function periodKey(object $r, string $period): array
    {
        $d = $r->kelgan_sana_d ? substr((string) $r->kelgan_sana_d, 0, 10) : null;
        if ($d === null) {
            return [null, ''];
        }
        [$y, $mo, $day] = array_map('intval', explode('-', $d));
        return match ($period) {
            'kun' => [sprintf('%02d.%02d.%d', $day, $mo, $y), sprintf('%d%02d%02d', $y, $mo, $day)],
            'hafta' => [$y.' — '.(int) ceil($day / 7).'-hafta', sprintf('%d%02d%02d', $y, $mo, (int) ceil($day / 7))],
            'chorak' => [$y.' — '.(int) ceil($mo / 3).'-chorak', sprintf('%d%d', $y, (int) ceil($mo / 3))],
            'yil' => [(string) $y, (string) $y],
            default => [$y.' '.self::MONTHS[$mo], sprintf('%d%02d', $y, $mo)], // oy
        };
    }
}
