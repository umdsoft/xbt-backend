<?php

declare(strict_types=1);

namespace Tests\Feature\Mahalla;

use App\Domains\Mahalla\Support\ExecutiveScope;
use App\Domains\Mahalla\Support\MahallaAccess;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Feature\Mahalla\Concerns\ExecutiveScopeFixtures;
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
    use ExecutiveScopeFixtures;

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

        try {
            app(ExecutiveScope::class)->district($user, $other);
            $this->fail('403 kutilgan edi, istisno otilmadi');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    /**
     * ENG MUHIM TEST. Profilida tuman ko'rsatilmagan `tuman` user standart
     * tumanga (Shovot) TUSHMASLIGI kerak — aks holda noto'g'ri sozlangan
     * hisob jimgina begona tuman ma'lumotini oladi.
     */
    public function test_scope_forbids_tuman_without_district_instead_of_defaulting(): void
    {
        $user = $this->makeTumanUser(null);

        try {
            app(ExecutiveScope::class)->district($user, null);
            $this->fail('403 kutilgan edi, istisno otilmadi');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
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

    /**
     * Har bir "tuman shaklidagi" endpoint uchun bir xil uch holat:
     * o'z tumani 200, begona tuman 403, tumansiz profil 403.
     *
     * @return array<string, array{0: string}>
     */
    public static function districtEndpointProvider(): array
    {
        return [
            'dashboard' => ['/api/mahalla/executive/districts/%s'],
            'social-objects' => ['/api/mahalla/executive/districts/%s/social-objects'],
            'objects' => ['/api/mahalla/executive/districts/%s/objects'],
            'geojson' => ['/api/mahalla/executive/districts/%s/geojson'],
            'scoring' => ['/api/mahalla/executive/scoring/%s'],
        ];
    }

    #[DataProvider('districtEndpointProvider')]
    public function test_tuman_can_open_its_own_district(string $template): void
    {
        $own = $this->districtId();
        $user = $this->makeTumanUser($own);

        $this->actingAs($user, 'sanctum')
            ->getJson(sprintf($template, $own))
            ->assertOk();
    }

    #[DataProvider('districtEndpointProvider')]
    public function test_tuman_cannot_open_another_district(string $template): void
    {
        $own = $this->districtId();
        $other = $this->anotherDistrictId($own);
        $user = $this->makeTumanUser($own);

        $this->actingAs($user, 'sanctum')
            ->getJson(sprintf($template, $other))
            ->assertForbidden();
    }

    #[DataProvider('districtEndpointProvider')]
    public function test_tuman_without_district_is_forbidden(string $template): void
    {
        $user = $this->makeTumanUser(null);
        $someDistrict = $this->districtId();

        $this->actingAs($user, 'sanctum')
            ->getJson(sprintf($template, $someDistrict))
            ->assertForbidden();
    }

    public function test_tuman_dashboard_without_id_falls_back_to_own_district(): void
    {
        $own = $this->districtId();
        $user = $this->makeTumanUser($own);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/districts')
            ->assertOk()
            ->assertJsonPath('district.id', $own);
    }

    public function test_district_list_shows_only_own_district_to_tuman(): void
    {
        $own = $this->districtId();
        $user = $this->makeTumanUser($own);

        $res = $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/district-list')
            ->assertOk();

        $ids = array_column($res->json('districts'), 'id');
        $this->assertSame([$own], $ids);
    }

    /**
     * REGRESSIYA: viloyat hamon barcha tumanlarni ko'radi.
     */
    public function test_district_list_shows_all_districts_to_viloyat(): void
    {
        $user = $this->makeUserWithRole('viloyat');

        $res = $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/district-list')
            ->assertOk();

        $this->assertGreaterThan(1, count($res->json('districts')),
            'viloyat bir nechta tuman ko\'rishi kerak');
    }

    /**
     * REGRESSIYA: viloyat begona tumanni ham ocha oladi.
     */
    public function test_viloyat_can_open_any_district(): void
    {
        $own = $this->districtId();
        $other = $this->anotherDistrictId($own);
        $user = $this->makeUserWithRole('viloyat');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/districts/'.$other)
            ->assertOk()
            ->assertJsonPath('district.id', $other);
    }

    /**
     * Har bir "mahalla shaklidagi" endpoint uchun bir xil ikki holat:
     * o'z tumanidagi mahalla 200, begona tumandagi mahalla 404.
     *
     * @return array<string, array{0: string}>
     */
    public static function mahallaEndpointProvider(): array
    {
        return [
            'dashboard' => ['/api/mahalla/executive/mahallas/%s'],
            'obod' => ['/api/mahalla/executive/mahallas/%s/obod'],
            'projects' => ['/api/mahalla/executive/mahallas/%s/projects'],
        ];
    }

    #[DataProvider('mahallaEndpointProvider')]
    public function test_tuman_can_open_mahalla_in_own_district(string $template): void
    {
        $own = $this->districtId();
        $user = $this->makeTumanUser($own);
        $mine = (string) DB::connection('master')->table('mahallas')
            ->where('district_id', $own)->value('id');

        $this->actingAs($user, 'sanctum')->getJson(sprintf($template, $mine))->assertOk();
    }

    #[DataProvider('mahallaEndpointProvider')]
    public function test_tuman_cannot_open_mahalla_in_another_district(string $template): void
    {
        $own = $this->districtId();
        $other = $this->anotherDistrictId($own);
        $user = $this->makeTumanUser($own);
        $foreign = (string) DB::connection('master')->table('mahallas')
            ->where('district_id', $other)->value('id');

        $this->actingAs($user, 'sanctum')->getJson(sprintf($template, $foreign))->assertNotFound();
    }

    /**
     * REGRESSIYA: viloyat istalgan mahallani ocha oladi.
     */
    public function test_viloyat_can_open_mahalla_in_any_district(): void
    {
        $own = $this->districtId();
        $other = $this->anotherDistrictId($own);
        $user = $this->makeUserWithRole('viloyat');
        $foreign = (string) DB::connection('master')->table('mahallas')
            ->where('district_id', $other)->value('id');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/mahallas/'.$foreign)
            ->assertOk();
    }

    /**
     * `nearby` endpointi `tuman` uchun ALLAQACHON to'g'ri ishlashi kerak:
     * u `canSeeAll` bo'lmagan userni `scope->districtId` bilan cheklaydi.
     * Bu testni qo'shish shu bog'lanishni qulflaydi — kelajakda kimdir
     * `scopeFor()` ni o'zgartirsa, shu test tutadi.
     */
    public function test_nearby_scopes_tuman_to_its_own_district(): void
    {
        $own = $this->districtId();
        $user = $this->makeTumanUser($own);

        $center = DB::connection('master')->table('mahallas')
            ->where('district_id', $own)->whereNotNull('center_lat')
            ->first(['center_lat', 'center_lng']);
        $this->assertNotNull($center, 'Tumanda markaz koordinatasi bo\'lgan mahalla kerak');

        $res = $this->actingAs($user, 'sanctum')->getJson(sprintf(
            '/api/mahalla/nearby?lat=%s&lng=%s&radius_m=3000&limit=50',
            $center->center_lat, $center->center_lng
        ))->assertOk();

        $this->assertNotEmpty($res->json('points'), 'tuman rahbari o\'z tumanida nuqtalarni ko\'rishi kerak');
    }

    /**
     * Tumansiz `tuman` user `nearby` da ham hech narsa ko'rmaydi.
     */
    public function test_nearby_returns_nothing_for_tuman_without_district(): void
    {
        $user = $this->makeTumanUser(null);

        $res = $this->actingAs($user, 'sanctum')->getJson(
            '/api/mahalla/nearby?lat=41.62&lng=60.38&radius_m=3000&limit=50'
        )->assertOk();

        $this->assertSame([], $res->json('points'));
    }

    /**
     * MEDIUM-1 (xavfsizlik ko'rigi, 2026-09). `EnsureMahallaViewer` — 8 ta
     * rahbariyat (executive) endpointining YAGONA gvardiyasi — `MahallaAccess
     * ::VIEWER_ROLES` ro'yxatiga TUSHMAGAN har bir operatsion rolni rad etishi
     * SHART.
     *
     * Bu ayniqsa `rais` uchun muhim: `MahallaAccess::scopeFor()` unga
     * NULL BO'LMAGAN `districtId` beradi (o'z profilidan). Agar `rais`
     * qandaydir sababga ko'ra `VIEWER_ROLES`ga qo'shilib qolsa (masalan
     * "u ham ko'rish huquqiga ega-ku" degan xato mulohaza bilan),
     * `ExecutiveScope::district()` unga BUTUN TUMANNI ochib beradi va
     * `ExecutiveScope::mahalla()` shu tumandagi ISTALGAN mahallani —
     * ya'ni bitta mahalla raisi butun tumanning rahbariyat ko'rinishini
     * (aholi, ijtimoiy obyektlar, skoring...) ko'ra oladigan bo'lib qoladi.
     * `hokim-yordamchisi` ham xuddi shunday qamrovga ega, xuddi shu xavf.
     *
     * `deputat` allaqachon `ExecutiveDashboardTest`da (districts/geojson/
     * scoring) qoplangan — bu yerda faqat SIMMETRIYA uchun: provider
     * ro'yxatiga kelajakda yangi operatsion rol (masalan hozircha yo'q
     * biror lavozim) qo'shilsa, bitta qator qo'shish yetarli bo'lishi kerak.
     *
     * @return array<string, array{0: string}>
     */
    public static function nonViewerOperationalRoleProvider(): array
    {
        return [
            'rais' => ['rais'],
            'hokim-yordamchisi' => ['hokim-yordamchisi'],
            'deputat' => ['deputat'],
        ];
    }

    #[DataProvider('nonViewerOperationalRoleProvider')]
    public function test_operational_role_is_forbidden_on_district_shaped_executive_endpoint(string $role): void
    {
        $own = $this->districtId();
        // Haqiqiy scopeFor() xulqini takrorlash uchun `mahalla.users`da
        // profil ham beriladi — aks holda guard 403ni faqat rol ro'yxati
        // orqali berayotgani, profil holatidan qat'i nazar, aniq bo'lmaydi.
        $user = $this->makeMahallaProfileUser($role, $own);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/districts/'.$own)
            ->assertForbidden();
    }

    #[DataProvider('nonViewerOperationalRoleProvider')]
    public function test_operational_role_is_forbidden_on_mahalla_shaped_executive_endpoint(string $role): void
    {
        $own = $this->districtId();
        $mahallaId = (string) DB::connection('master')->table('mahallas')
            ->where('district_id', $own)->value('id');
        $this->assertNotSame('', $mahallaId, 'Standart tumanda mahalla bo\'lishi kerak');

        $user = $this->makeMahallaProfileUser($role, $own, $mahallaId);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/mahallas/'.$mahallaId)
            ->assertForbidden();
    }

    // ---------- fikstura yordamchilari ----------
    // districtId/anotherDistrictId/makeUserWithRole/makeTumanUser/makeMahallaProfileUser
    // -> Concerns\ExecutiveScopeFixtures (AyollarSummaryTest bilan umumiy).

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
}
