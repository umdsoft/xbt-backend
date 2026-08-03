<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * advisor gvardiyasi: advisor bo'lmagan user 403, advisor user o'tadi,
 * auth-siz so'rov 401.
 */
class AccessTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['pgsql', 'auth', 'master', 'advisor'];

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/advisor/me')->assertUnauthorized();
        $this->getJson('/api/advisor/districts')->assertUnauthorized();
    }

    public function test_non_advisor_user_gets_403(): void
    {
        $user = $this->makeUser(null);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/advisor/me')
            ->assertForbidden();
    }

    public function test_advisor_user_passes(): void
    {
        $user = $this->makeUser('advisor_tuman');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/advisor/me')
            ->assertOk();
    }

    /**
     * @param  string|null  $role  null => advisor tizimiga ruxsatsiz user
     */
    private function makeUser(?string $role): User
    {
        $userId = (string) Str::uuid();
        $now = now();

        DB::connection('auth')->table('users')->insert([
            'id' => $userId, 'login' => 'atest_'.substr($userId, 0, 8),
            'password' => bcrypt('secret'), 'name' => 'ТЕСТ', 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        if ($role !== null) {
            DB::connection('auth')->table('user_system_access')->insert([
                'id' => (string) Str::uuid(), 'user_id' => $userId,
                'system_id' => $this->advisorSystemId(),
                'role' => $role, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        return User::on('auth')->findOrFail($userId);
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
