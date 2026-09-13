<?php

declare(strict_types=1);

namespace Tests\Feature\Mahalla;

use App\Domains\Mahalla\Support\MahallaAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `tuman` roli — tuman bilan cheklangan «faqat ko'rish» rahbariyati.
 *
 * `viloyat` dan farqi FAQAT qamrovda: ruxsatlar bir xil, lekin canSeeAll=false
 * va districtId profildan olinadi.
 */
class TumanViewerScopeTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, string> */
    protected array $connectionsToTransact = ['pgsql', 'auth', 'master', 'mahalla'];

    public function test_tuman_role_is_a_viewer_role(): void
    {
        $this->assertContains('tuman', MahallaAccess::VIEWER_ROLES);
    }

    public function test_tuman_has_view_only_permissions(): void
    {
        $user = $this->makeTumanUser($this->districtId());
        $perms = app(MahallaAccess::class)->permissionsFor($user);

        $this->assertContains('dashboard.view', $perms);
        $this->assertContains('analyses.view', $perms);
        $this->assertNotContains('photos.upload', $perms, 'rahbar surat yuklamaydi');
        $this->assertNotContains('*', $perms, 'rahbar super-admin emas');
    }

    public function test_tuman_scope_is_limited_to_its_own_district(): void
    {
        $districtId = $this->districtId();
        $user = $this->makeTumanUser($districtId);

        $scope = app(MahallaAccess::class)->scopeFor($user);

        $this->assertFalse($scope->canSeeAll, 'tuman HAMMASINI ko\'rmaydi');
        $this->assertFalse($scope->isAdmin, 'tuman boshqaruvchi emas');
        $this->assertFalse($scope->restrictToStreets, 'tuman ko\'chalar bilan cheklanmaydi');
        $this->assertSame($districtId, $scope->districtId);
        $this->assertNull($scope->mahallaId, 'tuman bitta mahalla bilan cheklanmaydi');
    }

    public function test_tuman_without_district_on_profile_gets_null_district(): void
    {
        $user = $this->makeTumanUser(null);

        $scope = app(MahallaAccess::class)->scopeFor($user);

        $this->assertNull($scope->districtId, 'profilsiz tuman useriga tuman berilmaydi');
        $this->assertFalse($scope->canSeeAll, 'va u HAMMASINI ham ko\'rmaydi — fail-closed');
    }

    /**
     * REGRESSIYA. `tuman` qo'shilganda `viloyat` xulqi buzilmaganini qulflaydi.
     */
    public function test_viloyat_still_sees_everything(): void
    {
        $user = $this->makeUserWithRole('viloyat');
        $scope = app(MahallaAccess::class)->scopeFor($user);

        $this->assertTrue($scope->canSeeAll);
        $this->assertFalse($scope->isAdmin);
    }

    /**
     * REGRESSIYA. `deputat` hamon ko'chalar bilan cheklangan.
     */
    public function test_deputat_still_restricted_to_streets(): void
    {
        $user = $this->makeUserWithRole('deputat');
        $scope = app(MahallaAccess::class)->scopeFor($user);

        $this->assertFalse($scope->canSeeAll);
        $this->assertTrue($scope->restrictToStreets);
    }

    // ---------- fikstura yordamchilari ----------

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
     *
     * `mahalla.users` ustunlari (haqiqiy bazada tekshirildi — migratsiya fayli
     * `password`ni nullable deb ko'rsatadi, lekin haqiqiy jadvalda u NOT NULL,
     * shuning uchun bu yerda ham beriladi):
     * id, name (NOT NULL), login (NOT NULL, UNIQUE), password (NOT NULL), email?,
     * district_id?, mahalla_id?, is_active, timestamps, deleted_at.
     */
    protected function makeTumanUser(?string $districtId): User
    {
        $user = $this->makeUserWithRole('tuman');

        DB::connection('mahalla')->table('users')->insert([
            'id' => $user->id,
            'name' => 'ТЕСТ туман раҳбари',
            'login' => 'test_'.substr((string) $user->id, 0, 8),
            'password' => bcrypt('secret'),
            'district_id' => $districtId,
            'mahalla_id' => null,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }
}
