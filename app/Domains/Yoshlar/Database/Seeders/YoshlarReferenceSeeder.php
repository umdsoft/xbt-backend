<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Database\Seeders;

use App\Domains\Yoshlar\Models\Organization;
use App\Domains\Yoshlar\Models\Sector;
use App\Domains\Yoshlar\Support\Translit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Spravochnik urug'i: 9 sektor + yoshlar vertikali (1 viloyat + 13 tuman).
 * Idempotent: `code` / (type + district_id) bo'yicha mavjud bo'lsa o'tkazib yuboriladi.
 */
class YoshlarReferenceSeeder extends Seeder
{
    /** @var array<int, array{code: string, cyr: string, lat: string}> */
    private const SECTORS = [
        ['code' => 'bandlik', 'cyr' => 'Бандлик', 'lat' => 'Bandlik'],
        ['code' => 'talim', 'cyr' => 'Халқ таълими', 'lat' => 'Xalq ta‘limi'],
        ['code' => 'oliy_talim', 'cyr' => 'Олий таълим', 'lat' => 'Oliy ta‘lim'],
        ['code' => 'sogliq', 'cyr' => 'Соғлиқни сақлаш', 'lat' => 'Sog‘liqni saqlash'],
        ['code' => 'soliq', 'cyr' => 'Солиқ', 'lat' => 'Soliq'],
        ['code' => 'iib', 'cyr' => 'ИИБ (профилактика)', 'lat' => 'IIB (profilaktika)'],
        ['code' => 'madaniyat', 'cyr' => 'Маданият', 'lat' => 'Madaniyat'],
        ['code' => 'sport', 'cyr' => 'Спорт', 'lat' => 'Sport'],
        ['code' => 'mahalla_oila', 'cyr' => 'Маҳалла ва оила', 'lat' => 'Mahalla va oila'],
    ];

    public function run(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        foreach (self::SECTORS as $i => $sector) {
            Sector::query()->firstOrCreate(
                ['code' => $sector['code']],
                ['name_cyr' => $sector['cyr'], 'name_lat' => $sector['lat'], 'sort_order' => $i + 1],
            );
        }

        $viloyat = Organization::query()->firstOrCreate(
            ['type' => Organization::TYPE_VILOYAT_YOSHLAR],
            [
                'name_cyr' => 'Хоразм вилояти ёшлар ишлари бошқармаси',
                'name_lat' => 'Xorazm viloyati yoshlar ishlari boshqarmasi',
                'short_name' => 'Viloyat yoshlar boshqarmasi',
                'is_active' => true,
            ],
        );

        $districts = DB::connection('master')->table('districts')
            ->orderBy('sort_order')->get(['id', 'name_cyr', 'name_lat']);

        foreach ($districts as $district) {
            // `districts.name_lat` allaqachon «... tumani» / «... shahri» shaklida —
            // yana «tuman» qo'shsak «Xiva tumani tuman yoshlar bo'limi» chiqadi.
            $lat = ((string) $district->name_lat).' yoshlar bo‘limi';

            Organization::query()->firstOrCreate(
                ['type' => Organization::TYPE_TUMAN_YOSHLAR, 'district_id' => $district->id],
                [
                    'parent_id' => $viloyat->id,
                    'name_cyr' => Translit::toCyr($lat),
                    'name_lat' => $lat,
                    'is_active' => true,
                ],
            );
        }
    }
}
