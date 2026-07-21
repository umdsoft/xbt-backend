<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ko'cha kesimidagi umumiy agregatlar (master sxema).
 *
 * Ilgari bir xil "ko'chalar ro'yxati" va "ko'cha bo'yicha bino soni" so'rovlari
 * ObodStats, RaisCadastre (va boshqa servislarда) alohida yozilardi (DRY).
 * "Xonadon = residential bino" ta'rifi endi bitta joyda.
 */
class StreetAggregates
{
    /** Mahalla faol ko'chalari (id, name) — sort_order, keyin name bo'yicha. */
    public function activeStreets(string $mahallaId): Collection
    {
        return DB::connection('master')->table('streets')
            ->where('mahalla_id', $mahallaId)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Ko'cha bo'yicha bino soni (berilgan type), [street_id => n].
     *
     * @param  array<int, string>  $streetIds
     * @return array<string, int>
     */
    public function buildingsByStreet(array $streetIds, string $type): array
    {
        if ($streetIds === []) {
            return [];
        }

        return DB::connection('master')->table('buildings')
            ->whereIn('street_id', $streetIds)
            ->where('type', $type)
            ->groupBy('street_id')
            ->selectRaw('street_id, count(*) as n')
            ->pluck('n', 'street_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * Ko'cha bo'yicha turar-joy (residential) xonadonlar soni.
     *
     * @param  array<int, string>  $streetIds
     * @return array<string, int>
     */
    public function residentialByStreet(array $streetIds): array
    {
        return $this->buildingsByStreet($streetIds, 'residential');
    }
}
