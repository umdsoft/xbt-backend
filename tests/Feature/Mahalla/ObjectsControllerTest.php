<?php

declare(strict_types=1);

namespace Tests\Feature\Mahalla;

use App\Domains\Mahalla\Support\BuildingNameCleaner;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `GET /api/mahalla/executive/districts/{district}/objects` — tuman ichidagi
 * BARCHA turar-joy BO'LMAGAN obyektlar ro'yxati.
 *
 * NEGA KERAK: xaritadagi `/nearby` radius bo'yicha ishlaydi va 600 ta bilan
 * qattiq kesiladi — Shovotda 1000/3000/5000 metrda ham har doim aynan 600
 * qaytadi (`truncated: true`), ya'ni butun tumanni hech qachon ko'rsata
 * olmaydi. Hokim BARCHA binoni, ayniqsa BARCHA MFY binolarini ko'rishni
 * so'ragan — bu radius bilan yechilmaydigan talab.
 *
 * `?mahalla=` xavfsizligi bo'yicha testlar (422/qamrov qisqa tutashuvi)
 * `SocialObjectsControllerTest`dan so'zma-so'z ko'chirilgan — ikkala
 * kontroller BIR XIL himoya qatlamini takrorlaydi (qarang: ObjectsController
 * izohi). `tuman` roli boshqa tumanni so'rasa 403 bo'lishi allaqachon
 * `TumanViewerScopeTest::districtEndpointProvider()`ga qo'shilgan
 * ('objects' qatori) — shu yerda takrorlanmaydi.
 */
class ObjectsControllerTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, string> */
    protected array $connectionsToTransact = ['pgsql', 'auth', 'master', 'mahalla'];

    /**
     * ASOSIY TEST. QOTIRILGAN SON YO'Q — baza o'zi aytadi nechta bo'lishi
     * kerakligini.
     *
     * DIQQAT: bu test `b.type = 'non_residential'` filtrini QO'LFLAMAYDI —
     * qo'lda tekshirilgan: bazada HECH BIR turar-joy binosi `object_type_id`
     * ga ega emas (372 957 tadan 0 tasi), shuning uchun `object_types`ga
     * INNER JOIN o'zi ham ularni chetlab o'tadi va filtr olib tashlansa ham
     * bu testning soni o'zgarmaydi. Filtrning haqiqiy mutatsiya isboti —
     * pastdagi `test_residential_building_is_excluded_even_if_it_has_an_object_type()`.
     */
    public function test_returns_every_non_residential_object_of_the_district(): void
    {
        $own = $this->districtId();
        $user = $this->makeUser('viloyat');

        $expected = (int) DB::connection('master')->table('buildings')
            ->where('district_id', $own)
            ->where('type', 'non_residential')
            ->count();
        $this->assertGreaterThan(0, $expected, 'test ma\'nosiz bo\'lmasligi uchun');

        $res = $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/districts/'.$own.'/objects')
            ->assertOk()
            ->assertJsonStructure([
                'total',
                'types' => [['code', 'name', 'count']],
                'objects' => [[
                    'id', 'type_code', 'type_name', 'mahalla',
                    'address', 'purpose', 'name', 'lat', 'lng', 'is_social',
                ]],
            ]);

        $this->assertSame($expected, $res->json('total'));
        $this->assertCount($expected, $res->json('objects'));
    }

    /**
     * Har obyekt aynan bitta holatda: ijtimoiy (`is_social: true`) yoki
     * emas. Ijtimoiylarning soni `object_types.is_social` dan mustaqil
     * hisoblanadi va javobdagi bayroqqa solishtiriladi.
     */
    public function test_social_objects_are_flagged_true_and_others_false(): void
    {
        $own = $this->districtId();
        $user = $this->makeUser('viloyat');

        $expectedSocial = (int) DB::connection('master')->table('buildings as b')
            ->join('object_types as t', 't.id', '=', 'b.object_type_id')
            ->where('b.district_id', $own)
            ->where('b.type', 'non_residential')
            ->where('t.is_social', true)
            ->count();
        $this->assertGreaterThan(0, $expectedSocial, 'Shovotda ijtimoiy obyekt bo\'lishi kerak (113 ta o\'lchandi)');

        $objects = collect(
            $this->actingAs($user, 'sanctum')
                ->getJson('/api/mahalla/executive/districts/'.$own.'/objects')
                ->assertOk()
                ->json('objects')
        );

        $this->assertSame($expectedSocial, $objects->where('is_social', true)->count());
        $this->assertSame(
            $objects->count(),
            $objects->where('is_social', true)->count() + $objects->where('is_social', false)->count(),
            'har obyekt is_social bo\'yicha aynan bitta guruhga tushishi kerak (true/false, boshqa qiymat yo\'q)'
        );
    }

    /**
     * Shovotda 11 ta MFY (mahalla gузар) binosi — hokimning asosiy so'ravi
     * shu edi ("barcha MFY binolarini ko'raman"). Son bazadan olinadi.
     */
    public function test_all_mfy_buildings_are_present(): void
    {
        $own = $this->districtId();
        $user = $this->makeUser('viloyat');

        $expectedMfy = (int) DB::connection('master')->table('buildings as b')
            ->join('object_types as t', 't.id', '=', 'b.object_type_id')
            ->where('b.district_id', $own)
            ->where('t.code', 'mfy_binosi')
            ->count();
        $this->assertGreaterThan(0, $expectedMfy, 'Shovotda MFY binolari bo\'lishi kerak (11 ta o\'lchandi)');

        $objects = collect(
            $this->actingAs($user, 'sanctum')
                ->getJson('/api/mahalla/executive/districts/'.$own.'/objects')
                ->assertOk()
                ->json('objects')
        );

        $this->assertSame($expectedMfy, $objects->where('type_code', 'mfy_binosi')->count());
        // MFY binolari ijtimoiy toifaga kiradi (seed: is_social=true) — shu
        // yerda ham tekshiriladi, chunki aynan shu bayroq mijozga kerak.
        $this->assertTrue(
            $objects->where('type_code', 'mfy_binosi')->every(fn (array $o) => $o['is_social'] === true),
            'MFY binolari is_social=true bo\'lishi kerak'
        );
    }

    public function test_mahalla_filter_narrows_to_that_mahalla(): void
    {
        $own = $this->districtId();
        $user = $this->makeUser('viloyat');

        $mahallaId = DB::connection('master')->table('buildings')
            ->where('district_id', $own)
            ->where('type', 'non_residential')
            ->whereNotNull('mahalla_id')
            ->value('mahalla_id');
        $this->assertNotNull($mahallaId, 'kamida bitta obyektga biriktirilgan mahalla kerak');

        $expected = (int) DB::connection('master')->table('buildings')
            ->where('district_id', $own)
            ->where('type', 'non_residential')
            ->where('mahalla_id', $mahallaId)
            ->count();
        $this->assertGreaterThan(0, $expected);
        $this->assertLessThan(
            (int) DB::connection('master')->table('buildings')
                ->where('district_id', $own)->where('type', 'non_residential')->count(),
            $expected,
            'test ma\'nosiz bo\'lmasligi uchun mahalla butun tumandan kichik bo\'lishi kerak'
        );

        $res = $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/districts/'.$own.'/objects?mahalla='.$mahallaId)
            ->assertOk();

        $this->assertSame($expected, $res->json('total'));
        $this->assertCount($expected, $res->json('objects'));
        foreach ($res->json('objects') as $o) {
            $this->assertSame((string) $mahallaId, $o['mahalla']['id']);
        }
    }

    /**
     * Boshqa tumanning mahallasi so'ralsa bo'sh ro'yxat — ARALASHIB
     * KETGAN ma'lumot emas (`SocialObjectsController`dagi bilan bir xil
     * himoya, qarang shu klass izohi).
     */
    public function test_mahalla_from_another_district_returns_empty_not_leak(): void
    {
        $own = $this->districtId();
        $other = $this->anotherDistrictId($own);
        $user = $this->makeUser('viloyat');

        $foreign = DB::connection('master')->table('mahallas')
            ->where('district_id', $other)->value('id');
        $this->assertNotNull($foreign, 'Boshqa tumanda mahalla bo\'lishi kerak');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/districts/'.$own.'/objects?mahalla='.$foreign)
            ->assertOk()
            ->assertExactJson(['total' => 0, 'types' => [], 'objects' => []]);
    }

    /**
     * MUHIM ISBOT TESTI (SocialObjectsControllerTest'dagi bilan bir xil
     * naqsh). Agar kimdir kontrollerdagi `$belongs` blokini olib tashlasa,
     * bu test YIQILADI — servis begona mahalla id'si bilan chaqirilib,
     * `buildings` jadvaliga so'rov yuboradi.
     */
    public function test_belongs_check_short_circuits_before_computing_stats_for_foreign_mahalla(): void
    {
        $own = $this->districtId();
        $other = $this->anotherDistrictId($own);
        $user = $this->makeUser('viloyat');

        $foreign = DB::connection('master')->table('mahallas')
            ->where('district_id', $other)->value('id');
        $this->assertNotNull($foreign, 'Boshqa tumanda mahalla bo\'lishi kerak');

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if ($query->connectionName === 'master') {
                $queries[] = $query->sql;
            }
        });

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/districts/'.$own.'/objects?mahalla='.$foreign)
            ->assertOk()
            ->assertExactJson(['total' => 0, 'types' => [], 'objects' => []]);

        $touchedBuildings = collect($queries)->contains(
            fn (string $sql) => str_contains($sql, 'buildings')
        );
        $this->assertFalse(
            $touchedBuildings,
            'Tekshiruv ERTAROQ to\'xtatishi kerak edi — "buildings" jadvaliga so\'rov borgan.'
        );
    }

    public function test_non_uuid_mahalla_query_param_returns_422_not_500(): void
    {
        $user = $this->makeUser('viloyat');
        $own = $this->districtId();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/districts/'.$own.'/objects?mahalla=x')
            ->assertStatus(422)
            ->assertJsonValidationErrors('mahalla');
    }

    public function test_array_mahalla_query_param_returns_422_not_500(): void
    {
        $user = $this->makeUser('viloyat');
        $own = $this->districtId();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/districts/'.$own.'/objects?mahalla[]=1')
            ->assertStatus(422)
            ->assertJsonValidationErrors('mahalla');
    }

    /**
     * Ismlar `BuildingNameCleaner` orqali TOZALANGAN holda kelishi kerak
     * (qarang: /nearby, ExecutiveMahallaStats::mfyBuilding — bir xil qoida).
     * Haqiqiy Shovot kadastrida chetlarida tirnoq/bo'shliq bor `purpose`
     * qatorlari mavjud (o'lchandi); shundaylaridan biri tanlanadi — aks
     * holda tozalash chaqirilmasa ham test "tasodifan" o'tib ketardi
     * (tozalashdan keyin ham oldin ham bir xil bo'lgan qatorda).
     */
    public function test_names_come_through_cleaned_via_building_name_cleaner(): void
    {
        $own = $this->districtId();
        $user = $this->makeUser('viloyat');

        // Chetida tirnoq yoki bo'shliq bor — ya'ni tozalash NATIJANI
        // o'zgartiradigan qator. Regex `BuildingNameCleaner::EDGE_PATTERN`ga
        // mos (qarang: shu klass).
        $row = DB::connection('master')->table('buildings')
            ->where('district_id', $own)
            ->where('type', 'non_residential')
            ->whereNotNull('purpose')
            ->whereRaw('purpose ~ \'^[\\s"«»\'\']\' OR purpose ~ \'[\\s"«»\'\']$\'')
            ->first(['id', 'purpose']);

        if ($row === null) {
            $this->markTestSkipped('Tozalashni farqlaydigan (chetida tirnoq/bo\'shliq bor) purpose qatori topilmadi.');
        }

        $cleaned = BuildingNameCleaner::clean($row->purpose);
        $this->assertNotSame($row->purpose, $cleaned, 'test ma\'nosiz bo\'lmasligi uchun tozalash qiymatni o\'zgartirishi kerak');

        $objects = collect(
            $this->actingAs($user, 'sanctum')
                ->getJson('/api/mahalla/executive/districts/'.$own.'/objects')
                ->assertOk()
                ->json('objects')
        );

        $match = $objects->firstWhere('id', $row->id);
        $this->assertNotNull($match, 'tanlangan bino javobda bo\'lishi kerak');
        // `purpose` XOM qoladi — `socialObjects()` va `/nearby` bilan BIR XIL
        // ma'no: tur noto'g'ri tasniflangan bo'lsa kadastrdagi asl yozuv
        // ko'rinib tursin va tuzatish mumkin bo'lsin. Tozalangan ko'rsatish
        // ismi ALOHIDA `name` maydonida keladi (aynan `/nearby` dagi juftlik).
        $this->assertSame($row->purpose, $match['purpose'], 'purpose XOM kelishi kerak');
        $this->assertSame($cleaned, $match['name'], 'name TOZALANGAN kelishi kerak');
    }

    /**
     * MUTATSIYA QATLAMI (haqiqiy isbot). `object_types`ga INNER JOIN o'zi
     * ham deyarli barcha turar-joy binolarini chetlab o'tadi — bazada HECH
     * BIR turar-joy binosi `object_type_id`ga ega emas (o'lchandi: 372 957
     * tadan 0 tasi). Shuning uchun `b.type = 'non_residential'` filtri
     * BUGUNGI HAQIQIY bazada "sukut bo'yicha" ortiqcha ko'rinadi — uni olib
     * tashlash mavjud qatorlar bilan natijani o'zgartirmaydi.
     *
     * Lekin filtr KELAJAK uchun himoya qatlami: import quvuri xato bilan
     * turar-joy binosini ham klassifikatsiya qilib qo'ysa, `type` filtrisiz
     * u ro'yxatga suzib chiqadi. Shu holatni TAQLID qiluvchi vaqtinchalik
     * qator bilan (DatabaseTransactions — test oxirida qaytariladi) bu
     * himoyani qulflaymiz: `ExecutiveStats::buildObjects()`dagi
     * `->where('b.type', 'non_residential')` olib tashlansa, bu test
     * YIQILADI (qo'lda tekshirilgan, qarang: task-B5-report.md).
     */
    public function test_residential_building_is_excluded_even_if_it_has_an_object_type(): void
    {
        $own = $this->districtId();
        $user = $this->makeUser('viloyat');

        $sample = DB::connection('master')->table('buildings')
            ->where('district_id', $own)->where('type', 'non_residential')
            ->whereNotNull('lat')->whereNotNull('lng')
            ->first(['lat', 'lng']);
        $this->assertNotNull($sample, 'namuna koordinata uchun kamida bitta obyekt kerak');

        $objectTypeId = DB::connection('master')->table('object_types')
            ->where('code', 'savdo')->value('id');
        $this->assertNotNull($objectTypeId);

        $fakeId = (string) Str::uuid();
        DB::connection('master')->insert(
            "INSERT INTO master.buildings
                (id, type, geom, lat, lng, district_id, object_type_id, purpose, created_at, updated_at)
             VALUES
                (:id, 'residential', ST_SetSRID(ST_MakePoint(:lng, :lat), 4326), :lat, :lng,
                 :district_id, :object_type_id, 'MUTATSIYA SINOVI — soxta qator', now(), now())",
            [
                'id' => $fakeId,
                'lat' => $sample->lat,
                'lng' => $sample->lng,
                'district_id' => $own,
                'object_type_id' => $objectTypeId,
            ],
        );

        $res = $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/districts/'.$own.'/objects')
            ->assertOk();

        $ids = collect($res->json('objects'))->pluck('id')->all();
        $this->assertNotContains(
            $fakeId,
            $ids,
            'turar-joy binosi (klassifikatsiya qilingan bo\'lsa ham) ro\'yxatda chiqmasligi kerak'
        );
    }

    // ---------------------------------------------------------------- yordamchi

    /** Shovot tumani (SOATO 1733230) — kadastr yuklanmagan bo'lsa test o'tkazib yuboriladi. */
    protected function districtId(): string
    {
        $id = DB::connection('master')->table('districts')
            ->where('soato_code', '1733230')->value('id');

        if ($id === null) {
            $this->markTestSkipped('Shovot kadastr ma\'lumoti yuklanmagan.');
        }

        return (string) $id;
    }

    protected function anotherDistrictId(string $exclude): string
    {
        $id = DB::connection('master')->table('districts')
            ->where('id', '!=', $exclude)->orderBy('sort_order')->value('id');

        $this->assertNotNull($id, 'Ikkinchi tuman bazada bo\'lishi kerak');

        return (string) $id;
    }

    /** Markaziy auth'da user + mahalla tizimiga rol yaratadi. */
    protected function makeUser(string $role): User
    {
        $userId = (string) Str::uuid();
        $now = now();

        DB::connection('auth')->table('users')->insert([
            'id' => $userId, 'login' => 'test_'.substr($userId, 0, 8),
            'password' => bcrypt('secret'), 'name' => 'ТЕСТ фойдаланувчи',
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $systemId = DB::connection('auth')->table('systems')
            ->where('code', 'mahalla')->value('id');

        DB::connection('auth')->table('user_system_access')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $userId, 'system_id' => $systemId, 'role' => $role,
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return User::on('auth')->findOrFail($userId);
    }
}
