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

        $userId = (string) Str::uuid();
        $now = now();

        DB::connection('auth')->table('users')->insert([
            'id' => $userId,
            'name' => 'Синов депутат',
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
            'name' => 'Синов депутат',
            'login' => 'nb_'.substr($userId, 0, 8),
            'password' => bcrypt('secret'),
            'district_id' => $districtId,
            'mahalla_id' => $mahallaId,
            'position' => 'deputat',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [User::on('auth')->findOrFail($userId), (string) $districtId];
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
}
