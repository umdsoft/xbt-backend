<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Services;

use Illuminate\Support\Facades\DB;

/**
 * KADASTR MA'LUMOTNOMASI — ko'chalar va uylar.
 *
 * Manba `master` sxemasi: 7 261 ko'cha (`streets`) va 405 464 bino
 * (`buildings`). Ular Ayollar moduligacha, mahalla moduli uchun
 * import qilingan — bu yerda faqat O'QILADI.
 *
 * NEGA MODEL EMAS, XOM SO'ROV: `master.buildings` boshqa domenning
 * (`Mahalla`) modeli bilan tasvirlangan va uni Ayollar domeniga
 * import qilish ikki domenni bir-biriga bog'lab qo'yardi. Bu yerda
 * kerak bo'lgani — ikkita o'qish so'rovi, model emas.
 *
 * TURAR-JOY FILTRI MAJBURIY. Kadastrda do'kon, garaj, transformator
 * ham bor (`type = non_residential`) — sinov MFY'sida 757 binodan
 * 224 tasi shunday. Ularni ro'yxatda ko'rsatish faolni chalg'itardi:
 * u do'konga anketa to'ldirishga urinmaydi, lekin ro'yxatni varaqlab
 * vaqt yo'qotadi.
 */
class CadastreDirectory
{
    /** Kadastrdagi turar-joy binosi turi. */
    private const RESIDENTIAL = 'residential';

    /**
     * MFY ko'chalari — har birida uy soni va qamrov.
     *
     * Qamrov (`filled`) bu yerda hisoblanadi, chunki faol uchun eng
     * muhim savol «qaysi ko'cha qolgan?». Ro'yxatni ochib, har
     * ko'chaga alohida so'rov yuborish planshetda sekin bo'lardi.
     *
     * @return array<int, array<string, mixed>>
     */
    public function streets(string $mahallaId): array
    {
        $rows = DB::connection('master')->select(<<<'SQL'
            select s.id,
                   s.name,
                   s.sort_order,
                   count(b.id) filter (where b.type = ?) as houses
            from master.streets s
            left join master.buildings b on b.street_id = s.id
            where s.mahalla_id = ? and s.is_active
            group by s.id, s.name, s.sort_order
            having count(b.id) filter (where b.type = ?) > 0
            order by s.sort_order, s.name
        SQL, [self::RESIDENTIAL, $mahallaId, self::RESIDENTIAL]);

        if ($rows === []) {
            return [];
        }

        $filled = $this->filledByStreet($mahallaId);

        return array_map(fn ($r) => [
            'id' => (string) $r->id,
            'name' => $this->displayName((string) $r->name),
            'raw_name' => (string) $r->name,
            'houses' => (int) $r->houses,
            'filled' => $filled[(string) $r->id] ?? 0,
        ], $rows);
    }

    /**
     * Ko'chadagi turar-joy uylari.
     *
     * `taken` — bu uyda xonadon allaqachon ochilganmi. Faol buni
     * KO'RISHI kerak: aks holda u bir uyni ikki marta yozib,
     * ayollarni takrorlab qo'yardi.
     *
     * @return array<int, array<string, mixed>>
     */
    public function houses(string $mahallaId, string $streetId): array
    {
        $rows = DB::connection('master')->select(<<<'SQL'
            select b.id, b.house_number, b.kadastr, b.lat, b.lng
            from master.buildings b
            where b.mahalla_id = ? and b.street_id = ? and b.type = ?
            order by
              -- Uy raqami MATN ustuni: «2», «2a», «10» ketma-ketligini
              -- alifbo tartibi buzadi (2, 10, 2a). Raqamli qismni
              -- ajratib saralaymiz, qolganini ikkinchi kalit qilamiz.
              nullif(regexp_replace(coalesce(b.house_number, ''), '\D', '', 'g'), '')::bigint
                nulls last,
              b.house_number
        SQL, [$mahallaId, $streetId, self::RESIDENTIAL]);

        if ($rows === []) {
            return [];
        }

        $taken = $this->takenBuildings(array_map(fn ($r) => (string) $r->id, $rows));

        return array_map(fn ($r) => [
            'id' => (string) $r->id,
            'house_number' => (string) ($r->house_number ?? ''),
            'cadastre' => $r->kadastr === null ? null : (string) $r->kadastr,
            'lat' => $r->lat === null ? null : (float) $r->lat,
            'lng' => $r->lng === null ? null : (float) $r->lng,
            'household_id' => $taken[(string) $r->id]['id'] ?? null,
            'women' => $taken[(string) $r->id]['women'] ?? 0,
            'completed' => $taken[(string) $r->id]['completed'] ?? 0,
        ], $rows);
    }

    /**
     * MFY'dagi turar-joy binolari soni — «412 xonadon».
     *
     * Bosh ekranda MFY tanlagichida ko'rsatiladi va marshrut
     * hisobining maxraji bo'ladi.
     */
    public function householdCount(string $mahallaId): int
    {
        return (int) DB::connection('master')->table('buildings')
            ->where('mahalla_id', $mahallaId)
            ->where('type', self::RESIDENTIAL)
            ->count();
    }

    /**
     * Bitta binoning ma'lumoti — xonadon saqlashda tekshirish uchun.
     *
     * @return array<string, mixed>|null
     */
    public function building(string $buildingId, string $mahallaId): ?array
    {
        $row = DB::connection('master')->table('buildings as b')
            ->leftJoin('streets as s', 's.id', '=', 'b.street_id')
            ->where('b.id', $buildingId)
            ->where('b.mahalla_id', $mahallaId)
            ->first(['b.id', 'b.street_id', 'b.house_number', 'b.kadastr', 'b.lat', 'b.lng', 's.name as street_name']);

        if ($row === null) {
            return null;
        }

        return [
            'id' => (string) $row->id,
            'street_id' => $row->street_id === null ? null : (string) $row->street_id,
            'street_name' => $row->street_name === null ? null : $this->displayName((string) $row->street_name),
            'house_number' => (string) ($row->house_number ?? ''),
            'cadastre' => $row->kadastr === null ? null : (string) $row->kadastr,
            'lat' => $row->lat === null ? null : (float) $row->lat,
            'lng' => $row->lng === null ? null : (float) $row->lng,
        ];
    }

    /**
     * Ko'rsatiladigan manzil matni.
     *
     * Kadastrda nom ruscha qisqartma bilan keladi: «ул. Урганч».
     * Faol ekranida «Урганч ko'chasi» ko'rinishi kerak — u o'zbek
     * tilidagi interfeysda ishlaydi va «ул.» unga begona.
     *
     * NOM O'ZGARTIRILMAYDI, faqat KO'RSATILADI: `raw_name` javobda
     * qoladi, chunki kadastr bilan solishtirish o'sha nom bo'yicha
     * boradi.
     */
    private function displayName(string $raw): string
    {
        $name = trim(preg_replace('/^\s*(ул\.|улица|кўча|ko\'cha|kocha)\s*/iu', '', $raw) ?? $raw);

        if ($name === '') {
            return $raw;
        }

        return $name.' ko‘chasi';
    }

    /**
     * Ko'cha bo'yicha to'ldirilgan xonadonlar soni.
     *
     * @return array<string, int>
     */
    private function filledByStreet(string $mahallaId): array
    {
        $rows = DB::connection('ayollar')->table('households')
            ->where('mahalla_id', $mahallaId)
            ->whereNotNull('street_id')
            ->selectRaw('street_id, count(*) as c')
            ->groupBy('street_id')
            ->get();

        return $rows->mapWithKeys(fn ($r) => [(string) $r->street_id => (int) $r->c])->all();
    }

    /**
     * Bino -> xonadon xaritasi (ayol va tugallangan anketa soni bilan).
     *
     * @param  array<int, string>  $buildingIds
     * @return array<string, array<string, mixed>>
     */
    private function takenBuildings(array $buildingIds): array
    {
        if ($buildingIds === []) {
            return [];
        }

        $rows = DB::connection('ayollar')->select(<<<'SQL'
            select h.id, h.building_id,
                   count(distinct w.id) as women,
                   count(distinct a.id) filter (where a.status = 'completed') as completed
            from ayollar.households h
            left join ayollar.women w on w.household_id = h.id and w.deleted_at is null
            left join ayollar.anketas a on a.woman_id = w.id
            where h.building_id = any(?)
            group by h.id, h.building_id
        SQL, ['{'.implode(',', $buildingIds).'}']);

        $out = [];

        foreach ($rows as $r) {
            $out[(string) $r->building_id] = [
                'id' => (string) $r->id,
                'women' => (int) $r->women,
                'completed' => (int) $r->completed,
            ];
        }

        return $out;
    }
}
