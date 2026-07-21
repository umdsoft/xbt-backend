<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Services;

use App\Domains\Mahalla\Support\MahallaAccess;
use App\Domains\Mahalla\Support\MahallaZones;
use Illuminate\Support\Facades\DB;

/**
 * OBODONLASHTIRISH agregatsiyasi — ko'cha × iш turi (zona) kesimida "bajarildi" jamlanmasi.
 *
 * "Bajarildi" QO'LDA kiritilmaydi: u mavjud surat-monitoring natijasidan keladi —
 * shu ko'chadagi, zona holati `completed`/`good` bo'lgan xonadonlar (bino) soni.
 * "Qolgan" = xonadon soni − bajarilgan (kadastr bo'yicha xonadon soni).
 *
 * Masъul — `street_assignments` (ko'cha→user) orqali; user lavozimi
 * (`mahalla.users.position`) guruh sarlavhasini beradi. Ko'chalar masъul bo'yicha
 * guruhlanadi (rahbar eskizidagidek).
 */
class ObodStats
{
    /** "Bajarilgan" deb hisoblanadigan zona holatlari. */
    private const DONE_STATUSES = ['completed', 'good'];

    /**
     * @return array<string, mixed>
     */
    public function forMahalla(string $mahallaId): array
    {
        $zones = MahallaZones::zoneOptions();          // [['code','name'], ...] ZONES tartibida
        $zoneCodes = array_column($zones, 'code');
        $zoneCount = count($zones);

        $streets = DB::connection('master')->table('streets')
            ->where('mahalla_id', $mahallaId)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name']);
        $streetIds = $streets->pluck('id')->all();

        $households = $this->householdsByStreet($streetIds);
        $done = $this->doneByStreetZone($streetIds, $zoneCodes);   // [street_id][zone] = n
        $respByStreet = $this->responsibleByStreet($streetIds);    // street_id => object|null

        // --- Ko'chalarni masъul bo'yicha guruhlash ---
        $groups = [];        // key => ['responsible'=>..,'streets'=>[..]]
        $byType = array_fill_keys($zoneCodes, 0);
        $householdTotal = 0;

        foreach ($streets as $s) {
            $hh = $households[$s->id] ?? 0;
            $householdTotal += $hh;

            $works = [];
            foreach ($zones as $z) {
                $d = min($done[$s->id][$z['code']] ?? 0, $hh);
                $byType[$z['code']] += $d;
                $works[] = [
                    'code' => $z['code'],
                    'name' => $z['name'],
                    'done' => $d,
                    'remaining' => max(0, $hh - $d),
                    'pct' => $hh > 0 ? (int) round($d / $hh * 100) : 0,
                ];
            }
            $doneSum = array_sum(array_column($works, 'done'));

            $streetRow = [
                'street_id' => $s->id,
                'name' => $s->name,
                'household_count' => $hh,
                'works' => $works,
                'overall_pct' => $hh > 0 ? (int) round($doneSum / ($hh * $zoneCount) * 100) : 0,
            ];

            $r = $respByStreet[$s->id] ?? null;
            $key = $r?->user_id ?? '__none__';
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'responsible' => $r
                        ? ['name' => $r->name, 'position' => MahallaAccess::positionLabel($r->position) ?? ($r->position ?? '—')]
                        : ['name' => 'Бириктирилмаган кўчалар', 'position' => ''],
                    'streets' => [],
                ];
            }
            $groups[$key]['streets'][] = $streetRow;
        }

        // --- Har guruh uchun jamlanma (subtotal) ---
        $groupsOut = [];
        foreach ($groups as $g) {
            $subHH = 0;
            $subDone = array_fill_keys($zoneCodes, 0);
            foreach ($g['streets'] as $st) {
                $subHH += $st['household_count'];
                foreach ($st['works'] as $w) {
                    $subDone[$w['code']] += $w['done'];
                }
            }
            $subWorks = [];
            foreach ($zones as $z) {
                $d = $subDone[$z['code']];
                $subWorks[] = [
                    'code' => $z['code'], 'name' => $z['name'], 'done' => $d,
                    'remaining' => max(0, $subHH - $d),
                    'pct' => $subHH > 0 ? (int) round($d / $subHH * 100) : 0,
                ];
            }
            $subDoneSum = array_sum($subDone);
            $g['subtotal'] = [
                'household_count' => $subHH,
                'works' => $subWorks,
                'overall_pct' => $subHH > 0 ? (int) round($subDoneSum / ($subHH * $zoneCount) * 100) : 0,
            ];
            $groupsOut[] = $g;
        }

        // --- Umumiy xulosa (summary) ---
        $byTypeOut = [];
        foreach ($zones as $z) {
            $d = $byType[$z['code']];
            $byTypeOut[] = [
                'code' => $z['code'], 'name' => $z['name'], 'done' => $d,
                'total' => $householdTotal,
                'pct' => $householdTotal > 0 ? (int) round($d / $householdTotal * 100) : 0,
            ];
        }
        $overallDone = array_sum($byType);

        return [
            'zones' => $zones,
            'summary' => [
                'household_total' => $householdTotal,
                'street_count' => $streets->count(),
                'by_type' => $byTypeOut,
                'overall_pct' => $householdTotal > 0 ? (int) round($overallDone / ($householdTotal * $zoneCount) * 100) : 0,
            ],
            'groups' => $groupsOut,
        ];
    }

    /**
     * @param  array<int, string>  $streetIds
     * @return array<string, int>
     */
    private function householdsByStreet(array $streetIds): array
    {
        if ($streetIds === []) {
            return [];
        }

        return DB::connection('master')->table('buildings')
            ->whereIn('street_id', $streetIds)
            ->where('type', 'residential')
            ->groupBy('street_id')
            ->selectRaw('street_id, count(*) as n')
            ->pluck('n', 'street_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * Ko'cha × zona bo'yicha "bajarilgan" (completed/good) xonadonlar soni.
     *
     * @param  array<int, string>  $streetIds
     * @param  array<int, string>  $zoneCodes
     * @return array<string, array<string, int>>
     */
    private function doneByStreetZone(array $streetIds, array $zoneCodes): array
    {
        if ($streetIds === []) {
            return [];
        }

        $rows = DB::connection('mahalla')->table('house_zone_states as z')
            ->join('houses as h', 'h.id', '=', 'z.house_id')
            ->whereIn('h.street_id', $streetIds)
            ->whereIn('z.status', self::DONE_STATUSES)
            ->whereIn('z.zone', $zoneCodes)
            ->whereNull('h.deleted_at')
            ->groupBy('h.street_id', 'z.zone')
            ->selectRaw('h.street_id, z.zone, count(distinct z.house_id) as n')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->street_id][$r->zone] = (int) $r->n;
        }

        return $out;
    }

    /**
     * Har ko'chaga birinchi biriktirilgan masъul (user).
     *
     * @param  array<int, string>  $streetIds
     * @return array<string, object>
     */
    private function responsibleByStreet(array $streetIds): array
    {
        if ($streetIds === []) {
            return [];
        }

        $rows = DB::connection('mahalla')->table('street_assignments as a')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->whereIn('a.street_id', $streetIds)
            ->orderBy('a.created_at')
            ->get(['a.street_id', 'u.id as user_id', 'u.name', 'u.position']);

        $out = [];
        foreach ($rows as $r) {
            // Bir ko'chada bir necha masъul bo'lsa — birinchisi (created_at bo'yicha).
            $out[$r->street_id] ??= $r;
        }

        return $out;
    }
}
