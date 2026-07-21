<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Services;

use App\Domains\Mahalla\Support\ExecutiveCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Маҳалла раиси учун кадастр бинолари билан ишлаш.
 *
 * Nima uchun kerak: kadastrdagi «maqsad» yozuvi ko'pincha bo'sh yoki umumiy
 * («Бино», «Маъмурий бино»). Tasnif kalit so'zga tayanadi va yozuv bo'lmasa
 * hech nima qila olmaydi — Shovotda shu sababdan 40+ maktabdan faqat 15
 * tasi topilgan.
 *
 * Rais o'z hududidagi har bir binoni biladi. Uning ishi — mavjud kadastr
 * yozuvini TO'G'RI TURGA bog'lash. Yangi bino qo'shmaydi: binolar ro'yxati
 * kadastrdan keladi va o'zgarmaydi.
 */
class RaisCadastre
{
    /**
     * Mahalladagi binolar — turi bo'yicha yoki matn bo'yicha qidirish.
     *
     * @return array{total: int, items: array<int, array<string, mixed>>}
     */
    public function buildings(
        string $mahallaId,
        ?string $search = null,
        ?string $typeCode = null,
        bool $onlyUnclassified = false,
        int $page = 1,
        int $perPage = 40,
    ): array {
        $base = fn () => DB::connection('master')->table('buildings as b')
            ->leftJoin('object_types as t', 't.id', '=', 'b.object_type_id')
            ->where('b.mahalla_id', $mahallaId)
            // Turar-joy binolari bu ro'yxatda kerak emas: ular xonadon,
            // ijtimoiy obyekt emas. 34 ming qatorni ko'rsatish foydasiz.
            ->where('b.type', '!=', 'residential')
            ->when($onlyUnclassified, fn ($q) => $q->where(function ($x) {
                $x->whereNull('b.object_type_id')->orWhere('t.code', 'boshqa');
            }))
            ->when($typeCode !== null, fn ($q) => $q->where('t.code', $typeCode))
            ->when($search !== null && $search !== '', function ($q) use ($search) {
                $like = '%'.$search.'%';
                $q->where(function ($x) use ($like) {
                    $x->where('b.purpose', 'ilike', $like)
                        ->orWhere('b.address', 'ilike', $like)
                        ->orWhere('b.kadastr', 'ilike', $like);
                });
            });

        $total = (int) $base()->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $lastPage));

        $items = $base()
            ->orderByRaw('t.sort_order nulls first')
            ->orderBy('b.address')
            ->forPage($page, $perPage)
            ->get([
                'b.id', 'b.kadastr', 'b.address', 'b.purpose', 'b.lat', 'b.lng',
                'b.object_type_id', 't.code as type_code', 't.name_cyr as type_name',
                't.is_social',
            ])
            ->map(fn ($r) => [
                'id' => $r->id,
                'kadastr' => $r->kadastr,
                'address' => $r->address,
                // Kadastrdagi asl yozuv — rais aynan shunga qarab qaror qiladi.
                'purpose' => $r->purpose,
                'lat' => $r->lat === null ? null : (float) $r->lat,
                'lng' => $r->lng === null ? null : (float) $r->lng,
                'type_id' => $r->object_type_id,
                'type_code' => $r->type_code,
                'type_name' => $r->type_name,
                'is_social' => (bool) $r->is_social,
            ])
            ->all();

        return [
            'total' => $total,
            'items' => $items,
            'meta' => [
                'total' => $total,
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
            ],
        ];
    }

    /**
     * Bino turini o'zgartiradi va o'zgarishni yozib qo'yadi.
     *
     * Mahalla tekshiruvi CHAQIRUVCHIDA emas, shu yerda: qamrov nazorati bitta
     * joyda bo'lishi kerak. Aks holda yangi endpoint qo'shilganda uni
     * takrorlash unutiladi va boshqa mahalla binosi tahrirlanadi.
     *
     * @return bool  `false` — bino bu mahallaga tegishli emas
     */
    public function classify(
        string $buildingId,
        string $mahallaId,
        ?string $typeId,
        string $userId,
        ?string $note = null,
    ): bool {
        $master = DB::connection('master');

        $building = $master->table('buildings')
            ->where('id', $buildingId)
            ->where('mahalla_id', $mahallaId)
            ->first(['id', 'object_type_id']);

        if ($building === null) {
            return false;
        }

        if ($typeId !== null && ! $master->table('object_types')->where('id', $typeId)->exists()) {
            return false;
        }

        $master->transaction(function () use ($master, $building, $mahallaId, $typeId, $userId, $note) {
            $master->table('buildings')
                ->where('id', $building->id)
                ->update(['object_type_id' => $typeId, 'updated_at' => now()]);

            $master->table('building_type_changes')->insert([
                'id' => (string) Str::uuid(),
                'building_id' => $building->id,
                'mahalla_id' => $mahallaId,
                'from_type_id' => $building->object_type_id,
                'to_type_id' => $typeId,
                'user_id' => $userId,
                'note' => $note === null ? null : mb_substr($note, 0, 500),
                'created_at' => now(),
            ]);
        });

        // Ijtimoiy obyektlar soni keshda — tuzatish darhol ko'rinishi kerak,
        // aks holda rais o'zgartiradi va natijani ko'rmay ikkinchi marta
        // o'zgartirishga urinadi.
        ExecutiveCache::flush();

        return true;
    }

    /**
     * Кўчалар кесими — раис бош панели учун.
     *
     * Tuman jadvali mahallalar kesimini bergani kabi, rais paneli ko'chalar
     * kesimini beradi: har ko'cha uchun xonadon (kadastr), ijtimoiy obyekt va
     * shu haftadagi o'zgarish. Rais "qaysi ko'chada ish qanday?" degan savolga
     * javob oladi.
     *
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, int>}
     */
    public function streetsBreakdown(string $mahallaId): array
    {
        $streets = DB::connection('master')->table('streets')
            ->where('mahalla_id', $mahallaId)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name']);

        $streetIds = $streets->pluck('id')->all();

        $households = $this->countByStreet($streetIds, 'residential', null);
        $social = $this->socialByStreet($streetIds);
        $changed = $this->changedByStreet($streetIds);
        $contracts = $this->contractsByStreet($mahallaId);

        $rows = [];
        $totals = ['households' => 0, 'social_objects' => 0, 'changed_week' => 0, 'contracts' => 0];

        foreach ($streets as $s) {
            $hh = $households[$s->id] ?? 0;
            $so = $social[$s->id] ?? 0;
            $ch = $changed[$s->id] ?? 0;
            // Qamrov = shartnoma IMZOLAGAN xonadon (DISTINCT bino), nechta
            // shartnoma emas: bitta uyda bir necha shartnoma bo'lishi mumkin,
            // lekin u bitta qamralgan xonadon.
            $ct = $contracts[$s->id] ?? 0;

            $totals['households'] += $hh;
            $totals['social_objects'] += $so;
            $totals['changed_week'] += $ch;
            $totals['contracts'] += $ct;

            $rows[] = [
                'street' => ['id' => $s->id, 'name' => $s->name],
                'households' => $hh,
                'social_objects' => $so,
                'changed_week' => $ch,
                'contracts' => $ct,
                // Shartnoma qamrovi: imzolagan xonadon / jami xonadon.
                'percent' => $hh > 0 ? round($ct / $hh * 100, 1) : 0.0,
            ];
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * @param  array<int, string>  $streetIds
     * @return array<string, int>
     */
    private function countByStreet(array $streetIds, string $type, ?bool $social): array
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
     * @param  array<int, string>  $streetIds
     * @return array<string, int>
     */
    private function socialByStreet(array $streetIds): array
    {
        if ($streetIds === []) {
            return [];
        }

        return DB::connection('master')->table('buildings as b')
            ->join('object_types as t', 't.id', '=', 'b.object_type_id')
            ->whereIn('b.street_id', $streetIds)
            ->where('t.is_social', true)
            ->groupBy('b.street_id')
            ->selectRaw('b.street_id, count(*) as n')
            ->pluck('n', 'b.street_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * Shartnoma imzolagan xonadonlar — ko'cha kesimida (DISTINCT bino).
     *
     * @return array<string, int>
     */
    private function contractsByStreet(string $mahallaId): array
    {
        return DB::connection('mahalla')->table('social_contracts')
            ->where('mahalla_id', $mahallaId)
            ->whereNull('deleted_at')
            ->whereNotNull('street_id')
            ->whereNotNull('building_id')
            ->groupBy('street_id')
            ->selectRaw('street_id, count(distinct building_id) as n')
            ->pluck('n', 'street_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * Shu haftada o'zgargan xonadonlar — ko'cha kesimida (DISTINCT uy).
     *
     * @param  array<int, string>  $streetIds
     * @return array<string, int>
     */
    private function changedByStreet(array $streetIds): array
    {
        if ($streetIds === []) {
            return [];
        }

        $weekStart = \Illuminate\Support\Carbon::now('Asia/Tashkent')->startOfWeek()->utc();

        return DB::connection('mahalla')->table('zone_observations as o')
            ->join('houses as h', 'h.id', '=', 'o.house_id')
            ->whereIn('h.street_id', $streetIds)
            ->where('o.is_change', true)
            ->where('o.observed_at', '>=', $weekStart)
            ->groupBy('h.street_id')
            ->selectRaw('h.street_id, count(distinct o.house_id) as n')
            ->pluck('n', 'street_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * Маҳалла чегараси — харита учун.
     *
     * Tuman geojson'i bilan bir xil soddalashtirish (0.0003 ≈ 33 m): bitta
     * mahalla uchun ham xom geometriya ortiqcha, ekranda farqi ko'rinmaydi.
     *
     * Javob GeoJSON `FeatureCollection` shaklida — `FeatureMap` tuman
     * xaritasi bilan bir xil formatni kutadi, alohida holat kerak emas.
     *
     * @return array{type: string, features: array<int, array<string, mixed>>}
     */
    public function boundary(string $mahallaId): array
    {
        $row = DB::connection('master')->table('mahallas')
            ->where('id', $mahallaId)
            ->whereNotNull('boundary')
            ->selectRaw('id, name_cyr, ST_AsGeoJSON(ST_SimplifyPreserveTopology(boundary, ?)) as geom', [0.0003])
            ->first();

        if ($row === null) {
            return ['type' => 'FeatureCollection', 'features' => []];
        }

        return [
            'type' => 'FeatureCollection',
            'features' => [[
                'type' => 'Feature',
                'properties' => ['id' => $row->id, 'name' => $row->name_cyr],
                'geometry' => json_decode((string) $row->geom, true),
            ]],
        ];
    }

    /**
     * Маҳалладаги БАРЧА ижтимоий объектлар — харитага қўйиш учун.
     *
     * Jadval suzgichidan MUSTAQIL: rais qidiruv yozganda xaritadagi obyektlar
     * yo'qolib qolmasligi kerak. Ular kontekst — "mahallamda nima bor" degan
     * savolga javob, qidiruv natijasi emas.
     *
     * @return array<int, array<string, mixed>>
     */
    public function socialPoints(string $mahallaId): array
    {
        return DB::connection('master')->table('buildings as b')
            ->join('object_types as t', 't.id', '=', 'b.object_type_id')
            ->where('b.mahalla_id', $mahallaId)
            ->where('t.is_social', true)
            ->whereNotNull('b.lat')
            ->whereNotNull('b.lng')
            ->orderBy('t.sort_order')
            ->get(['b.id', 'b.lat', 'b.lng', 'b.purpose', 'b.address', 't.code', 't.name_cyr'])
            ->map(fn ($r) => [
                'id' => $r->id,
                'lat' => (float) $r->lat,
                'lng' => (float) $r->lng,
                'title' => $r->purpose ?: $r->name_cyr,
                'subtitle' => $r->address,
                'type_code' => $r->code,
                'type_name' => $r->name_cyr,
            ])
            ->all();
    }

    /**
     * Mahalladagi so'nggi tuzatishlar — kim nimani o'zgartirgani ko'rinib tursin.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentChanges(string $mahallaId, int $limit = 20): array
    {
        return DB::connection('master')->table('building_type_changes as c')
            ->leftJoin('buildings as b', 'b.id', '=', 'c.building_id')
            ->leftJoin('object_types as f', 'f.id', '=', 'c.from_type_id')
            ->leftJoin('object_types as t', 't.id', '=', 'c.to_type_id')
            ->where('c.mahalla_id', $mahallaId)
            ->orderByDesc('c.created_at')
            ->limit($limit)
            ->get([
                'c.id', 'c.created_at', 'c.note',
                'b.address', 'b.purpose',
                'f.name_cyr as from_name', 't.name_cyr as to_name',
            ])
            ->map(fn ($r) => [
                'id' => $r->id,
                'at' => \Illuminate\Support\Carbon::parse($r->created_at)->toIso8601String(),
                'address' => $r->address,
                'purpose' => $r->purpose,
                'from' => $r->from_name,
                'to' => $r->to_name,
                'note' => $r->note,
            ])
            ->all();
    }
}
