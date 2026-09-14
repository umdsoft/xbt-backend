<?php

declare(strict_types=1);

namespace Tests\Feature\Mahalla;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `/api/mahalla/mahalla-points` — turgan tumanning FAOL, markazi bor
 * mahallalari (xarita qatlami + marshrut manzillari uchun).
 *
 * Bu endpoint `executive` prefiksi TASHQARISIDA turadi (`mahalla.viewer`
 * middleware'i yo'q) — xaritani deputat ham, rahbar ham ochadi, shuning
 * uchun qamrov invarianti `NearbyController::index` dan so'zma-so'z
 * takrorlanadi va shu yerda ham tekshiriladi.
 *
 * Foydalanuvchi qurish uchun `NearbyApiTest::insertDeputat()` naqshi
 * ishlatiladi (uch sxemaga: auth.users, auth.user_system_access,
 * mahalla.users) — `ExecutiveDashboardTest::makeUser()` EMAS, chunki u
 * faqat auth sxemasiga yozadi va mahalla.users profil satrini hech qachon
 * yaratmaydi, ya'ni haqiqiy tuman-scoped (district_id to'ldirilgan) user
 * bera olmaydi (batafsil: task-B4-report.md).
 */
class MahallaPointsApiTest extends TestCase
{
    use DatabaseTransactions;

    /** Har ulanish alohida tranzaksiyada — test oxirida hammasi qaytariladi. */
    protected array $connectionsToTransact = ['pgsql', 'auth', 'master', 'mahalla'];

    public function test_returns_all_mahallas_of_the_district_the_point_is_in(): void
    {
        $user = $this->makeAdminUser();
        [$lat, $lng] = $this->shovotPoint();

        $res = $this->actingAs($user, 'sanctum')
            ->getJson("/api/mahalla/mahalla-points?lat={$lat}&lng={$lng}")
            ->assertOk()
            ->assertJsonStructure([
                'district' => ['id', 'name'],
                'points' => [['id', 'name', 'lat', 'lng']],
                'count',
            ]);

        // QOTIRILGAN SON YO'Q: baza o'zi aytadi nechta bo'lishi kerakligini.
        $districtId = $res->json('district.id');
        $expected = DB::connection('master')->table('mahallas')
            ->where('district_id', $districtId)
            ->where('is_active', true)
            ->whereNotNull('center_lat')->whereNotNull('center_lng')
            ->count();

        $this->assertSame($expected, $res->json('count'));
        $this->assertCount($expected, $res->json('points'));
        $this->assertGreaterThan(0, $expected, 'test ma\'nosiz bo\'lmasligi uchun');
        $this->assertSame(
            $this->shovotDistrictId(),
            $districtId,
            'ST_PointOnSurface bilan olingan nuqta Shovot chegarasi ichida bo\'lishi shart',
        );
    }

    /**
     * `tuman` roli: BOSHQA tumandagi koordinata bilan so'ralsa ham O'Z
     * tumanini oladi (standart tumanga TUSHMAYDI, so'ralgan koordinata
     * tumaniga ham OCHILMAYDI).
     */
    public function test_district_scoped_user_gets_own_district_regardless_of_coordinate(): void
    {
        $districtId = $this->shovotDistrictId();
        $user = $this->makeScopedUser('tuman', $districtId, null);

        [$otherLat, $otherLng] = $this->otherDistrictPoint($districtId);

        $res = $this->actingAs($user, 'sanctum')
            ->getJson("/api/mahalla/mahalla-points?lat={$otherLat}&lng={$otherLng}")
            ->assertOk();

        $this->assertSame(
            $districtId,
            $res->json('district.id'),
            'tuman-scoped user o\'z tumanini olishi kerak, so\'ralgan koordinata boshqa tumanda bo\'lsa ham',
        );

        $expected = DB::connection('master')->table('mahallas')
            ->where('district_id', $districtId)
            ->where('is_active', true)
            ->whereNotNull('center_lat')->whereNotNull('center_lng')
            ->count();

        $this->assertSame($expected, $res->json('count'));
        $this->assertCount($expected, $res->json('points'));
    }

    /**
     * canSeeAll=false, districtId=null (profili to'liq bo'lmagan operatsion
     * user) — bo'sh ro'yxat, standart (Shovot) tumanga TUSHMAYDI.
     */
    public function test_user_without_scope_gets_empty_list_not_default_district(): void
    {
        $user = $this->makeScopedUser('deputat', null, null);
        [$lat, $lng] = $this->shovotPoint();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/mahalla/mahalla-points?lat={$lat}&lng={$lng}")
            ->assertOk()
            ->assertExactJson(['district' => null, 'points' => [], 'count' => 0]);
    }

    /**
     * `center_lat`/`center_lng` NULL bo'lgan mahalla javobda YO'Q (xaritada
     * (0,0) — Gvineya ko'rfazida — paydo bo'lmasin). Haqiqiy bazada bunday
     * mahalla yo'q (509 tasining hammasida to'ldirilgan — o'lchandi), shuning
     * uchun bitta vaqtinchalik qator qo'shib isbotlanadi; DatabaseTransactions
     * uni test oxirida qaytaradi.
     */
    public function test_mahallas_without_centre_are_excluded(): void
    {
        $districtId = $this->shovotDistrictId();
        $user = $this->makeAdminUser();
        [$lat, $lng] = $this->shovotPoint();

        $before = $this->actingAs($user, 'sanctum')
            ->getJson("/api/mahalla/mahalla-points?lat={$lat}&lng={$lng}")
            ->assertOk()->json();

        $noCentreId = $this->insertTestMahalla($districtId, null, null, true);

        $after = $this->actingAs($user, 'sanctum')
            ->getJson("/api/mahalla/mahalla-points?lat={$lat}&lng={$lng}")
            ->assertOk()->json();

        $this->assertSame($before['count'], $after['count'], 'markazsiz mahalla sonni oshirmasligi kerak');
        $this->assertNotContains(
            $noCentreId,
            collect($after['points'])->pluck('id')->all(),
            'markazsiz mahalla ro\'yxatda ko\'rinmasligi kerak',
        );
    }

    /**
     * Nofaol (`is_active = false`) mahalla — markazi bo'lsa ham javobda YO'Q.
     * Haqiqiy bazada Shovotda nofaol mahalla yo'q (o'lchandi: butun bazada
     * 0 ta), shuning uchun vaqtinchalik qator bilan isbotlanadi. Bu test
     * `->where('is_active', true)` mutatsiya isbotining nazorat nuqtasi.
     */
    public function test_inactive_mahallas_are_excluded(): void
    {
        $districtId = $this->shovotDistrictId();
        $user = $this->makeAdminUser();
        [$lat, $lng] = $this->shovotPoint();

        $before = $this->actingAs($user, 'sanctum')
            ->getJson("/api/mahalla/mahalla-points?lat={$lat}&lng={$lng}")
            ->assertOk()->json();

        $inactiveId = $this->insertTestMahalla($districtId, 41.65, 60.27, false);

        $after = $this->actingAs($user, 'sanctum')
            ->getJson("/api/mahalla/mahalla-points?lat={$lat}&lng={$lng}")
            ->assertOk()->json();

        $this->assertSame($before['count'], $after['count'], 'nofaol mahalla sonni oshirmasligi kerak');
        $this->assertNotContains(
            $inactiveId,
            collect($after['points'])->pluck('id')->all(),
            'nofaol mahalla ro\'yxatda ko\'rinmasligi kerak',
        );
    }

    public function test_requires_lat_lng(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/mahalla-points')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lat', 'lng']);
    }

    // ---------------------------------------------------------------- yordamchi

    /** Shovot tumani (SOATO 1733230) — kadastr yuklanmagan bo'lsa test o'tkazib yuboriladi. */
    private function shovotDistrictId(): string
    {
        $id = DB::connection('master')->table('districts')
            ->where('soato_code', '1733230')->value('id');

        if ($id === null) {
            $this->markTestSkipped('Shovot kadastr ma\'lumoti yuklanmagan.');
        }

        return (string) $id;
    }

    /**
     * Berilgan tuman chegarasi ICHIDA KAFOLATLANGAN nuqta — `ST_PointOnSurface`
     * har doim poligon ichidagi (chegarada emas) nuqta qaytaradi, shuning
     * uchun bironta ham qattiq kodlangan koordinataga bog'lanmaydi.
     *
     * @return array{0: float, 1: float} [lat, lng]
     */
    private function districtInteriorPoint(string $districtId): array
    {
        $row = DB::connection('master')->selectOne(
            'SELECT ST_Y(ST_PointOnSurface(boundary)) AS lat, ST_X(ST_PointOnSurface(boundary)) AS lng
             FROM master.districts WHERE id = :id AND boundary IS NOT NULL',
            ['id' => $districtId],
        );

        if ($row === null) {
            $this->markTestSkipped('Tuman chegarasi (boundary) topilmadi.');
        }

        return [(float) $row->lat, (float) $row->lng];
    }

    /** @return array{0: float, 1: float} [lat, lng] Shovot ichida. */
    private function shovotPoint(): array
    {
        return $this->districtInteriorPoint($this->shovotDistrictId());
    }

    /** @return array{0: float, 1: float} [lat, lng] Shovotdan BOSHQA, chegarasi bor tuman ichida. */
    private function otherDistrictPoint(string $excludeDistrictId): array
    {
        $id = DB::connection('master')->table('districts')
            ->where('id', '!=', $excludeDistrictId)
            ->whereNotNull('boundary')
            ->value('id');

        if ($id === null) {
            $this->markTestSkipped('Shovotdan boshqa chegarali tuman topilmadi.');
        }

        return $this->districtInteriorPoint((string) $id);
    }

    private function makeAdminUser(): User
    {
        return $this->makeScopedUser('admin', null, null);
    }

    /**
     * Uch sxemaga (auth.users, auth.user_system_access, mahalla.users) user
     * yozadi — naqsh `NearbyApiTest::insertDeputat()` dan.
     */
    private function makeScopedUser(string $role, ?string $districtId, ?string $mahallaId): User
    {
        $userId = (string) Str::uuid();
        $now = now();

        DB::connection('auth')->table('users')->insert([
            'id' => $userId,
            'login' => 'mpt_'.substr($userId, 0, 8),
            'password' => bcrypt('secret'),
            'name' => 'ТЕСТ фойдаланувчи',
            'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        DB::connection('auth')->table('user_system_access')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'system_id' => DB::connection('auth')->table('systems')->where('code', 'mahalla')->value('id'),
            'role' => $role,
            'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        DB::connection('mahalla')->table('users')->insert([
            'id' => $userId,
            'name' => 'ТЕСТ фойдаланувчи',
            'login' => 'mpt_'.substr($userId, 0, 8),
            'password' => bcrypt('secret'),
            'district_id' => $districtId,
            'mahalla_id' => $mahallaId,
            'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return User::on('auth')->findOrFail($userId);
    }

    /**
     * Vaqtinchalik `master.mahallas` qatori — `DatabaseTransactions` test
     * oxirida qaytaradi, dev bazada iz qolmaydi.
     */
    private function insertTestMahalla(string $districtId, ?float $centerLat, ?float $centerLng, bool $isActive): string
    {
        $id = (string) Str::uuid();

        DB::connection('master')->table('mahallas')->insert([
            'id' => $id,
            'district_id' => $districtId,
            'name_cyr' => 'ТЕСТ МФЙ',
            'name_lat' => 'TEST MFY',
            'center_lat' => $centerLat,
            'center_lng' => $centerLng,
            'sort_order' => 0,
            'is_active' => $isActive,
            'soato_code' => 'T'.substr(str_replace('-', '', $id), 0, 15),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
