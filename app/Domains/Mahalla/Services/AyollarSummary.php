<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Аёллар хатлови — раҳбар панели учун ФАҚАТ АГРЕГАТ кўприк.
 *
 * Дизайн: docs/superpowers/specs/2026-09-14-rahbar-kontekst-paneli-design.md §4.
 *
 * НЕГА: `tuman` роли Аёллар API'сига кира олмайди (ўз RBAC'и, `AyollarAccess`)
 * — ҳар сўровга 403. Бу сервис Аёллар доменига (RBAC, контроллер, route)
 * умуман тегмасдан, тўғридан-тўғри `ayollar` уланиши орқали ФАҚАТ иккита
 * жадвалдан (`mahalla_balances`, `anketa_red_flags` + боғловчи `anketas`)
 * жамланма ўқийди. Шахсий маълумот (исм, ПИНФЛ, паспорт, телефон, манзил,
 * жавоблар) БИР МАРТА ҲАМ ўқилмайди — тегишли устунлар SELECT'га умуман
 * қўшилмаган.
 *
 * МАХФИЙЛИК (small-cell suppression): нозик тоифаларда сон 5 (конфигурацияда
 * созланадиган) дан кам бўлса, аниқ сон ЎРНИГА `null` + `suppressed=true`
 * қайтарилади. Кичик маҳаллада "зўравонлик қурбони: 1" — сон эмас, шахсни
 * очиб бериш.
 */
final class AyollarSummary
{
    /** Нозик тоифалар — кичик сонлар яширилади. */
    private const SENSITIVE = [
        'violence_victim', 'protection_order', 'minor_mother', 'probation',
        'prevention_record', 'narcology_record', 'human_trafficking',
    ];

    /** 13 та байроқ коди → кирилл ёрлиқ. */
    private const LABELS = [
        'chronic_illness' => 'Сурункали касаллик',
        'divorced_widowed' => 'Ажрашган / бева',
        'conflict_family' => 'Низоли оила',
        'social_registry' => 'Ижтимоий реестрда',
        'alimony_problem' => 'Алимент муаммоси',
        'violence_victim' => 'Зўравонлик қурбони',
        'protection_order' => 'Ҳимоя ордери',
        'minor_mother' => 'Вояга етмаган она',
        'probation' => 'Пробация назоратида',
        'prevention_record' => 'Профилактика ҳисобида',
        'narcology_record' => 'Наркология ҳисобида',
        'alien_ideology' => 'Бегона мафкура таъсирида',
        'human_trafficking' => 'Одам савдоси қурбони',
    ];

    /**
     * @return array<string, mixed>
     */
    public function forMahalla(string $mahallaId): array
    {
        if (! $this->schemaAvailable()) {
            return [
                'available' => false,
                'started' => false,
                'period' => null,
                'balance' => null,
                'flags' => [],
                'urgent_total' => 0,
            ];
        }

        $balance = $this->latestBalance($mahallaId);
        $counts = $this->redFlagCounts($mahallaId);

        $flags = [];
        $urgentTotal = 0;
        $threshold = (int) config('mahalla.ayollar_small_cell_threshold', 5);

        foreach (self::LABELS as $code => $label) {
            $count = $counts[$code] ?? 0;
            $sensitive = in_array($code, self::SENSITIVE, true);

            if ($sensitive) {
                $urgentTotal += $count;
            }

            $suppressed = $sensitive && $count < $threshold;

            $flags[] = [
                'code' => $code,
                'label' => $label,
                'count' => $suppressed ? null : $count,
                'suppressed' => $suppressed,
            ];
        }

        $total = (int) ($balance['total'] ?? 0);

        return [
            'available' => true,
            'started' => $total > 0,
            'period' => $balance !== null ? [
                'year' => (int) $balance['period_year'],
                'month' => (int) $balance['period_month'],
            ] : null,
            'balance' => [
                'total' => $total,
                'green' => (int) ($balance['green'] ?? 0),
                'yellow' => (int) ($balance['yellow'] ?? 0),
                'red' => (int) ($balance['red'] ?? 0),
                'status' => $balance['status'] ?? 'open',
            ],
            'flags' => $flags,
            // Жами сон ҲЕЧ ҚАЧОН яширилмайди — жамланган йиғинди шахсни очмайди
            // (фақат алоҳида кичик тоифа сони очиб беради).
            'urgent_total' => $urgentTotal,
        ];
    }

    /**
     * Схема (жадваллар) мавжудлигини текширади — янги ўрнатишда `ayollar`
     * уланиши конфигурацияда бўлса ҳам, схема ҳали яратилмаган бўлиши мумкин.
     * Портлаш ўрнига `available=false` қайтарилади.
     */
    private function schemaAvailable(): bool
    {
        try {
            return Schema::connection('ayollar')->hasTable('mahalla_balances')
                && Schema::connection('ayollar')->hasTable('anketas')
                && Schema::connection('ayollar')->hasTable('anketa_red_flags');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestBalance(string $mahallaId): ?array
    {
        $row = DB::connection('ayollar')->table('mahalla_balances')
            ->where('mahalla_id', $mahallaId)
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->first(['period_year', 'period_month', 'total', 'green', 'yellow', 'red', 'status']);

        return $row !== null ? (array) $row : null;
    }

    /**
     * Шу маҳалладаги анкеталарга боғланган байроқлар — код бўйича сон.
     * ФАҚАТ `flag_code` ва `count(*)` ўқилади — анкетанинг ўзидаги шахсий
     * майдонлар (`answers`, `woman_id`, ...) SELECT'га умуман кирмайди.
     *
     * @return array<string, int>
     */
    private function redFlagCounts(string $mahallaId): array
    {
        $rows = DB::connection('ayollar')->table('anketa_red_flags as f')
            ->join('anketas as a', 'a.id', '=', 'f.anketa_id')
            ->where('a.mahalla_id', $mahallaId)
            ->whereNull('a.deleted_at')
            ->groupBy('f.flag_code')
            ->selectRaw('f.flag_code, count(*) as n')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$row->flag_code] = (int) $row->n;
        }

        return $out;
    }
}
