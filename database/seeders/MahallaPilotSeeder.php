<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Mahalla\Models\House;
use App\Domains\Mahalla\Models\Master\District;
use App\Domains\Mahalla\Models\Master\Mahalla;
use App\Domains\Mahalla\Models\Master\Region;
use App\Domains\Mahalla\Models\Master\Street;
use Illuminate\Database\Seeder;

/**
 * Pilot geo (master) + namunaviy honadonlar (mahalla). Idempotent.
 * Markaziy foydalanuvchi/ruxsat seeding — alohida (identity seeder).
 */
class MahallaPilotSeeder extends Seeder
{
    public function run(): void
    {
        $region = Region::firstOrCreate(
            ['code' => 'UZ-XO'],
            ['name_cyr' => 'Хоразм', 'name_lat' => 'Xorazm', 'sort_order' => 1],
        );

        $district = District::firstOrCreate(
            ['code' => 'UZ-XO-XONQA'],
            ['region_id' => $region->id, 'name_cyr' => 'Хонқа', 'name_lat' => 'Xonqa', 'sort_order' => 1],
        );

        $mahalla1 = Mahalla::firstOrCreate(
            ['district_id' => $district->id, 'name_lat' => 'Guliston MFY'],
            ['name_cyr' => 'Гулистон МФЙ', 'center_lat' => 41.5900, 'center_lng' => 60.7900, 'sort_order' => 1, 'is_active' => true],
        );

        $mahalla2 = Mahalla::firstOrCreate(
            ['district_id' => $district->id, 'name_lat' => 'Navbahor MFY'],
            ['name_cyr' => 'Навбаҳор МФЙ', 'center_lat' => 41.5820, 'center_lng' => 60.7710, 'sort_order' => 2, 'is_active' => true],
        );

        $streets = [];
        foreach (['Мустақиллик', 'Дўстлик', 'Бунёдкор'] as $i => $name) {
            $streets[] = Street::firstOrCreate(
                ['mahalla_id' => $mahalla1->id, 'name' => $name],
                ['sort_order' => $i + 1, 'is_active' => true],
            );
        }
        foreach (['Ободлик', 'Файз'] as $i => $name) {
            Street::firstOrCreate(
                ['mahalla_id' => $mahalla2->id, 'name' => $name],
                ['sort_order' => $i + 1, 'is_active' => true],
            );
        }

        $street = $streets[0];
        for ($n = 1; $n <= 3; $n++) {
            House::firstOrCreate(
                ['cadastral_number' => sprintf('06:12:0101:%04d', $n)],
                [
                    'district_id' => $district->id,
                    'mahalla_id' => $mahalla1->id,
                    'street_id' => $street->id,
                    'lat' => 41.5900 + ($n * 0.0003),
                    'lng' => 60.7900 + ($n * 0.0002),
                    'address' => "Мустақиллик кўчаси, {$n}-уй",
                    'owner_name' => "Уй эгаси {$n}",
                    'status' => 'not_started',
                    'progress_percent' => 0,
                ],
            );
        }
    }
}
