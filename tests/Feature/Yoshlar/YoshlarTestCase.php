<?php

declare(strict_types=1);

namespace Tests\Feature\Yoshlar;

use App\Domains\Yoshlar\Models\Organization;
use App\Domains\Yoshlar\Models\Staff;
use App\Domains\Yoshlar\Support\YoshlarAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Yoshlar domeni feature testlari poydevori. Ko'p sxemali (auth/master/yoshlar).
 * DatabaseTransactions (RefreshDatabase EMAS — dev bazasi ustida ishlaymiz).
 */
abstract class YoshlarTestCase extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, string> */
    protected array $connectionsToTransact = ['pgsql', 'auth', 'master', 'yoshlar'];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function yoshlarSystemId(): string
    {
        $auth = DB::connection('auth');
        $id = $auth->table('systems')->where('code', YoshlarAccess::SYSTEM_CODE)->value('id');
        if ($id !== null) {
            return (string) $id;
        }

        $id = (string) Str::uuid();
        $auth->table('systems')->insert([
            'id' => $id, 'code' => YoshlarAccess::SYSTEM_CODE, 'name' => 'Ёшлар',
            'is_active' => true, 'sort_order' => 7, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** yoshlar foydalanuvchisi: auth.users + user_system_access + yoshlar.staff. */
    protected function makeUser(string $role, ?string $orgId = null, bool $canPatronage = false): User
    {
        $userId = (string) Str::uuid();
        $now = now();

        DB::connection('auth')->table('users')->insert([
            'id' => $userId, 'login' => 'ysh_'.substr($userId, 0, 8), 'password' => bcrypt('secret'),
            'name' => 'Sinov '.substr($userId, 0, 4), 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::connection('auth')->table('user_system_access')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $userId, 'system_id' => $this->yoshlarSystemId(),
            'role' => $role, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        if ($orgId !== null) {
            Staff::query()->create([
                'user_id' => $userId, 'org_id' => $orgId,
                'position' => 'Sinov lavozimi', 'can_patronage' => $canPatronage, 'is_active' => true,
            ]);
        }

        return User::on('auth')->findOrFail($userId);
    }

    /** Rolsiz (yoshlar bo'lmagan) user — 403 tekshiruvi uchun. */
    protected function makeOutsider(): User
    {
        $userId = (string) Str::uuid();
        DB::connection('auth')->table('users')->insert([
            'id' => $userId, 'login' => 'out_'.substr($userId, 0, 8), 'password' => bcrypt('secret'),
            'name' => 'Begona', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return User::on('auth')->findOrFail($userId);
    }

    /** @param array<string, mixed> $attrs */
    protected function makeOrganization(string $type, array $attrs = []): Organization
    {
        $name = 'TEST-'.Str::random(8);

        return Organization::query()->create(array_merge([
            'type' => $type,
            'name_cyr' => $name,
            'name_lat' => $name,
            'is_active' => true,
        ], $attrs));
    }

    protected function someDistrictId(): string
    {
        return (string) DB::connection('master')->table('districts')
            ->orderBy('sort_order')->value('id');
    }

    /** Boshqa tuman — IDOR testlari uchun. */
    protected function otherDistrictId(string $exceptId): string
    {
        return (string) DB::connection('master')->table('districts')
            ->where('id', '!=', $exceptId)->orderBy('sort_order')->value('id');
    }

    protected function someMahallaId(?string $districtId = null): string
    {
        $query = DB::connection('master')->table('mahallas');
        if ($districtId !== null) {
            $query->where('district_id', $districtId);
        }

        return (string) $query->orderBy('sort_order')->value('id');
    }
}
