<?php

declare(strict_types=1);

namespace Tests\Feature\Mahalla;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class NearbyApiTest extends TestCase
{
    use DatabaseTransactions;

    /** Ko'p sxemali: har ulanish alohida qaytarilishi kerak. */
    protected array $connectionsToTransact = ['pgsql', 'auth', 'master', 'mahalla'];

    public function test_nearby_returns_points_within_radius_sorted_by_distance(): void
    {
        [$user, $districtId] = $this->makeDeputatInPilotDistrict();
        [$lat, $lng] = $this->denseCenterIn($districtId);

        $body = $this->actingAs($user, 'sanctum')
            ->getJson("/api/mahalla/nearby?lat={$lat}&lng={$lng}&radius_m=1000&layers=monitoring,homes,orgs&limit=50")
            ->assertOk()
            ->assertJsonStructure([
                'center' => ['lat', 'lng'],
                'radius_m',
                'points' => [['id', 'lat', 'lng', 'distance_m', 'kind', 'type']],
            ])
            ->json();

        $this->assertSame(1000, $body['radius_m']);
        $this->assertNotEmpty($body['points'], 'Zich nuqtada 1km radiusda bino topilishi kerak.');
        $this->assertLessThanOrEqual(50, count($body['points']));

        // Hammasi radius ichida
        foreach ($body['points'] as $p) {
            $this->assertLessThanOrEqual(1000, $p['distance_m']);
        }

        // Masofa bo'yicha o'sib boradi
        $distances = array_column($body['points'], 'distance_m');
        $sorted = $distances;
        sort($sorted);
        $this->assertSame($sorted, $distances, 'Nuqtalar masofa bo\'yicha saralangan bo\'lishi kerak.');
    }

    public function test_deputat_cannot_read_another_district_by_moving_the_point(): void
    {
        [$user, $districtId] = $this->makeDeputatInPilotDistrict();

        $other = DB::connection('master')->table('buildings')
            ->whereNotNull('lat')->whereNotNull('lng')
            ->where('district_id', '!=', $districtId)
            ->whereNotNull('district_id')
            ->first(['lat', 'lng']);

        if ($other === null) {
            $this->markTestSkipped('Boshqa tumanda koordinatali bino yo\'q.');
        }

        $body = $this->actingAs($user, 'sanctum')
            ->getJson("/api/mahalla/nearby?lat={$other->lat}&lng={$other->lng}&radius_m=3000&layers=monitoring,homes,orgs&limit=50")
            ->assertOk()
            ->json();

        $this->assertSame([], $body['points'], 'Deputat o\'z tumanidan tashqaridagi binolarni ko\'rmasligi kerak.');
    }

    public function test_limit_caps_the_number_of_returned_points(): void
    {
        [$user, $districtId] = $this->makeDeputatInPilotDistrict();
        [$lat, $lng] = $this->denseCenterIn($districtId);

        $body = $this->actingAs($user, 'sanctum')
            ->getJson("/api/mahalla/nearby?lat={$lat}&lng={$lng}&radius_m=1000&layers=monitoring,homes,orgs&limit=5")
            ->assertOk()
            ->json();

        $this->assertCount(5, $body['points']);
    }

    public function test_user_without_district_scope_sees_nothing_even_with_valid_coordinates(): void
    {
        // Profili to'liq bo'lmagan operatsion user: districtId = null,
        // canSeeAll = false. ILGARI `?? districtIdForPoint()` unga ISTALGAN
        // tumanni ochardi — endi bo'sh ro'yxat qaytishi SHART.
        $user = $this->makeDeputatWithoutDistrict();

        $building = DB::connection('master')->table('buildings')
            ->whereNotNull('lat')->whereNotNull('lng')
            ->first(['lat', 'lng']);

        $this->assertNotNull($building, 'Koordinatali bino topilmadi.');

        $body = $this->actingAs($user, 'sanctum')
            ->getJson("/api/mahalla/nearby?lat={$building->lat}&lng={$building->lng}&radius_m=3000&layers=monitoring,homes,orgs&limit=50")
            ->assertOk()
            ->json();

        $this->assertSame([], $body['points'], 'Qamrovi aniqlanmagan user hech qanday bino ko\'rmasligi kerak.');
    }

    public function test_nearby_requires_authentication(): void
    {
        $this->getJson('/api/mahalla/nearby?lat=41.67&lng=60.24')
            ->assertUnauthorized();
    }

    public function test_nearby_rejects_missing_and_out_of_range_params(): void
    {
        [$user] = $this->makeDeputatInPilotDistrict();

        // lat/lng majburiy
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/nearby')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lat', 'lng']);

        // radius MAX 5000
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/nearby?lat=41.67&lng=60.24&radius_m=50000')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['radius_m']);

        // koordinata chegarasi
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/nearby?lat=999&lng=60.24')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lat']);
    }

    public function test_nearby_defaults_radius_to_3000(): void
    {
        [$user, $districtId] = $this->makeDeputatInPilotDistrict();
        [$lat, $lng] = $this->denseCenterIn($districtId);

        $body = $this->actingAs($user, 'sanctum')
            ->getJson("/api/mahalla/nearby?lat={$lat}&lng={$lng}&limit=5")
            ->assertOk()
            ->json();

        $this->assertSame(3000, $body['radius_m']);
    }

    public function test_orgs_layer_returns_only_non_residential_with_category(): void
    {
        [$user, $districtId] = $this->makeDeputatInPilotDistrict();
        [$lat, $lng] = $this->denseCenterIn($districtId);

        $body = $this->actingAs($user, 'sanctum')
            ->getJson("/api/mahalla/nearby?lat={$lat}&lng={$lng}&radius_m=3000&layers=orgs&limit=100")
            ->assertOk()
            ->assertJsonStructure([
                'points' => [['id', 'kind', 'type', 'is_social', 'category', 'category_label', 'address', 'kadastr', 'mahalla']],
            ])
            ->json();

        $this->assertNotEmpty($body['points'], '3km da tashkilot topilishi kerak (Shovot: 244 ta).');

        foreach ($body['points'] as $p) {
            $this->assertSame('non_residential', $p['type']);
            $this->assertSame('org', $p['kind']);
            $this->assertIsBool($p['is_social']);
        }
    }

    public function test_homes_layer_returns_only_unmonitored_residential(): void
    {
        [$user, $districtId] = $this->makeDeputatInPilotDistrict();
        [$lat, $lng] = $this->denseCenterIn($districtId);

        $body = $this->actingAs($user, 'sanctum')
            ->getJson("/api/mahalla/nearby?lat={$lat}&lng={$lng}&radius_m=1000&layers=homes&limit=100")
            ->assertOk()
            ->json();

        $this->assertNotEmpty($body['points']);
        foreach ($body['points'] as $p) {
            $this->assertSame('residential', $p['type']);
            $this->assertSame('home', $p['kind']);
        }
    }

    public function test_monitoring_layer_exposes_status_and_mine_flag(): void
    {
        [$user, $districtId] = $this->makeDeputatInPilotDistrict();

        // Real pilot-tuman binosini monitoring ostiga olamiz (dev bazadagi
        // yagona houses qatoriga tayanmaymiz — u boshqa tumanda bo'lishi mumkin).
        [$buildingId, $lat, $lng] = $this->monitorRealBuilding($districtId, 'in_progress');

        $body = $this->actingAs($user, 'sanctum')
            ->getJson("/api/mahalla/nearby?lat={$lat}&lng={$lng}&radius_m=500&layers=monitoring&limit=50")
            ->assertOk()
            ->assertJsonStructure([
                'points' => [['id', 'kind', 'monitored', 'overall_status', 'mine']],
            ])
            ->json();

        $point = collect($body['points'])->firstWhere('id', $buildingId);
        $this->assertNotNull($point, 'Monitoring qilingan bino natijalar orasida topilmadi.');
        $this->assertSame('monitoring', $point['kind']);
        $this->assertTrue($point['monitored']);
        $this->assertSame('in_progress', $point['overall_status']);
        $this->assertFalse($point['mine'], 'Ko\'cha biriktirilmagan deputat uchun mine=false bo\'lishi kerak.');
    }

    public function test_monitoring_layer_mine_flag_is_true_when_deputat_has_street_assignment(): void
    {
        [$user, $districtId] = $this->makeDeputatInPilotDistrict();

        [$buildingId, $lat, $lng, $streetId] = $this->monitorRealBuilding($districtId, 'completed');

        // Deputatni monitoring qilinayotgan binoning ko'chasiga biriktiramiz —
        // shundan keyingina `mine` true bo'lishi kerak.
        DB::connection('mahalla')->table('street_assignments')->insert([
            'id' => (string) Str::uuid(),
            'street_id' => $streetId,
            'user_id' => $user->id,
            'assigned_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $body = $this->actingAs($user, 'sanctum')
            ->getJson("/api/mahalla/nearby?lat={$lat}&lng={$lng}&radius_m=500&layers=monitoring&limit=50")
            ->assertOk()
            ->json();

        $point = collect($body['points'])->firstWhere('id', $buildingId);
        $this->assertNotNull($point, 'Monitoring qilingan bino natijalar orasida topilmadi.');
        $this->assertSame('monitoring', $point['kind']);
        $this->assertSame('completed', $point['overall_status']);
        $this->assertTrue($point['mine'], 'Ko\'chasi biriktirilgan deputat uchun mine=true bo\'lishi kerak.');
    }

    public function test_response_includes_current_mahalla_and_counts(): void
    {
        [$user, $districtId] = $this->makeDeputatInPilotDistrict();
        [$lat, $lng] = $this->denseCenterIn($districtId);

        $body = $this->actingAs($user, 'sanctum')
            ->getJson("/api/mahalla/nearby?lat={$lat}&lng={$lng}&radius_m=1000&layers=monitoring,homes,orgs&limit=25")
            ->assertOk()
            ->assertJsonStructure([
                'current_mahalla',
                'counts' => ['monitoring', 'home', 'org', 'returned', 'truncated'],
            ])
            ->json();

        $this->assertSame(count($body['points']), $body['counts']['returned']);
        $this->assertSame(
            $body['counts']['monitoring'] + $body['counts']['home'] + $body['counts']['org'],
            $body['counts']['returned'],
        );
        $this->assertTrue($body['counts']['truncated'], 'limit=25 zich nuqtada kesilgan bo\'lishi kerak.');

        // Zich mahalla markazi polygon ichida — mahalla topilishi kerak.
        $this->assertNotNull($body['current_mahalla']);
        $this->assertArrayHasKey('id', $body['current_mahalla']);
        $this->assertArrayHasKey('name', $body['current_mahalla']);
        $this->assertNotSame('', $body['current_mahalla']['name']);
    }

    // ── Yordamchilar ────────────────────────────────────────────────────────

    /** Pilot (Shovot) tumanida deputat yaratadi. @return array{0:User,1:string} */
    private function makeDeputatInPilotDistrict(): array
    {
        $districtId = DB::connection('master')->table('districts')
            ->where('soato_code', (string) config('mahalla.executive.default_district_soato'))
            ->value('id');
        $this->assertNotNull($districtId, 'Pilot tuman (soato_code) bazada topilmadi.');

        $mahallaId = DB::connection('master')->table('mahallas')
            ->where('district_id', $districtId)->value('id');
        $this->assertNotNull($mahallaId, 'Pilot tumanda mahalla topilmadi.');

        $user = $this->insertDeputat($districtId, $mahallaId, 'Синов депутат');

        return [$user, (string) $districtId];
    }

    /**
     * Profili to'liq bo'lmagan deputat: mahalla.users.district_id/mahalla_id
     * ATAYLAB null. `MahallaAccess::scopeFor()` bunday user uchun
     * `districtId = null`, `canSeeAll = false` qaytaradi — bu ILGARIGI
     * zaiflikning aniq shароiti (594d9b6 gача `?? districtIdForPoint()`
     * shu holatda ISTALGAN tumanni ochib qo'yardi).
     */
    private function makeDeputatWithoutDistrict(): User
    {
        return $this->insertDeputat(null, null, 'Қамровсиз депутат');
    }

    /** Uch schemaga (auth.users, auth.user_system_access, mahalla.users) deputat yozadi. */
    private function insertDeputat(?string $districtId, ?string $mahallaId, string $name): User
    {
        $userId = (string) Str::uuid();
        $now = now();

        DB::connection('auth')->table('users')->insert([
            'id' => $userId,
            'name' => $name,
            'login' => 'nb_'.substr($userId, 0, 8),
            'password' => bcrypt('secret'),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::connection('auth')->table('user_system_access')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'system_id' => DB::connection('auth')->table('systems')->where('code', 'mahalla')->value('id'),
            'role' => 'deputat',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::connection('mahalla')->table('users')->insert([
            'id' => $userId,
            'name' => $name,
            'login' => 'nb_'.substr($userId, 0, 8),
            'password' => bcrypt('secret'),
            'district_id' => $districtId,
            'mahalla_id' => $mahallaId,
            'position' => 'deputat',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return User::on('auth')->findOrFail($userId);
    }

    /** Tumanning eng zich mahallasi markazi (real ma'lumotdan). @return array{0:float,1:float} */
    private function denseCenterIn(string $districtId): array
    {
        $row = DB::connection('master')->table('buildings')
            ->selectRaw('avg(lat) AS lat, avg(lng) AS lng, count(*) AS c')
            ->where('district_id', $districtId)
            ->whereNotNull('mahalla_id')
            ->groupBy('mahalla_id')
            ->orderByDesc('c')
            ->first();

        $this->assertNotNull($row, 'Pilot tumanda koordinatali bino topilmadi.');

        return [(float) $row->lat, (float) $row->lng];
    }

    /**
     * Pilot tumandagi REAL binoni monitoring ostiga oladi (mahalla.houses ga
     * qator qo'shadi). DatabaseTransactions qaytaradi — dev bazada iz qolmaydi.
     *
     * @return array{0:string,1:float,2:float,3:string} [buildingId, lat, lng, streetId]
     */
    private function monitorRealBuilding(string $districtId, string $status = 'in_progress'): array
    {
        $building = DB::connection('master')->table('buildings')
            ->where('district_id', $districtId)
            ->where('type', 'residential')
            ->whereNotNull('street_id')
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->whereNotNull('mahalla_id')
            ->first(['id', 'lat', 'lng', 'street_id', 'mahalla_id']);

        $this->assertNotNull(
            $building,
            'Pilot tumanda street_id/lat/lng/mahalla_id to\'liq bino topilmadi.',
        );

        $now = now();
        DB::connection('mahalla')->table('houses')->insert([
            'id' => (string) Str::uuid(),
            'district_id' => $districtId,
            'mahalla_id' => $building->mahalla_id,
            'street_id' => $building->street_id,
            'building_id' => $building->id,
            'lat' => $building->lat,
            'lng' => $building->lng,
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            (string) $building->id,
            (float) $building->lat,
            (float) $building->lng,
            (string) $building->street_id,
        ];
    }
}
