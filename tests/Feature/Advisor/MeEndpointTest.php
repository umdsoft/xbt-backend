<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor;

use App\Domains\Advisor\Models\Advisor;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `/api/advisor/me` + `/api/advisor/districts` — frontend shartnomasi.
 *   me: {advisor:{id,name,level,district:{id,name}|null}, role, permissions}
 *   districts: [{id,name,soato}]
 */
class MeEndpointTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['pgsql', 'auth', 'master', 'advisor'];

    public function test_guest_me_is_401(): void
    {
        $this->getJson('/api/advisor/me')->assertUnauthorized();
    }

    public function test_tuman_advisor_me_returns_contract_shape(): void
    {
        $district = DB::connection('master')->table('districts')
            ->whereNotNull('soato_code')->orderBy('sort_order')
            ->first(['id', 'name_cyr']);
        $this->assertNotNull($district);

        [$user] = $this->makeAdvisor('advisor_tuman', 'tuman', (string) $district->id, 'Тест маслаҳатчи');

        $body = $this->actingAs($user, 'sanctum')
            ->getJson('/api/advisor/me')
            ->assertOk()
            ->assertJsonStructure([
                'advisor' => ['id', 'name', 'level', 'district' => ['id', 'name']],
                'role',
                'permissions',
            ])
            ->json();

        $this->assertSame('tuman', $body['advisor']['level']);
        $this->assertSame('Тест маслаҳатчи', $body['advisor']['name']);
        $this->assertSame((string) $district->id, $body['advisor']['district']['id']);
        $this->assertSame($district->name_cyr, $body['advisor']['district']['name']);
        $this->assertSame('advisor_tuman', $body['role']);
        $this->assertContains('dashboard.view', $body['permissions']);
    }

    public function test_viloyat_advisor_me_has_null_district_and_wildcard_perms(): void
    {
        [$user] = $this->makeAdvisor('advisor_viloyat', 'viloyat', null, 'Вилоят маслаҳатчиси');

        $body = $this->actingAs($user, 'sanctum')
            ->getJson('/api/advisor/me')
            ->assertOk()
            ->json();

        $this->assertSame('viloyat', $body['advisor']['level']);
        $this->assertNull($body['advisor']['district']);
        $this->assertSame('advisor_viloyat', $body['role']);
        $this->assertContains('*', $body['permissions']);
    }

    public function test_districts_endpoint_returns_all_xorazm_districts(): void
    {
        [$user] = $this->makeAdvisor('advisor_viloyat', 'viloyat', null, 'Вилоят');

        $body = $this->actingAs($user, 'sanctum')
            ->getJson('/api/advisor/districts')
            ->assertOk()
            ->assertJsonStructure([['id', 'name', 'soato']])
            ->json();

        $this->assertGreaterThanOrEqual(13, count($body));
        $this->assertArrayHasKey('soato', $body[0]);
    }

    public function test_guest_districts_is_401(): void
    {
        $this->getJson('/api/advisor/districts')->assertUnauthorized();
    }

    /**
     * @return array{0: User, 1: Advisor}
     */
    private function makeAdvisor(string $role, string $level, ?string $districtId, string $name): array
    {
        $userId = (string) Str::uuid();
        $now = now();

        DB::connection('auth')->table('users')->insert([
            'id' => $userId, 'login' => 'am_'.substr($userId, 0, 8),
            'password' => bcrypt('secret'), 'name' => $name, 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::connection('auth')->table('user_system_access')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $userId,
            'system_id' => $this->advisorSystemId(),
            'role' => $role, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $advisor = Advisor::create([
            'user_id' => $userId, 'level' => $level, 'district_id' => $districtId, 'active' => true,
        ]);

        return [User::on('auth')->findOrFail($userId), $advisor];
    }

    private function advisorSystemId(): string
    {
        $id = DB::connection('auth')->table('systems')->where('code', 'advisor')->value('id');
        if ($id !== null) {
            return (string) $id;
        }

        $id = (string) Str::uuid();
        DB::connection('auth')->table('systems')->insert([
            'id' => $id, 'code' => 'advisor', 'name' => 'Advisor', 'url' => null,
            'is_active' => true, 'sort_order' => 3, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
