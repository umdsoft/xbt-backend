<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Services;

use App\Domains\Mahalla\Support\ExecutiveCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Маҳалла раиси — кўчаларни таҳрирлаш ва уйларни кўчага бириктириш.
 *
 * Nima uchun: kadastr importida ko'p ko'cha noto'g'ri (imlo, dublikat, soxta
 * 1-2 uyли), koordinata esa qaysi uy qayerda ekanini beradi, ammo qaysi ko'cha
 * RASMIY ekanini faqat rais biladi. Shuning uchun rais xarita ustida uylarni
 * to'g'ri ko'chaga biriktiradi va ko'cha ro'yxatini tozalaydi.
 *
 * Qamrov (mahalla_id) HAR DOIM chaqiruvchidan (profil), so'rovdan emas.
 * Har bir ko'cha/bino shu mahallaga tegishliligi SHU YERDA tekshiriladi —
 * boshqa mahalla ma'lumotini o'zgartirib bo'lmasin.
 */
class StreetEditor
{
    /** Ko'chalarni ajratuvchi barqaror rang palitrasi (xarita + ro'yxat bir xil). */
    private const PALETTE = [
        '#2563eb', '#16a34a', '#dc2626', '#d97706', '#7c3aed', '#0891b2',
        '#db2777', '#65a30d', '#ea580c', '#0d9488', '#4f46e5', '#ca8a04',
        '#be123c', '#059669', '#9333ea', '#c2410c', '#0284c7', '#a16207',
        '#15803d', '#b91c1c',
    ];

    /**
     * Kiril katta->kichik (lower() bu bazada kirilни kichiklashtirmaydi).
     * Nom unikalligini KATTA-KICHIK farqsiz tekshirish uchun.
     */
    private const CYR_U = 'АБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯЎҚҒҲ';
    private const CYR_L = 'абвгдеёжзийклмнопрстуфхцчшщъыьэюяўқғҳ';

    public function __construct(private readonly StreetAggregates $aggregates)
    {
    }

    /** Nomni KATTA-KICHIK farqsiz solishtirish SQL ifodasi (kiril-aware). */
    private function foldExpr(string $col): string
    {
        return "translate(lower($col), '".self::CYR_U."', '".self::CYR_L."')";
    }

    /** street_id -> barqaror rang (id hash bo'yicha, ro'yxat o'zgarsa ham o'zgarmaydi). */
    public function colorFor(string $streetId): string
    {
        return self::PALETTE[crc32($streetId) % count(self::PALETTE)];
    }

    /**
     * Ko'chalar ro'yxati — uy soni (residential) va rang bilan.
     *
     * @return array{streets: array<int, array<string, mixed>>, colors: array<string, string>}
     */
    public function streets(string $mahallaId): array
    {
        $streets = $this->aggregates->activeStreets($mahallaId);
        $ids = $streets->pluck('id')->all();
        $counts = $this->aggregates->buildingsByStreet($ids, 'residential');

        $rows = [];
        $colors = [];
        foreach ($streets as $s) {
            $colors[$s->id] = $this->colorFor($s->id);
            $rows[] = [
                'id' => $s->id,
                'name' => $s->name,
                'houses' => $counts[$s->id] ?? 0,
            ];
        }

        return ['streets' => $rows, 'colors' => $colors];
    }

    /**
     * Xarita ma'lumoti: residential binolar (koordinatali) + chegara.
     *
     * @return array{buildings: array<int, array<string, mixed>>, boundary: array<string, mixed>}
     */
    public function mapData(string $mahallaId): array
    {
        $buildings = DB::connection('master')->table('buildings')
            ->where('mahalla_id', $mahallaId)
            ->where('type', 'residential')
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->get(['id', 'lat', 'lng', 'street_id'])
            ->map(fn ($b) => [
                'id' => $b->id,
                'lat' => (float) $b->lat,
                'lng' => (float) $b->lng,
                'street_id' => $b->street_id,
            ])
            ->all();

        return ['buildings' => $buildings, 'boundary' => $this->boundary($mahallaId)];
    }

    /** Yangi ko'cha. Nom mahallada unikal bo'lishi shart. */
    public function create(string $mahallaId, string $name, string $userId): ?array
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $master = DB::connection('master');
        $exists = $master->table('streets')
            ->where('mahalla_id', $mahallaId)
            ->whereRaw($this->foldExpr('name').' = '.$this->foldExpr('?'), [$name])
            ->exists();
        if ($exists) {
            return null; // nom band
        }

        $id = (string) Str::uuid();
        $maxSort = (int) $master->table('streets')->where('mahalla_id', $mahallaId)->max('sort_order');

        $master->table('streets')->insert([
            'id' => $id,
            'mahalla_id' => $mahallaId,
            'name' => $name,
            'sort_order' => $maxSort + 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->log($mahallaId, 'create', $id, null, ['name' => $name], $userId);

        return ['id' => $id, 'name' => $name, 'houses' => 0];
    }

    /** Ko'cha nomini o'zgartiradi (+ buildings.street matnini sync). */
    public function rename(string $mahallaId, string $streetId, string $name, string $userId): bool
    {
        $name = trim($name);
        if ($name === '') {
            return false;
        }

        $master = DB::connection('master');
        $street = $master->table('streets')
            ->where('id', $streetId)->where('mahalla_id', $mahallaId)
            ->first(['id', 'name']);
        if ($street === null) {
            return false;
        }

        // Boshqa ko'cha shu nomni olgan bo'lsa — merge kerak, rename emas.
        $clash = $master->table('streets')
            ->where('mahalla_id', $mahallaId)->where('id', '!=', $streetId)
            ->whereRaw($this->foldExpr('name').' = '.$this->foldExpr('?'), [$name])->exists();
        if ($clash) {
            return false;
        }

        $master->transaction(function () use ($master, $streetId, $mahallaId, $name, $street, $userId) {
            $master->table('streets')->where('id', $streetId)
                ->update(['name' => $name, 'updated_at' => now()]);
            $master->table('buildings')->where('mahalla_id', $mahallaId)->where('street_id', $streetId)
                ->update(['street' => $name, 'updated_at' => now()]);
            $this->log($mahallaId, 'rename', $streetId, null, ['from' => $street->name, 'to' => $name], $userId);
        });

        ExecutiveCache::flush();

        return true;
    }

    /**
     * A ko'chani B ga birlashtiradi: A uylari B ga o'tadi, A o'chadi.
     *
     * @return bool  false — biror ko'cha shu mahallada yo'q yoki source=target
     */
    public function merge(string $mahallaId, string $sourceId, string $targetId, string $userId): bool
    {
        if ($sourceId === $targetId) {
            return false;
        }

        $master = DB::connection('master');
        $source = $master->table('streets')->where('id', $sourceId)->where('mahalla_id', $mahallaId)->first(['id', 'name']);
        $target = $master->table('streets')->where('id', $targetId)->where('mahalla_id', $mahallaId)->first(['id', 'name']);
        if ($source === null || $target === null) {
            return false;
        }

        $moved = 0;
        DB::transaction(function () use ($master, $mahallaId, $sourceId, $targetId, $source, $target, $userId, &$moved) {
            $moved = $master->table('buildings')
                ->where('mahalla_id', $mahallaId)->where('street_id', $sourceId)
                ->update(['street_id' => $targetId, 'street' => $target->name, 'updated_at' => now()]);

            DB::connection('mahalla')->table('houses')
                ->where('mahalla_id', $mahallaId)->where('street_id', $sourceId)
                ->update(['street_id' => $targetId, 'updated_at' => now()]);
            DB::connection('mahalla')->table('street_assignments')
                ->where('street_id', $sourceId)->update(['street_id' => $targetId]);

            $master->table('streets')->where('id', $sourceId)->delete();

            $this->log($mahallaId, 'merge', $targetId, null,
                ['source_id' => $sourceId, 'from' => $source->name, 'to' => $target->name, 'count' => $moved], $userId);
        });

        ExecutiveCache::flush();

        return true;
    }

    /**
     * Ko'chani o'chiradi — FAQAT bo'sh bo'lsa (uy/uy-yozuv/biriktirish yo'q).
     *
     * @return string  'ok' | 'not_found' | 'not_empty'
     */
    public function deleteStreet(string $mahallaId, string $streetId, string $userId): string
    {
        $master = DB::connection('master');
        $street = $master->table('streets')->where('id', $streetId)->where('mahalla_id', $mahallaId)->first(['id', 'name']);
        if ($street === null) {
            return 'not_found';
        }

        $hasBuildings = $master->table('buildings')->where('street_id', $streetId)->exists();
        $hasHouses = DB::connection('mahalla')->table('houses')->where('street_id', $streetId)->exists();
        $hasAssign = DB::connection('mahalla')->table('street_assignments')->where('street_id', $streetId)->exists();
        if ($hasBuildings || $hasHouses || $hasAssign) {
            return 'not_empty';
        }

        $master->table('streets')->where('id', $streetId)->delete();
        $this->log($mahallaId, 'delete', $streetId, null, ['name' => $street->name], $userId);
        ExecutiveCache::flush();

        return 'ok';
    }

    /**
     * Bino(lar)ni ko'chaga biriktiradi (xaritadan bosish/hudud tanlash).
     *
     * @param  array<int, string>  $buildingIds
     * @return int|null  ko'chirilgan bino soni; null — ko'cha yoki biror bino shu mahallada emas
     */
    public function assign(string $mahallaId, array $buildingIds, string $streetId, string $userId): ?int
    {
        $master = DB::connection('master');
        $street = $master->table('streets')->where('id', $streetId)->where('mahalla_id', $mahallaId)->first(['id', 'name']);
        if ($street === null) {
            return null;
        }

        $ids = array_values(array_unique($buildingIds));
        if ($ids === []) {
            return 0;
        }

        // Barcha bino shu mahallada bo'lishi shart — biri chetda bo'lsa butun so'rov rad.
        $inScope = (int) $master->table('buildings')
            ->whereIn('id', $ids)->where('mahalla_id', $mahallaId)->count();
        if ($inScope !== count($ids)) {
            return null;
        }

        $count = 0;
        DB::transaction(function () use ($master, $mahallaId, $ids, $streetId, $street, $userId, &$count) {
            $count = $master->table('buildings')
                ->whereIn('id', $ids)->where('mahalla_id', $mahallaId)
                ->update(['street_id' => $streetId, 'street' => $street->name, 'updated_at' => now()]);

            // Mos uy-yozuvlar ham (building_id orqali) shu ko'chaga o'tadi.
            DB::connection('mahalla')->table('houses')
                ->whereIn('building_id', $ids)->where('mahalla_id', $mahallaId)
                ->update(['street_id' => $streetId, 'updated_at' => now()]);

            // Ommaviy biriktirishda har bino uchun alohida qator emas — bitta yozuv (count bilan).
            $this->log($mahallaId, 'assign', $streetId,
                count($ids) === 1 ? $ids[0] : null,
                ['to' => $street->name, 'count' => $count], $userId);
        });

        ExecutiveCache::flush();

        return $count;
    }

    /** Мaҳалла чегараси — харита учун (RaisCadastre bilan bir xil format). */
    private function boundary(string $mahallaId): array
    {
        $row = DB::connection('master')->table('mahallas')
            ->where('id', $mahallaId)->whereNotNull('boundary')
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

    /** Audit yozuvi. */
    private function log(string $mahallaId, string $action, ?string $streetId, ?string $buildingId, array $detail, string $userId): void
    {
        DB::connection('mahalla')->table('street_edits')->insert([
            'id' => (string) Str::uuid(),
            'mahalla_id' => $mahallaId,
            'action' => $action,
            'street_id' => $streetId,
            'building_id' => $buildingId,
            'detail' => json_encode($detail, JSON_UNESCAPED_UNICODE),
            'performed_by' => $userId,
            'created_at' => now(),
        ]);
    }
}
