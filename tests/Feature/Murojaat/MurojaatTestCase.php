<?php

declare(strict_types=1);

namespace Tests\Feature\Murojaat;

use App\Domains\Murojaat\Support\MurojaatAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MurojAAT domeni feature testlari poydevori. Ko'p sxemali (auth/master/murojaat).
 * DatabaseTransactions (RefreshDatabase EMAS — dev baza ustida, advisor naqshi).
 */
abstract class MurojaatTestCase extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['pgsql', 'auth', 'master', 'murojaat'];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function murojaatSystemId(): string
    {
        $auth = DB::connection('auth');
        $id = $auth->table('systems')->where('code', MurojaatAccess::SYSTEM_CODE)->value('id');
        if ($id !== null) {
            return (string) $id;
        }
        $id = (string) Str::uuid();
        $auth->table('systems')->insert([
            'id' => $id, 'code' => MurojaatAccess::SYSTEM_CODE, 'name' => 'Мурожаатлар',
            'is_active' => true, 'sort_order' => 4, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** murojaat foydalanuvchisi: auth.users + user_system_access + murojaat.profiles. */
    protected function makeUser(string $role, string $level = 'tuman', ?string $districtId = null): User
    {
        $userId = (string) Str::uuid();
        $now = now();
        DB::connection('auth')->table('users')->insert([
            'id' => $userId, 'login' => 'mrj_'.substr($userId, 0, 8), 'password' => bcrypt('secret'),
            'name' => 'Синов '.substr($userId, 0, 4), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::connection('auth')->table('user_system_access')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $userId, 'system_id' => $this->murojaatSystemId(),
            'role' => $role, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::connection('murojaat')->table('profiles')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $userId, 'level' => $level,
            'district_id' => $districtId, 'active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return User::on('auth')->findOrFail($userId);
    }

    /** Rolsiz (murojaat bo'lmagan) user — 403 tekshiruvi uchun. */
    protected function makeOutsider(): User
    {
        $userId = (string) Str::uuid();
        DB::connection('auth')->table('users')->insert([
            'id' => $userId, 'login' => 'out_'.substr($userId, 0, 8), 'password' => bcrypt('secret'),
            'name' => 'Бегона', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return User::on('auth')->findOrFail($userId);
    }

    protected function someDistrictId(): string
    {
        return (string) DB::connection('master')->table('districts')
            ->whereNotNull('soato_code')->orderBy('sort_order')->value('id');
    }

    protected function anotherDistrictId(string $not): string
    {
        return (string) DB::connection('master')->table('districts')
            ->whereNotNull('soato_code')->where('id', '!=', $not)->orderBy('sort_order')->value('id');
    }

    /** Bitta xom murojaat qatori (import uchun) — override qiymatlar bilan. */
    protected function row(array $over = []): array
    {
        return array_merge([
            'murojaat_raqami' => 'MR-'.random_int(1000, 999999),
            'familiya' => 'Тошматов', 'ism' => 'Али', 'mahalla' => 'Марказ МФЙ',
            'ijrochi' => 'Урганч тумани ободонлаштириш', 'yonalish' => 'Йўл',
            'qaerdan' => 'Халқ қабулхонаси', 'natija_holat' => 'Ижобий ҳал этилди',
            'kelgan_sana' => '01.03.2026', 'muddat_kun' => '30',
        ], $over);
    }
}
