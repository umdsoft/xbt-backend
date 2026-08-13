<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Database\Seeders;

use App\Domains\Qurilish\Models\Organization;
use App\Domains\Qurilish\Models\Program;
use App\Domains\Qurilish\Models\Sector;
use Illuminate\Database\Seeder;

/**
 * Qurilish domeni spravochniklari: 8 davlat dasturi, 20 soha, 12 boshqarma.
 *
 * Idempotent: `code` (dastur/soha) va `name_cyr` (tashkilot) bo'yicha
 * firstOrCreate — qayta ishga tushirish no-op.
 *
 * DIQQAT: boshqarma nomlari BOSHLANG'ICH (spec 13.1-ochiq masala) — real
 * Xorazm viloyati boshqarma nomlari bilan keyin almashtiriladi. Soha->boshqarma
 * bog'lanishi import vaqtida `objects.department_org_id` ni to'ldiradi, чунки
 * manba xlsx'da boshqarma ustuni umuman yo'q.
 */
class QurilishReferenceSeeder extends Seeder
{
    /** @var array<int, array{code: string, cyr: string, lat: string, basis: ?string}> */
    private const PROGRAMS = [
        ['code' => 'pq393', 'cyr' => 'ПҚ-393-сонли Қарор', 'lat' => 'PQ-393-sonli Qaror', 'basis' => 'ПҚ-393'],
        ['code' => 'drayver', 'cyr' => '«Драйвер лойиҳалар» (1-босқич)', 'lat' => '«Drayver loyihalar» (1-bosqich)', 'basis' => null],
        ['code' => 'open', 'cyr' => '«Ташаббусли бюджет» (1-мавсум)', 'lat' => '«Tashabbusli byudjet» (1-mavsum)', 'basis' => null],
        ['code' => 'ogir_tuman', 'cyr' => '«Оғир» туманлар Дастури', 'lat' => '«Ogʻir» tumanlar Dasturi', 'basis' => 'ПҚ-298'],
        ['code' => 'ogir_mfy', 'cyr' => '«Оғир» маҳаллалар Дастури', 'lat' => '«Ogʻir» mahallalar Dasturi', 'basis' => 'ПҚ-298'],
        ['code' => 'yangi_uzb_tuman', 'cyr' => '«Янги Ўзбекистон қиёфасидаги туман»', 'lat' => '«Yangi Oʻzbekiston qiyofasidagi tuman»', 'basis' => 'ПҚ-298'],
        ['code' => 'yangi_uzb_mfy', 'cyr' => '«Янги Ўзбекистон қиёфасидаги маҳалла»', 'lat' => '«Yangi Oʻzbekiston qiyofasidagi mahalla»', 'basis' => 'ПҚ-298'],
        ['code' => 'dxsh', 'cyr' => 'Тадбиркорлар маблағлари ҳисобидан ДХШ', 'lat' => 'Tadbirkorlar mablagʻlari hisobidan DXSh', 'basis' => null],
    ];

    /** @var array<int, array{code: string, cyr: string, lat: string, dept: ?string}> */
    private const SECTORS = [
        ['code' => 'umumtalim_maktab', 'cyr' => 'Умумтаълим мактаблари', 'lat' => 'Umumtaʼlim maktablari', 'dept' => 'talim'],
        ['code' => 'mtt', 'cyr' => 'Мактабгача таълим ташкилотлари', 'lat' => 'Maktabgacha taʼlim tashkilotlari', 'dept' => 'talim'],
        ['code' => 'ijod_maktab', 'cyr' => 'Ижод ва ихтисослаштирилган мактаблар', 'lat' => 'Ijod va ixtisoslashtirilgan maktablar', 'dept' => 'talim'],
        ['code' => 'sogliqni_saqlash', 'cyr' => 'Соғлиқни сақлаш ва тиббий-ижтимоий муассасалар', 'lat' => 'Sogʻliqni saqlash va tibbiy-ijtimoiy muassasalar', 'dept' => 'sogliq'],
        ['code' => 'sport', 'cyr' => 'Спортни ривожлантириш объектлари', 'lat' => 'Sportni rivojlantirish obyektlari', 'dept' => 'sport'],
        ['code' => 'madaniyat', 'cyr' => 'Маданият ва санъат', 'lat' => 'Madaniyat va sanʼat', 'dept' => 'madaniyat'],
        ['code' => 'turizm', 'cyr' => 'Туризм инфратузилмаси объектлари', 'lat' => 'Turizm infratuzilmasi obyektlari', 'dept' => 'turizm'],
        ['code' => 'madaniy_meros', 'cyr' => 'Маданий мерос', 'lat' => 'Madaniy meros', 'dept' => 'meros'],
        ['code' => 'oliy_talim', 'cyr' => 'Олий таълим муассасалари', 'lat' => 'Oliy taʼlim muassasalari', 'dept' => null],
        ['code' => 'suv_kanalizatsiya', 'cyr' => 'Сув таъминоти ва канализация', 'lat' => 'Suv taʼminoti va kanalizatsiya', 'dept' => 'quykx'],
        ['code' => 'issiqlik', 'cyr' => 'Иссиқлик таъминоти', 'lat' => 'Issiqlik taʼminoti', 'dept' => 'quykx'],
        ['code' => 'avtoyol', 'cyr' => 'Автомобиль йўллари ва кўприклар', 'lat' => 'Avtomobil yoʻllari va koʻpriklar', 'dept' => 'yol'],
        ['code' => 'ichki_yol', 'cyr' => 'Ички йўллар ва кўчалар', 'lat' => 'Ichki yoʻllar va koʻchalar', 'dept' => 'yol'],
        ['code' => 'irrigatsiya', 'cyr' => 'Ирригация тармоқлари ва иншоотлари', 'lat' => 'Irrigatsiya tarmoqlari va inshootlari', 'dept' => 'suv'],
        ['code' => 'melioratsiya', 'cyr' => 'Мелиорация тармоқлари ва иншоотлари', 'lat' => 'Melioratsiya tarmoqlari va inshootlari', 'dept' => 'suv'],
        ['code' => 'ormon', 'cyr' => 'Ўрмон хўжалиги объектлари', 'lat' => 'Oʻrmon xoʻjaligi obyektlari', 'dept' => 'ormon'],
        ['code' => 'mudofaa_huquq', 'cyr' => 'Мудофаа ва ҳуқуқни муҳофаза қилувчи органлар', 'lat' => 'Mudofaa va huquqni muhofaza qiluvchi organlar', 'dept' => null],
        ['code' => 'elektr', 'cyr' => 'Электр таъминоти объектлари', 'lat' => 'Elektr taʼminoti obyektlari', 'dept' => 'elektr'],
        ['code' => 'maxsus_zona', 'cyr' => 'Махсус иқтисодий зоналар', 'lat' => 'Maxsus iqtisodiy zonalar', 'dept' => 'investitsiya'],
        ['code' => 'boshqa', 'cyr' => 'Ободонлаштириш ва бошқа', 'lat' => 'Obodonlashtirish va boshqa', 'dept' => null],
    ];

    /** @var array<string, array{cyr: string, lat: string}> */
    private const DEPARTMENTS = [
        'talim' => ['cyr' => 'Мактабгача ва мактаб таълими бошқармаси', 'lat' => 'Maktabgacha va maktab taʼlimi boshqarmasi'],
        'sogliq' => ['cyr' => 'Соғлиқни сақлаш бошқармаси', 'lat' => 'Sogʻliqni saqlash boshqarmasi'],
        'sport' => ['cyr' => 'Жисмоний тарбия ва спорт бошқармаси', 'lat' => 'Jismoniy tarbiya va sport boshqarmasi'],
        'madaniyat' => ['cyr' => 'Маданият бошқармаси', 'lat' => 'Madaniyat boshqarmasi'],
        'turizm' => ['cyr' => 'Туризм бошқармаси', 'lat' => 'Turizm boshqarmasi'],
        'meros' => ['cyr' => 'Маданий мерос агентлиги', 'lat' => 'Madaniy meros agentligi'],
        'quykx' => ['cyr' => 'Қурилиш ва уй-жой коммунал хўжалиги бошқармаси', 'lat' => 'Qurilish va uy-joy kommunal xoʻjaligi boshqarmasi'],
        'yol' => ['cyr' => 'Автомобиль йўллари бошқармаси', 'lat' => 'Avtomobil yoʻllari boshqarmasi'],
        'suv' => ['cyr' => 'Сув хўжалиги бошқармаси', 'lat' => 'Suv xoʻjaligi boshqarmasi'],
        'ormon' => ['cyr' => 'Ўрмон хўжалиги бошқармаси', 'lat' => 'Oʻrmon xoʻjaligi boshqarmasi'],
        'elektr' => ['cyr' => '«Ҳудудий электр тармоқлари» АЖ', 'lat' => '«Hududiy elektr tarmoqlari» AJ'],
        'investitsiya' => ['cyr' => 'Инвестициялар ва ташқи савдо бошқармаси', 'lat' => 'Investitsiyalar va tashqi savdo boshqarmasi'],
    ];

    public function run(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        foreach (self::PROGRAMS as $i => $p) {
            Program::query()->firstOrCreate(
                ['code' => $p['code']],
                [
                    'name_cyr' => $p['cyr'],
                    'name_lat' => $p['lat'],
                    'legal_basis' => $p['basis'],
                    'year' => 2026,
                    'sort_order' => $i + 1,
                    'is_active' => true,
                ],
            );
        }

        // Boshqarmalar sohalardan OLDIN: sectors.default_department_org_id ularga ishora qiladi.
        $deptIds = [];
        foreach (self::DEPARTMENTS as $key => $d) {
            $deptIds[$key] = Organization::query()->firstOrCreate(
                ['name_cyr' => $d['cyr']],
                ['name_lat' => $d['lat'], 'is_department' => true, 'is_active' => true],
            )->id;
        }

        foreach (self::SECTORS as $i => $s) {
            Sector::query()->firstOrCreate(
                ['code' => $s['code']],
                [
                    'name_cyr' => $s['cyr'],
                    'name_lat' => $s['lat'],
                    'default_department_org_id' => $s['dept'] === null ? null : $deptIds[$s['dept']],
                    'sort_order' => $i + 1,
                    'is_active' => true,
                ],
            );
        }
    }
}
