<?php

declare(strict_types=1);

namespace Tests\Feature\Mahalla\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * `executive/*` testlari uchun umumiy fikstura yordamchilari.
 *
 * `TumanViewerScopeTest`dan chiqarildi — `AyollarSummaryTest` xuddi shu
 * userlarga (tuman/viloyat, o'z va begona tuman) muhtoj, DRY uchun bitta
 * joyda (rules/common/coding-style.md: takrorlama, umumiy trait ga chiqar).
 */
trait ExecutiveScopeFixtures
{
    protected function districtId(): string
    {
        $id = DB::connection('master')->table('districts')
            ->where('soato_code', (string) config('mahalla.executive.default_district_soato'))
            ->value('id');

        $this->assertNotNull($id, 'Standart tuman (Shovot) bazada bo\'lishi kerak');

        return (string) $id;
    }

    protected function anotherDistrictId(string $exclude): string
    {
        $id = DB::connection('master')->table('districts')
            ->where('id', '!=', $exclude)->orderBy('sort_order')->value('id');

        $this->assertNotNull($id, 'Ikkinchi tuman bazada bo\'lishi kerak');

        return (string) $id;
    }

    protected function makeUserWithRole(string $role): User
    {
        $userId = (string) Str::uuid();
        $now = now();

        DB::connection('auth')->table('users')->insert([
            'id' => $userId, 'login' => 'test_'.substr($userId, 0, 8),
            'password' => bcrypt('secret'), 'name' => 'ТЕСТ раҳбар',
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

    /**
     * `tuman` roli + mahalla profili (district_id shu yerdan olinadi).
     * `$districtId = null` — profilda tuman ko'rsatilmagan holat.
     */
    protected function makeTumanUser(?string $districtId, ?string $mahallaId = null): User
    {
        return $this->makeMahallaProfileUser('tuman', $districtId, $mahallaId);
    }

    /**
     * Berilgan rol bilan `mahalla.users` profiliga ega user yaratadi
     * (`makeTumanUser()`ning umumlashtirilgani — boshqa geo-qamrovli
     * rollar uchun ham ishlatiladi).
     */
    protected function makeMahallaProfileUser(string $role, ?string $districtId, ?string $mahallaId = null): User
    {
        $user = $this->makeUserWithRole($role);

        DB::connection('mahalla')->table('users')->insert([
            'id' => $user->id,
            'name' => 'ТЕСТ '.$role,
            'login' => 'test_'.substr((string) $user->id, 0, 8),
            'password' => bcrypt('secret'),
            'district_id' => $districtId,
            'mahalla_id' => $mahallaId,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }
}
