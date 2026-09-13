<?php

declare(strict_types=1);

namespace Tests\Feature\Mahalla;

use App\Domains\Mahalla\Support\ExecutiveScope;
use App\Domains\Mahalla\Support\MahallaAccess;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

        // Aniq ro'yxat — `streets.edit`, `contracts.manage`, `buildings.classify`,
        // `projects.manage` kabi yozuv huquqlari YO'Q ekanini ham qulflaydi.
        // ESLATMA: bu `viloyat` bilan ATAYLAB bir xil (MahallaAccess::PERMISSIONS
        // izohiga qarang) — lekin ikkalasi mustaqil o'zgarishi mumkin, shuning
        // uchun bu yerda `viloyat`ga taqqoslanmaydi, faqat o'z qiymati bilan.
        $this->assertSame(
            ['dashboard.view', 'reports.view', 'houses.view', 'analyses.view'],
            $perms,
        );
    }

    public function test_tuman_scope_is_limited_to_its_own_district(): void
    {
        // ATAYLAB standart (Shovot) tumandan boshqasi — aks holda default-fallback
        // xatosi ham xuddi shu qiymatni qaytarib, testni ko'rlantirgan bo'lardi.
        $districtId = $this->anotherDistrictId($this->districtId());
        $user = $this->makeTumanUser($districtId);

        $scope = app(MahallaAccess::class)->scopeFor($user);

        $this->assertFalse($scope->canSeeAll, 'tuman HAMMASINI ko\'rmaydi');
        $this->assertFalse($scope->isAdmin, 'tuman boshqaruvchi emas');
        $this->assertFalse($scope->restrictToStreets, 'tuman ko\'chalar bilan cheklanmaydi');
        $this->assertSame($districtId, $scope->districtId);
        $this->assertNull($scope->mahallaId, 'tuman bitta mahalla bilan cheklanmaydi');
        $this->assertSame([], $scope->streetIds, 'tuman ko\'cha ro\'yxati bilan ham cheklanmaydi (fail-closed)');
    }

    /**
     * REGRESSIYA. `deputat`dan `tuman`ga ko'tarilgan (lekin eski ko'cha
     * biriktiruvi va mahalla_id profilda o'chirilmagan) hisob — real
     * `mahalla.street_assignments` qatori va profilda mahalla_id BOR bo'lsa
     * ham, `scopeFor()` ikkalasini ham ATAYLAB tashlab yuborishini isbotlaydi.
     * Aks holda `House::scopeVisibleTo()` bu userga eski ko'chalarning
     * honadonlarini ko'rsatib qo'yardi — fail-closed buzilgan bo'lardi.
     */
    public function test_tuman_scope_discards_existing_street_assignment_and_mahalla_id(): void
    {
        $districtId = $this->anotherDistrictId($this->districtId());
        [$mahallaId, $streetId] = $this->realMahallaWithStreet($districtId);

        $user = $this->makeTumanUser($districtId, $mahallaId);

        DB::connection('mahalla')->table('street_assignments')->insert([
            'id' => (string) Str::uuid(),
            'street_id' => $streetId,
            'user_id' => $user->id,
            'assigned_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $scope = app(MahallaAccess::class)->scopeFor($user);

        $this->assertFalse($scope->canSeeAll);
        $this->assertFalse($scope->isAdmin);
        $this->assertFalse($scope->restrictToStreets);
        $this->assertSame($districtId, $scope->districtId);
        $this->assertNull($scope->mahallaId, 'profilda mahalla_id bo\'lsa ham ataylab tashlanadi');
        $this->assertSame([], $scope->streetIds, 'ko\'cha biriktiruvi bo\'lsa ham ataylab tashlanadi (fail-closed)');
    }

    public function test_tuman_without_district_on_profile_gets_null_district(): void
    {
        $user = $this->makeTumanUser(null);

        $scope = app(MahallaAccess::class)->scopeFor($user);

        $this->assertNull($scope->districtId, 'profilsiz tuman useriga tuman berilmaydi');
        $this->assertFalse($scope->canSeeAll, 'va u HAMMASINI ham ko\'rmaydi — fail-closed');
        $this->assertSame([], $scope->streetIds);
    }

    /**
     * `mahalla.users`da UMUMAN qator yo'q (qo'lda ochilgan hisob uchun odatiy
     * holat) — `MahallaProfile::find()` null qaytaradi. Xulq profil bor-u
     * district_id null bo'lgan holat bilan bir xil bo'lishi kerak.
     */
    public function test_tuman_without_any_mahalla_profile_row_gets_null_district(): void
    {
        $user = $this->makeUserWithRole('tuman');

        $scope = app(MahallaAccess::class)->scopeFor($user);

        $this->assertNull($scope->districtId, 'mahalla.users qatori yo\'q tuman useriga tuman berilmaydi');
        $this->assertFalse($scope->canSeeAll, 'va u HAMMASINI ham ko\'rmaydi — fail-closed');
        $this->assertSame([], $scope->streetIds);
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

    public function test_scope_resolves_own_district_when_none_requested(): void
    {
        $districtId = $this->districtId();
        $user = $this->makeTumanUser($districtId);

        $model = app(ExecutiveScope::class)
            ->district($user, null);

        $this->assertSame($districtId, (string) $model->id);
    }

    public function test_scope_allows_own_district_when_requested_explicitly(): void
    {
        $districtId = $this->districtId();
        $user = $this->makeTumanUser($districtId);

        $model = app(ExecutiveScope::class)
            ->district($user, $districtId);

        $this->assertSame($districtId, (string) $model->id);
    }

    public function test_scope_forbids_another_district(): void
    {
        $own = $this->districtId();
        $other = $this->anotherDistrictId($own);
        $user = $this->makeTumanUser($own);

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(403);

        app(ExecutiveScope::class)->district($user, $other);
    }

    /**
     * ENG MUHIM TEST. Profilida tuman ko'rsatilmagan `tuman` user standart
     * tumanga (Shovot) TUSHMASLIGI kerak — aks holda noto'g'ri sozlangan
     * hisob jimgina begona tuman ma'lumotini oladi.
     */
    public function test_scope_forbids_tuman_without_district_instead_of_defaulting(): void
    {
        $user = $this->makeTumanUser(null);

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(403);

        app(ExecutiveScope::class)->district($user, null);
    }

    public function test_scope_lets_viloyat_open_any_district(): void
    {
        $own = $this->districtId();
        $other = $this->anotherDistrictId($own);
        $user = $this->makeUserWithRole('viloyat');

        $model = app(ExecutiveScope::class)
            ->district($user, $other);

        $this->assertSame($other, (string) $model->id);
    }

    public function test_scope_defaults_viloyat_to_configured_district(): void
    {
        $user = $this->makeUserWithRole('viloyat');

        $model = app(ExecutiveScope::class)
            ->district($user, null);

        $this->assertSame($this->districtId(), (string) $model->id);
    }

    public function test_scope_rejects_mahalla_outside_own_district(): void
    {
        $own = $this->districtId();
        $other = $this->anotherDistrictId($own);
        $user = $this->makeTumanUser($own);

        $foreign = DB::connection('master')->table('mahallas')
            ->where('district_id', $other)->value('id');
        $this->assertNotNull($foreign, 'Boshqa tumanda mahalla bo\'lishi kerak');

        $this->expectException(ModelNotFoundException::class);

        app(ExecutiveScope::class)
            ->mahalla($user, (string) $foreign);
    }

    public function test_scope_accepts_mahalla_inside_own_district(): void
    {
        $own = $this->districtId();
        $user = $this->makeTumanUser($own);

        $mine = DB::connection('master')->table('mahallas')
            ->where('district_id', $own)->value('id');

        $model = app(ExecutiveScope::class)
            ->mahalla($user, (string) $mine);

        $this->assertSame((string) $mine, (string) $model->id);
    }

    public function test_visible_district_ids_is_null_for_viloyat_and_single_for_tuman(): void
    {
        $scope = app(ExecutiveScope::class);

        $this->assertNull($scope->visibleDistrictIds($this->makeUserWithRole('viloyat')));

        $own = $this->districtId();
        $this->assertSame([$own], $scope->visibleDistrictIds($this->makeTumanUser($own)));

        $this->assertSame([], $scope->visibleDistrictIds($this->makeTumanUser(null)),
            'tumansiz user hech qanday tuman ko\'rmaydi');
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

    /**
     * Berilgan tumandagi HAQIQIY (kamida bitta ko'chasi bor) mahalla va shu
     * mahalladagi ko'chani topadi. Geo qatorlar yaratilmaydi — faqat
     * mavjudlaridan tanlanadi (qarang: `NearbyApiTest::monitorRealBuilding`).
     *
     * @return array{0: string, 1: string} [mahallaId, streetId]
     */
    protected function realMahallaWithStreet(string $districtId): array
    {
        $row = DB::connection('master')->table('streets as st')
            ->join('mahallas as m', 'm.id', '=', 'st.mahalla_id')
            ->where('m.district_id', $districtId)
            ->first(['m.id as mahalla_id', 'st.id as street_id']);

        $this->assertNotNull($row, 'Tumanda ko\'chasi bor mahalla topilishi kerak');

        return [(string) $row->mahalla_id, (string) $row->street_id];
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
    protected function makeTumanUser(?string $districtId, ?string $mahallaId = null): User
    {
        $user = $this->makeUserWithRole('tuman');

        DB::connection('mahalla')->table('users')->insert([
            'id' => $user->id,
            'name' => 'ТЕСТ туман раҳбари',
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
