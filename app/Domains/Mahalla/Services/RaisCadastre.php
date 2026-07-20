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
        int $limit = 200,
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

        $items = $base()
            ->orderByRaw('t.sort_order nulls first')
            ->orderBy('b.address')
            ->limit($limit)
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

        return ['total' => $total, 'items' => $items];
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
