<?php

declare(strict_types=1);

namespace Tests\Feature\Mahalla;

use App\Domains\Mahalla\Models\House;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Rahbariyat dashboard'i testlari.
 *
 * Geo (tuman/mahalla/ko'cha/bino) — HAQIQIY kadastr ma'lumoti, chunki
 * mahalla.houses dan master schema'siga tashqi kalitlar bor va ular commit
 * qilingan qatorni talab qiladi. Test faqat operatsion qatorlarni yaratadi,
 * ular tranzaksiya bilan qaytariladi.
 */
class ExecutiveDashboardTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Har ulanish alohida tranzaksiyada — test oxirida hammasi qaytariladi.
     *
     * @var array<int, string>
     */
    protected array $connectionsToTransact = ['pgsql', 'auth', 'master', 'mahalla'];

    public function test_real_cadastre_data_is_available(): void
    {
        $count = DB::connection('master')->table('buildings')
            ->where('district_id', $this->districtId())
            ->where('type', 'residential')
            ->count();

        $this->assertGreaterThan(0, $count, 'Shovot kadastr binolari topilishi kerak');
    }

    /**
     * KAFOLAT TESTI. `makeUser()`, `makeHouse()`, `makeObservation()` yordamchilari
     * yuqoridagi testda ham, boshqa hech qaysi testda ham chaqirilmagan edi — demak
     * bu yordamchilar orqali 3 ta ulanishga (auth, mahalla, va master orqali
     * o'qiladigan geo) YOZISH yo'li, va undan keyin `DatabaseTransactions` bu
     * yozuvlarni to'liq qaytarishi, hech qachon haqiqatda tekshirilmagan edi.
     * Qaytarilmasa umumiy dev bazasiga (`kbt`) axlat qolib ketadi. Shu test
     * yozuvni haqiqatan amalga oshirib, har birini test ICHIDA tasdiqlaydi;
     * qaytarilishning o'zi tashqi jarayon (tinker) bilan alohida tekshiriladi.
     */
    public function test_write_helpers_work_across_connections(): void
    {
        $user = $this->makeUser('viloyat');
        $this->assertDatabaseHas('users', ['id' => $user->id], 'auth');

        [$mahallaId, $streetId] = $this->mahallaWithStreet();
        $houseId = $this->makeHouse($mahallaId, $streetId);
        $this->assertDatabaseHas('houses', [
            'id' => $houseId,
            'mahalla_id' => $mahallaId,
            'street_id' => $streetId,
        ], 'mahalla');

        $this->makeObservation($houseId, 'facade', now(), true);
        $this->assertTrue(
            DB::connection('mahalla')->table('zone_observations')
                ->where('house_id', $houseId)->where('zone', 'facade')->exists(),
            'Kuzatuv yozuvi mahalla ulanishida topilishi kerak'
        );
    }

    public function test_viloyat_role_sees_all_houses_but_is_not_admin(): void
    {
        $user = $this->makeUser('viloyat');
        $access = app(\App\Domains\Mahalla\Support\MahallaAccess::class);

        $scope = $access->scopeFor($user);

        $this->assertTrue($scope->canSeeAll, 'viloyat barcha honadonlarni ko\'rishi kerak');
        $this->assertFalse($scope->isAdmin, 'viloyat ADMIN emas');
        $this->assertFalse($scope->restrictToStreets);
    }

    public function test_deputat_role_is_restricted_to_streets(): void
    {
        $user = $this->makeUser('deputat');
        $scope = app(\App\Domains\Mahalla\Support\MahallaAccess::class)->scopeFor($user);

        $this->assertFalse($scope->canSeeAll);
        $this->assertTrue($scope->restrictToStreets);
    }

    /**
     * BO'SHLIQ 1: admin regressiyasi. Hozirgacha faqat `viloyat` va `deputat`
     * uchun test bor edi. `MahallaAccess::scopeFor()` dagi `admin` shoxi
     * (canSeeAll=true QO'SHIMCHA isAdmin=true bilan) sinovsiz qolgan — kimdir
     * uni buzsa (masalan faqat isAdmin qaytarib, canSeeAll ni unutsa), admin
     * `House::scopeVisibleTo()` orqali HECH NARSA ko'rmay qoladi va buni
     * hech qaysi test tutmagan bo'lardi.
     */
    public function test_admin_role_sees_all_houses_and_is_admin(): void
    {
        $user = $this->makeUser('admin');
        $scope = app(\App\Domains\Mahalla\Support\MahallaAccess::class)->scopeFor($user);

        $this->assertTrue($scope->isAdmin, 'admin ADMIN bo\'lishi kerak (boshqaruv huquqi)');
        $this->assertTrue($scope->canSeeAll, 'admin barcha honadonlarni ko\'rishi kerak (ko\'rish doirasi) — '.
            'isAdmin va canSeeAll ataylab ajratilgan, ikkalasi ham admin uchun true bo\'lishi shart');
        $this->assertFalse($scope->restrictToStreets);
    }

    /**
     * BO'SHLIQ 2: `House::scopeVisibleTo()` haqiqiy so'rov darajasida sinovsiz
     * edi — mavjud testlar faqat `MahallaScope` obyektining xossalarini
     * tekshiradi, filtr SQL darajasida to'g'ri ishlashini emas. `MahallaScope`
     * ni to'g'ridan-to'g'ri (user'siz) yaratib, `House::visibleTo()` so'rovini
     * uch holatda tekshiramiz.
     */
    public function test_house_visible_to_with_can_see_all_returns_all_houses(): void
    {
        $scope = new \App\Domains\Mahalla\Support\MahallaScope(true, null, null, [], false, true);

        // Qattiq songa bog'lanmaslik uchun kutilgan qiymatni bazadan hisoblaymiz:
        // canSeeAll=true bo'lganda visibleTo() umuman filtrlamasligi kerak,
        // demak natija umumiy honadonlar soniga teng bo'lishi shart.
        $expected = House::count();
        $actual = House::visibleTo($scope)->count();

        $this->assertSame($expected, $actual, 'canSeeAll=true bo\'lganda barcha honadonlar ko\'rinishi kerak');
    }

    public function test_house_visible_to_with_no_assigned_streets_returns_none(): void
    {
        $scope = new \App\Domains\Mahalla\Support\MahallaScope(false, null, null, [], true, false);

        $count = House::visibleTo($scope)->count();

        $this->assertSame(0, $count, 'streetIds bo\'sh bo\'lsa, ko\'cha-cheklovli userga hech qanday honadon ko\'rinmasligi kerak');
    }

    public function test_house_visible_to_with_specific_street_filters_correctly(): void
    {
        [$mahallaId1, $streetId1] = $this->mahallaWithStreet();
        [$mahallaId2, $streetId2] = $this->anotherStreetInDistrict($streetId1);

        $houseOnStreet1 = $this->makeHouse($mahallaId1, $streetId1);
        $houseOnStreet2 = $this->makeHouse($mahallaId2, $streetId2);

        $scope = new \App\Domains\Mahalla\Support\MahallaScope(false, null, null, [$streetId1], true, false);

        $visibleIds = House::visibleTo($scope)->pluck('id')->all();

        $this->assertContains($houseOnStreet1, $visibleIds, 'Biriktirilgan ko\'chadagi honadon ko\'rinishi kerak');
        $this->assertNotContains($houseOnStreet2, $visibleIds, 'Boshqa ko\'chadagi honadon ko\'rinmasligi kerak');
    }

    // `makeUser()` 1-vazifada bazaviy sinfda ta'riflangan — qayta yozilmaydi.

    public function test_household_denominator_comes_from_cadastre_not_houses_table(): void
    {
        $districtId = $this->districtId();
        $stats = app(\App\Domains\Mahalla\Services\ExecutiveStats::class)->district($districtId);

        // Kutilgan qiymat bazadan hisoblanadi — kadastr yangilansa test buzilmaydi.
        $expected = DB::connection('master')->table('buildings')
            ->where('district_id', $districtId)
            ->where('type', 'residential')
            ->whereNotNull('mahalla_id')
            ->count();

        $this->assertSame($expected, $stats['totals']['households'],
            'JAMI = mahallaga biriktirilgan turar-joy binolari yig\'indisi');

        $unassigned = DB::connection('master')->table('buildings')
            ->where('district_id', $districtId)
            ->where('type', 'residential')
            ->whereNull('mahalla_id')
            ->count();

        $this->assertSame($unassigned, $stats['unassigned_households']);

        // `houses` operatsion jadvali deyarli bo'sh — maxraj undan OLINMAYDI.
        $operational = DB::connection('mahalla')->table('houses')
            ->where('district_id', $districtId)->count();
        $this->assertGreaterThan($operational, $stats['totals']['households']);
    }

    public function test_same_house_changing_twice_counts_once(): void
    {
        [$mahallaId, $streetId] = $this->mahallaWithStreet();
        $stats = app(\App\Domains\Mahalla\Services\ExecutiveStats::class);

        $before = collect($stats->mahalla($mahallaId)['rows'])->firstWhere('zone', 'yard');

        $house = $this->makeHouse($mahallaId, $streetId);
        $this->makeObservation($house, 'yard', now()->subHours(3), isChange: true);
        $this->makeObservation($house, 'yard', now()->subHours(1), isChange: true);

        $after = collect($stats->mahalla($mahallaId)['rows'])->firstWhere('zone', 'yard');

        $this->assertSame(1, $after['week'] - $before['week'],
            'DISTINCT house_id — bitta uydagi ikki kuzatuv 1 marta sanaladi');
        $this->assertSame(1, $after['today'] - $before['today']);
    }

    public function test_unconfirmed_change_is_not_counted(): void
    {
        [$mahallaId, $streetId] = $this->mahallaWithStreet();
        $stats = app(\App\Domains\Mahalla\Services\ExecutiveStats::class);

        $before = collect($stats->mahalla($mahallaId)['rows'])->firstWhere('zone', 'facade');

        $house = $this->makeHouse($mahallaId, $streetId);
        $this->makeObservation($house, 'facade', now()->subHour(), isChange: false);

        $after = collect($stats->mahalla($mahallaId)['rows'])->firstWhere('zone', 'facade');

        $this->assertSame(0, $after['week'] - $before['week'],
            'tasdiqlanmagan (is_change=false) kuzatuv sanalmaydi');
    }

    public function test_change_outside_current_week_is_not_counted(): void
    {
        [$mahallaId, $streetId] = $this->mahallaWithStreet();
        $stats = app(\App\Domains\Mahalla\Services\ExecutiveStats::class);

        $before = collect($stats->mahalla($mahallaId)['rows'])->firstWhere('zone', 'toilet');

        $house = $this->makeHouse($mahallaId, $streetId);
        // Joriy hafta boshidan 1 soat OLDIN — hisobga kirmasligi kerak
        $weekStart = app(\App\Domains\Mahalla\Services\ExecutiveStats::class)
            ->period()['week_start_utc'];
        $this->makeObservation($house, 'toilet', $weekStart->copy()->subHour(), isChange: true);

        $after = collect($stats->mahalla($mahallaId)['rows'])->firstWhere('zone', 'toilet');

        $this->assertSame(0, $after['week'] - $before['week'],
            'o\'tgan haftadagi o\'zgarish joriy hafta hisobiga kirmaydi');
    }

    /**
     * I6: `changeQuery()` dagi `today_count` — `count(distinct case when
     * o.observed_at >= ? then o.house_id end)` — Toshkent yarim tuni chegarasini
     * ishlatadi (`period()['today_start_utc']`). Bu bog'lam hech qanday test
     * bilan qoplanmagan edi: chegara noto'g'ri joyga (masalan UTC yarim tuniga)
     * siljisa, raqam JIMGINA xato chiqadi — hech qanday xato yoki 500 bo'lmaydi,
     * shunchaki noto'g'ri son. Vaqtni qotirib, chegaraning ikki tomonidagi
     * kuzatuvlarni ANIQ vaqt bilan yaratamiz va delta (oldin/keyin farqi) orqali
     * tekshiramiz — mavjud testlar (masalan
     * `test_same_house_changing_twice_counts_once`) shu uslubga amal qiladi.
     */
    public function test_today_boundary_is_tashkent_midnight_not_utc(): void
    {
        // 2026-07-22 — chorshanba (Toshkent), hafta boshidan (dushanba,
        // 2026-07-20) yetarlicha uzoq — pastdagi ikkinchi kuzatuv haftaga
        // kiradi, lekin "bugun"ga kirmasligi kerak.
        Carbon::setTestNow(Carbon::parse('2026-07-22 10:00:00', 'Asia/Tashkent'));

        [$mahallaId, $streetId] = $this->mahallaWithStreet();
        $stats = app(\App\Domains\Mahalla\Services\ExecutiveStats::class);
        $todayStartUtc = $stats->period()['today_start_utc'];

        // 1) Mahalliy yarim tundan 30 daqiqa KEYIN — "бугун" hisobiga KIRISHI kerak.
        $beforeAfter = collect($stats->mahalla($mahallaId)['rows'])->firstWhere('zone', 'facade');
        $houseAfterMidnight = $this->makeHouse($mahallaId, $streetId);
        $this->makeObservation($houseAfterMidnight, 'facade', $todayStartUtc->copy()->addMinutes(30), isChange: true);
        $afterAfter = collect($stats->mahalla($mahallaId)['rows'])->firstWhere('zone', 'facade');

        $this->assertSame(1, $afterAfter['today'] - $beforeAfter['today'],
            'Mahalliy yarim tundan 30 daqiqa KEYINGI kuzatuv "бугун" hisobiga kirishi kerak');

        // 2) Mahalliy yarim tundan 30 daqiqa OLDIN — "бугун" hisobiga KIRMASLIGI kerak.
        $beforeBefore = collect($stats->mahalla($mahallaId)['rows'])->firstWhere('zone', 'yard');
        $houseBeforeMidnight = $this->makeHouse($mahallaId, $streetId);
        $this->makeObservation($houseBeforeMidnight, 'yard', $todayStartUtc->copy()->subMinutes(30), isChange: true);
        $afterBefore = collect($stats->mahalla($mahallaId)['rows'])->firstWhere('zone', 'yard');

        $this->assertSame(0, $afterBefore['today'] - $beforeBefore['today'],
            'Mahalliy yarim tundan 30 daqiqa OLDINGI kuzatuv "бугун" hisobiga kirmasligi kerak');

        Carbon::setTestNow();
    }

    public function test_every_mahalla_of_district_appears_in_rows(): void
    {
        $districtId = $this->districtId();
        $stats = app(\App\Domains\Mahalla\Services\ExecutiveStats::class)->district($districtId);

        // FAOL mahallalar sanaladi: nofaol (tugatilgan/qayta tashkil etilgan)
        // mahalla jadvalda ko'rinmasligi kerak, aks holda rahbar uni "ish
        // qilinmagan hudud" deb tushunardi.
        $expected = DB::connection('master')->table('mahallas')
            ->where('district_id', $districtId)
            ->where('is_active', true)
            ->count();

        $this->assertCount($expected, $stats['rows'],
            'kuzatuvi yo\'q FAOL mahalla ham jadvalda nol bilan ko\'rinishi kerak');

        foreach ($stats['rows'] as $row) {
            $this->assertArrayHasKey('yard', $row['zones']);
            $this->assertIsInt($row['zones']['yard']['week']);
        }
    }

    public function test_viloyat_can_open_executive_endpoint(): void
    {
        $user = $this->makeUser('viloyat');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/districts/'.$this->districtId())
            ->assertOk()
            ->assertJsonStructure([
                'district' => ['id', 'name', 'soato'],
                'period' => ['today', 'week_start', 'timezone'],
                'zones', 'rows', 'totals', 'unassigned_households',
            ]);
    }

    /**
     * BO'SHLIQ (3-vazifa ko'rigi): `MahallaDashboardController` javobiga
     * `dynamics`, `zone_status`, `recent_changes` qo'shilgan edi, lekin ularni
     * HTTP darajasida tekshiruvchi test yo'q edi — mavjud mahalla testlari
     * servisni (`ExecutiveMahallaStats`) to'g'ridan-to'g'ri chaqiradi,
     * kontroller o'zi ularni javobga qanday ulashini emas. Kalit noto'g'ri
     * yozilsa yoki maydon umuman qo'shilmasa (masalan `dynamics` qatori
     * o'chib qolsa), hech bir test tutmas edi. `assertJsonStructure` bilan
     * TO'LIQ javob shaklini (eski + yangi maydonlar) tasdiqlaymiz.
     *
     * Mutatsiya sinovi bilan tasdiqlangan: `MahallaDashboardController`dagi
     * `'dynamics' => ...` qatori vaqtincha izohga olinganda shu test YIQILADI
     * (batafsil: `.superpowers/sdd/task-3-report.md`).
     */
    public function test_viloyat_can_open_mahalla_executive_endpoint(): void
    {
        $user = $this->makeUser('viloyat');
        [$mahallaId] = $this->mahallaWithStreet();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/mahallas/'.$mahallaId)
            ->assertOk()
            ->assertJsonStructure([
                'mahalla' => ['id', 'name', 'district' => ['id', 'name']],
                'period' => ['today', 'week_start', 'timezone'],
                'households',
                'rows' => ['*' => ['zone', 'label', 'households', 'week', 'today']],
                'dynamics' => ['*' => ['date', 'count']],
                'zone_status' => ['*' => ['zone', 'label', 'households', 'statuses', 'unobserved']],
                'recent_changes',
            ]);

        $json = $response->json();

        // `dynamics` standart oynasi 30 kun — bo'sh kunlar ham tushmasligi kerak.
        $this->assertCount(30, $json['dynamics'], 'dynamics 30 ta kun qaytarishi kerak');

        // `zone_status` — 4 nazorat zonasi (facade, kitchen, toilet, yard).
        $this->assertCount(4, $json['zone_status'], 'zone_status zonalar soniga teng bo\'lishi kerak');

        $firstZone = $json['zone_status'][0];
        $this->assertIsInt($firstZone['unobserved'], 'unobserved butun son bo\'lishi kerak');
        $this->assertGreaterThanOrEqual(0, $firstZone['unobserved'], 'unobserved manfiy bo\'lishi mumkin emas');
    }

    /**
     * O'TGAN KO'RIKDAN QARZ: 2-vazifa ko'rigida `EnsureMahallaViewer` uchun
     * test yo'qligi qayd etilgan edi. `admin` ham `VIEWER_ROLES` ichida —
     * shuni ham HTTP darajasida tekshiramiz, faqat `viloyat` bilan
     * cheklanib qolmaslik uchun.
     */
    public function test_admin_can_open_executive_endpoint(): void
    {
        $user = $this->makeUser('admin');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/districts/'.$this->districtId())
            ->assertOk()
            ->assertJsonStructure([
                'district' => ['id', 'name', 'soato'],
                'period' => ['today', 'week_start', 'timezone'],
                'zones', 'rows', 'totals', 'unassigned_households',
            ]);
    }

    public function test_viloyat_cannot_touch_admin_endpoints(): void
    {
        $user = $this->makeUser('viloyat');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/mahalla/admin/users', ['name' => 'X'])
            ->assertForbidden();
    }

    /**
     * I3: `MahallaAccess::scopeFor()` `viloyat` uchun `canSeeAll=true` qaytaradi
     * (qarang: `test_viloyat_role_sees_all_houses_but_is_not_admin`), demak
     * `StorePhotoRequest`/`StoreObservationRequest` ichidagi
     * `House::visibleTo($scope)` tekshiruvi endi HAR QANDAY uyda/binoda `true`.
     * Viloyatni yozishdan to'xtatib turgan YAGONA qatlam — `photos.upload`
     * ruxsatining yo'qligi (`MahallaAccess::PERMISSIONS['viloyat']` da yo'q).
     * Himoya ikki qavatdan bir qavatga tushgan, va u qavat HTTP darajasida
     * hech qachon tekshirilmagan edi — shu ikki test buni yopadi.
     */
    public function test_viloyat_cannot_upload_house_photo(): void
    {
        $user = $this->makeUser('viloyat');
        [$mahallaId, $streetId] = $this->mahallaWithStreet();
        $houseId = $this->makeHouse($mahallaId, $streetId);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/mahalla/houses/{$houseId}/photos", [])
            ->assertForbidden();
    }

    public function test_viloyat_cannot_store_building_observation(): void
    {
        $user = $this->makeUser('viloyat');

        // `authorize()` `photos.upload` tekshiruvida so'rov ma'lumotlaridan
        // OLDIN rad etadi — shuning uchun bo'sh body yetarli, lekin route model
        // binding uchun bino HAQIQATAN mavjud bo'lishi shart (aks holda 404,
        // 403 emas). Shovot kadastridan bittasini olamiz.
        $buildingId = DB::connection('master')->table('buildings')
            ->where('district_id', $this->districtId())
            ->value('id');

        if ($buildingId === null) {
            $this->markTestSkipped('Shovot kadastr binolari topilmadi.');
        }

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/mahalla/buildings/{$buildingId}/observations", [])
            ->assertForbidden();
    }

    public function test_deputat_cannot_open_executive_endpoint(): void
    {
        $user = $this->makeUser('deputat');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/districts/'.$this->districtId())
            ->assertForbidden();
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/mahalla/executive/districts/'.$this->districtId())
            ->assertUnauthorized();
    }

    /**
     * TUZATISH: noto'g'ri formatdagi UUID marshrutda `whereUuid()` bilan
     * rad etilishi kerak — bazaga yetmasdan toza 404. Avval cheklov yo'q edi,
     * shu satr PostgreSQL drayveriga borib QueryException (500, stack trace
     * + xom SQL sizib chiqadi) bilan portlagan edi.
     */
    public function test_invalid_uuid_on_district_endpoint_returns_404_not_500(): void
    {
        $user = $this->makeUser('viloyat');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/districts/not-a-valid-uuid')
            ->assertNotFound();
    }

    public function test_invalid_uuid_on_mahalla_endpoint_returns_404_not_500(): void
    {
        $user = $this->makeUser('viloyat');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/mahallas/not-a-valid-uuid')
            ->assertNotFound();
    }

    /**
     * `{district?}` ixtiyoriyligi `whereUuid()` cheklovi qo'shilgandan keyin
     * ham buzilmasligi kerak — parametrsiz so'rov standart tumanga (Shovot)
     * tushishda davom etadi.
     */
    public function test_district_endpoint_without_parameter_still_uses_default_district(): void
    {
        $user = $this->makeUser('viloyat');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/districts')
            ->assertOk()
            ->assertJsonStructure([
                'district' => ['id', 'name', 'soato'],
                'period' => ['today', 'week_start', 'timezone'],
                'zones', 'rows', 'totals', 'unassigned_households',
            ]);
    }

    /** To'g'ri formatdagi, lekin mavjud bo'lmagan UUID — 404 (500 emas). */
    public function test_wellformed_but_nonexistent_uuid_returns_404(): void
    {
        $user = $this->makeUser('viloyat');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/districts/'.(string) Str::uuid())
            ->assertNotFound();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/mahallas/'.(string) Str::uuid())
            ->assertNotFound();
    }

    /**
     * I1: xarita (`ChangeMap`) va cardlar `row.changed` dan o'qiydi, jadval
     * esa `row.zones` dan — ikkalasi bir tilda gapirishi kerak. Avval
     * frontend `row.zones` ustunlarini QO'SHIB xarita raqamini hisoblardi —
     * to'rt zonasi o'zgargan bitta uy xaritada 4, cardda 1 bo'lardi. Bu test
     * backend `changed` maydonini to'g'ridan-to'g'ri tekshiradi: bitta uy
     * IKKI zonada o'zgarsa, `changed.week` 1 bo'lishi kerak (2 emas).
     */
    public function test_district_row_changed_counts_house_once_across_zones(): void
    {
        [$mahallaId, $streetId] = $this->mahallaWithStreet();
        $stats = app(\App\Domains\Mahalla\Services\ExecutiveStats::class);

        $rowBefore = collect($stats->district($this->districtId())['rows'])
            ->firstWhere('mahalla.id', $mahallaId);
        $changedBefore = $rowBefore['changed'];
        $zoneSumBefore = $rowBefore['zones']['yard']['week'] + $rowBefore['zones']['facade']['week'];

        $house = $this->makeHouse($mahallaId, $streetId);
        $this->makeObservation($house, 'yard', now()->subHours(2), isChange: true);
        $this->makeObservation($house, 'facade', now()->subHours(1), isChange: true);

        $rowAfter = collect($stats->district($this->districtId())['rows'])
            ->firstWhere('mahalla.id', $mahallaId);
        $changedAfter = $rowAfter['changed'];
        $zoneSumAfter = $rowAfter['zones']['yard']['week'] + $rowAfter['zones']['facade']['week'];

        $this->assertSame(1, $changedAfter['week'] - $changedBefore['week'],
            'ikki zonada o\'zgargan bitta uy changed.week da 1 marta sanaladi (2 emas)');
        $this->assertSame(1, $changedAfter['today'] - $changedBefore['today']);

        // Jadval kesimi (`zones`) esa ATAYLAB har zonani alohida sanaydi (+1 +1 = +2) —
        // `changed` bilan qasddan farqli, biri o'rniga ikkinchisi ishlatilmasin.
        $this->assertSame(2, $zoneSumAfter - $zoneSumBefore,
            'zones kesimi zona bo\'yicha alohida sanaydi — changed bilan ATAYLAB farqli');
    }

    public function test_summary_counts_households_once_across_zones(): void
    {
        // M3: `today_start_utc` = Toshkent yarim tuni = UTC 19:00. Test
        // UTC 19:00-21:00 oralig'ida yurgizilsa, pastdagi `subHours(2)`
        // kuzatuv "bugun" chegarasidan chiqib ketib test yiqilardi (kunlik
        // 2 soatlik oyna). Vaqtni Toshkent bo'yicha kun o'rtasiga qotiramiz —
        // naqsh `test_dynamics_groups_by_tashkent_day` dan.
        Carbon::setTestNow(Carbon::parse('2026-07-22 12:00:00', 'Asia/Tashkent'));

        [$mahallaId, $streetId] = $this->mahallaWithStreet();
        $stats = app(\App\Domains\Mahalla\Services\ExecutiveStats::class);

        $before = $stats->district($this->districtId())['summary'];

        // BITTA uy, IKKI xil zonada o'zgargan. Card "xonadon" sanaydi,
        // shuning uchun natija 2 emas, 1 bo'lishi shart.
        $house = $this->makeHouse($mahallaId, $streetId);
        $this->makeObservation($house, 'yard', now()->subHours(2), isChange: true);
        $this->makeObservation($house, 'facade', now()->subHours(1), isChange: true);

        $after = $stats->district($this->districtId())['summary'];

        $this->assertSame(1, $after['changed_week'] - $before['changed_week'],
            'ikki zonada o\'zgargan bitta uy 1 marta sanaladi');
        $this->assertSame(1, $after['changed_today'] - $before['changed_today']);

        Carbon::setTestNow();
    }

    public function test_summary_active_mahallas_counts_distinct_mahallas(): void
    {
        // M3: yuqoridagi izohga qarang — `subHour()` UTC yarim tunga yaqin
        // yurgizilsa "bugun" chegarasidan chiqib ketishi mumkin edi.
        Carbon::setTestNow(Carbon::parse('2026-07-22 12:00:00', 'Asia/Tashkent'));

        [$mahallaId, $streetId] = $this->mahallaWithStreet();
        $stats = app(\App\Domains\Mahalla\Services\ExecutiveStats::class);

        $before = $stats->district($this->districtId())['summary'];

        // Bitta mahallada IKKI uy o'zgardi -> faol mahalla +1 (2 emas)
        foreach ([1, 2] as $n) {
            $house = $this->makeHouse($mahallaId, $streetId);
            $this->makeObservation($house, 'yard', now()->subHour(), isChange: true);
        }

        $after = $stats->district($this->districtId())['summary'];

        $this->assertSame(1, $after['active_mahallas'] - $before['active_mahallas']);
        $this->assertSame(
            DB::connection('master')->table('mahallas')->where('district_id', $this->districtId())->count(),
            $after['total_mahallas'],
        );

        Carbon::setTestNow();
    }

    /**
     * TUZATISH: avval bu test faqat ikkita holatni tekshirar edi — `flagged`+NULL
     * (sanaladi) va `auto_confirmed` (sanalmaydi) — lekin HECH QACHON
     * `flagged`+`reviewed_by` TO'LDIRILGAN qatorni yaratmagan edi. Natijada
     * `ExecutiveStats::pendingReviews()` dagi `->whereNull('o.reviewed_by')`
     * qatorini kimdir o'chirib tashlasa ham, test baribir yashil qolar edi —
     * chunki testdagi ikkala kuzatuvda ham `reviewed_by` allaqachon NULL edi,
     * ya'ni shart amalda sinovsiz edi. Pastdagi uchinchi kuzatuv (`bathroom`
     * emas, `yard` — mavjud zona kodi) aynan shu bo'shliqni yopadi: `flagged`,
     * lekin allaqachon ko'rib chiqilgan (`reviewed_by` TO'LDIRILGAN) —
     * `pending_reviews` ga KIRMASLIGI kerak.
     *
     * Mutatsiya sinovi bilan tasdiqlangan: `->whereNull('o.reviewed_by')`
     * qatori vaqtincha izohga olinganda shu test YIQILADI (batafsil:
     * `.superpowers/sdd/task-1-report.md`).
     */
    public function test_summary_pending_reviews_counts_only_unreviewed_flagged(): void
    {
        [$mahallaId, $streetId] = $this->mahallaWithStreet();
        $stats = app(\App\Domains\Mahalla\Services\ExecutiveStats::class);

        $before = $stats->district($this->districtId())['summary']['pending_reviews'];

        $house = $this->makeHouse($mahallaId, $streetId);
        // flagged + tekshirilmagan -> sanaladi
        $this->makeObservation($house, 'toilet', now()->subHour(), isChange: false);
        // auto_confirmed -> sanalmaydi
        $this->makeObservation($house, 'kitchen', now()->subHour(), isChange: true);
        // flagged, LEKIN reviewed_by TO'LDIRILGAN (allaqachon ko'rib chiqilgan) -> sanalmaydi.
        // Haqiqiy FK yo'q (`zone_observations.reviewed_by` oddiy uuid, constrained emas),
        // shuning uchun istalgan UUID yetadi — haqiqiy userga bog'lanish shart emas.
        $this->makeObservation($house, 'yard', now()->subHour(), isChange: false, reviewedBy: (string) Str::uuid());

        $after = $stats->district($this->districtId())['summary']['pending_reviews'];

        $this->assertSame(1, $after - $before, 'faqat flagged va reviewed_by IS NULL sanaladi');
    }

    public function test_geojson_returns_feature_collection_matching_table_rows(): void
    {
        $user = $this->makeUser('viloyat');
        $districtId = $this->districtId();

        $geo = $this->actingAs($user, 'sanctum')
            ->getJson("/api/mahalla/executive/districts/{$districtId}/geojson")
            ->assertOk()
            ->json();

        $this->assertSame('FeatureCollection', $geo['type']);

        $expected = DB::connection('master')->table('mahallas')
            ->where('district_id', $districtId)->count();
        $this->assertCount($expected, $geo['features']);

        $first = $geo['features'][0];
        $this->assertSame('Feature', $first['type']);
        $this->assertArrayHasKey('id', $first['properties']);
        $this->assertArrayHasKey('name', $first['properties']);
        $this->assertContains($first['geometry']['type'], ['Polygon', 'MultiPolygon']);

        // Frontend GeoJSON'ni jadval qatorlariga `id` bo'yicha bog'laydi —
        // identifikatorlar mos kelmasa xarita rangsiz qoladi.
        $tableIds = collect($this->actingAs($user, 'sanctum')
            ->getJson("/api/mahalla/executive/districts/{$districtId}")
            ->json('rows'))->pluck('mahalla.id')->sort()->values()->all();
        $geoIds = collect($geo['features'])->pluck('properties.id')->sort()->values()->all();

        $this->assertSame($tableIds, $geoIds);
    }

    public function test_geojson_is_forbidden_for_deputat(): void
    {
        $this->actingAs($this->makeUser('deputat'), 'sanctum')
            ->getJson("/api/mahalla/executive/districts/{$this->districtId()}/geojson")
            ->assertForbidden();
    }

    public function test_zone_status_reports_unobserved_households(): void
    {
        [$mahallaId, $streetId] = $this->mahallaWithStreet();
        $households = (int) DB::connection('master')->table('buildings')
            ->where('mahalla_id', $mahallaId)->where('type', 'residential')->count();

        $house = $this->makeHouse($mahallaId, $streetId);
        DB::connection('mahalla')->table('house_zone_states')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'house_id' => $house, 'zone' => 'yard', 'status' => 'completed',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $rows = app(\App\Domains\Mahalla\Services\ExecutiveMahallaStats::class)
            ->zoneStatus($mahallaId, $households);

        $yard = collect($rows)->firstWhere('zone', 'yard');

        // Bitta xonadon kuzatilgan -> qolgani "kuzatilmagan". Bu segment bo'lmasa
        // diagramma 100% yashil ko'rsatib rahbarni chalg'itardi.
        $this->assertSame($households - 1, $yard['unobserved']);
        $this->assertSame(1, collect($yard['statuses'])->firstWhere('status', 'completed')['count']);

        // Kuzatuvsiz zona ham qatorda bo'ladi, hammasi kuzatilmagan
        $kitchen = collect($rows)->firstWhere('zone', 'kitchen');
        $this->assertSame($households, $kitchen['unobserved']);
    }

    public function test_dynamics_returns_full_window_with_zero_days(): void
    {
        [$mahallaId] = $this->mahallaWithStreet();

        $rows = app(\App\Domains\Mahalla\Services\ExecutiveMahallaStats::class)
            ->dynamics($mahallaId, 30);

        // Bo'sh kunlar tushib qolsa "har kuni ish bo'lgan" degan yolg'on
        // taassurot qoladi — shuning uchun 30 kunning hammasi qaytadi.
        $this->assertCount(30, $rows);
        $this->assertSame(array_keys($rows), range(0, 29), 'ro\'yxat tartibli bo\'lishi kerak');
        $dates = array_column($rows, 'date');
        $this->assertSame($dates, array_values(array_unique($dates)));
        $this->assertTrue($dates === array_values(collect($dates)->sort()->all()), 'sanalar o\'sish tartibida');
    }

    public function test_dynamics_groups_by_tashkent_day(): void
    {
        [$mahallaId, $streetId] = $this->mahallaWithStreet();
        $tz = 'Asia/Tashkent';

        // Toshkent bo'yicha bugun 00:30 — UTC bo'yicha KECHA 19:30.
        // Guruhlash UTC bo'yicha bo'lsa, bu kuzatuv kechaga tushib qolardi.
        $local = \Illuminate\Support\Carbon::now($tz)->startOfDay()->addMinutes(30);
        \Illuminate\Support\Carbon::setTestNow($local->copy()->addHours(6));

        $house = $this->makeHouse($mahallaId, $streetId);
        $this->makeObservation($house, 'yard', $local->copy()->utc(), isChange: true);

        $rows = app(\App\Domains\Mahalla\Services\ExecutiveMahallaStats::class)
            ->dynamics($mahallaId, 30);

        $today = collect($rows)->firstWhere('date', $local->toDateString());
        $this->assertNotNull($today, 'bugungi kun oynada bo\'lishi kerak');
        $this->assertSame(1, $today['count']);

        \Illuminate\Support\Carbon::setTestNow();
    }

    /**
     * C1: `recentChanges()` avval `observed_at` ni xom PostgreSQL satri sifatida
     * qaytarardi (offsetsiz, masalan "2026-07-19 07:22:11"). Frontendda
     * `new Date(...)` bunday satrni MAHALLIY vaqt deb talqin qiladi (ECMAScript
     * qoidasi), lekin qiymat aslida UTC — natijada 5 soatlik xato (Toshkent
     * UTC+5). Endi ISO-8601 offset bilan qaytishi shart.
     */
    public function test_recent_changes_observed_at_is_iso8601_with_offset(): void
    {
        [$mahallaId, $streetId] = $this->mahallaWithStreet();
        $house = $this->makeHouse($mahallaId, $streetId);
        $this->makeObservation($house, 'facade', now()->subMinutes(5), isChange: true);

        $rows = app(\App\Domains\Mahalla\Services\ExecutiveMahallaStats::class)
            ->recentChanges($mahallaId, 10);

        $this->assertNotEmpty($rows);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $rows[0]['observed_at'],
            'observed_at ISO-8601 offset bilan qaytishi kerak, xom PostgreSQL satri emas',
        );
    }

    public function test_recent_changes_only_confirmed_newest_first(): void
    {
        [$mahallaId, $streetId] = $this->mahallaWithStreet();
        $house = $this->makeHouse($mahallaId, $streetId);

        $this->makeObservation($house, 'facade', now()->subDays(2), isChange: true);
        $this->makeObservation($house, 'toilet', now()->subDay(), isChange: false);
        $this->makeObservation($house, 'yard', now()->subHour(), isChange: true);

        $rows = app(\App\Domains\Mahalla\Services\ExecutiveMahallaStats::class)
            ->recentChanges($mahallaId, 10);

        $zones = array_column($rows, 'zone');
        $this->assertSame(['yard', 'facade'], array_slice($zones, 0, 2), 'yangisi birinchi');
        $this->assertNotContains('toilet', $zones, 'tasdiqlanmagan o\'zgarish kirmaydi');
    }

    // ---------------------------------------------------------------- yordamchi

    /** Shovot tumani (SOATO 1733230) — kadastr yuklanmagan bo'lsa test o'tkazib yuboriladi. */
    protected function districtId(): string
    {
        $id = DB::connection('master')->table('districts')
            ->where('soato_code', '1733230')->value('id');

        if ($id === null) {
            $this->markTestSkipped('Shovot kadastr ma\'lumoti yuklanmagan.');
        }

        return (string) $id;
    }

    /**
     * Shovot ichidan ko'chasi bor bitta mahalla.
     *
     * @return array{0: string, 1: string} [mahalla_id, street_id]
     */
    protected function mahallaWithStreet(): array
    {
        $row = DB::connection('master')->table('streets as s')
            ->join('mahallas as m', 'm.id', '=', 's.mahalla_id')
            ->where('m.district_id', $this->districtId())
            ->select('m.id as mahalla_id', 's.id as street_id')
            ->first();

        if ($row === null) {
            $this->markTestSkipped('Ko\'chasi bor mahalla topilmadi.');
        }

        return [(string) $row->mahalla_id, (string) $row->street_id];
    }

    /**
     * Shovot ichidan berilgan ko'chadan BOSHQA ko'cha (va uning mahallasi) —
     * `test_house_visible_to_with_specific_street_filters_correctly` uchun
     * ikkinchi, alohida ko'cha kerak.
     *
     * @return array{0: string, 1: string} [mahalla_id, street_id]
     */
    protected function anotherStreetInDistrict(string $excludeStreetId): array
    {
        $row = DB::connection('master')->table('streets as s')
            ->join('mahallas as m', 'm.id', '=', 's.mahalla_id')
            ->where('m.district_id', $this->districtId())
            ->where('s.id', '!=', $excludeStreetId)
            ->select('m.id as mahalla_id', 's.id as street_id')
            ->first();

        if ($row === null) {
            $this->markTestSkipped('Ikkinchi ko\'cha topilmadi.');
        }

        return [(string) $row->mahalla_id, (string) $row->street_id];
    }

    protected function makeHouse(string $mahallaId, string $streetId): string
    {
        $id = (string) Str::uuid();

        DB::connection('mahalla')->table('houses')->insert([
            'id' => $id,
            'district_id' => $this->districtId(),
            'mahalla_id' => $mahallaId,
            'street_id' => $streetId,
            'lat' => 41.5, 'lng' => 60.6, 'address' => 'ТЕСТ уй',
            'status' => 'not_started', 'progress_percent' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * @param  ?string  $reviewedBy  Ixtiyoriy — TO'LDIRILSA, kuzatuv "ko'rib chiqilgan"
     *                                hisoblanadi (`reviewed_by` NOT NULL). Standart holatda
     *                                (chaqiruvchi bermasa) `null` — avvalgi xatti-harakat
     *                                o'zgarmaydi, mavjud chaqiruvlar buzilmaydi.
     */
    protected function makeObservation(string $houseId, string $zone, Carbon $at, bool $isChange, ?string $reviewedBy = null): void
    {
        DB::connection('mahalla')->table('zone_observations')->insert([
            'id' => (string) Str::uuid(),
            'house_id' => $houseId, 'zone' => $zone,
            'observed_at' => $at, 'is_on_site' => true, 'photo_count' => 1,
            'is_change' => $isChange,
            'decision' => $isChange ? 'auto_confirmed' : 'flagged',
            'status' => 'needs_work',
            'reviewed_by' => $reviewedBy,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Markaziy auth'da user + mahalla tizimiga rol yaratadi. */
    protected function makeUser(string $role): User
    {
        $userId = (string) Str::uuid();
        $now = now();

        DB::connection('auth')->table('users')->insert([
            'id' => $userId, 'login' => 'test_'.substr($userId, 0, 8),
            'password' => bcrypt('secret'), 'name' => 'ТЕСТ фойдаланувчи',
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
}
