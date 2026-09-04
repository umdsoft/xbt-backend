<?php

declare(strict_types=1);

namespace Tests\Feature\Ayollar;

use App\Domains\Ayollar\Services\CadastreDirectory;
use App\Domains\Ayollar\Services\StaffProvisioner;
use App\Domains\Ayollar\Support\AyollarAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * KADASTR MA'LUMOTNOMASI VA HISOB OCHISH.
 *
 * Ikki yangi imkoniyat bitta faylda, chunki ular BIR manbaga
 * tayanadi: `master` sxemasidagi geografiya. Planshet manzil
 * tanlashda, administrator esa hisob ochishda AYNAN SHU ro'yxatdan
 * o'qiydi — ikki alohida manba bo'lganda ular vaqt o'tib
 * ajralib ketardi.
 */
class CadastreAndStaffTest extends AyollarTestCase
{
    // ---------------------------------------------------------------
    // Kadastr ma'lumotnomasi
    // ---------------------------------------------------------------

    public function test_streets_are_listed_for_a_mahalla(): void
    {
        $mahallaId = $this->mahallaWithStreets();
        $streets = app(CadastreDirectory::class)->streets($mahallaId);

        $this->assertNotEmpty($streets, 'Sinov MFYsida kadastr ko‘chalari bo‘lishi kerak.');
        $this->assertArrayHasKey('houses', $streets[0]);
        $this->assertGreaterThan(0, $streets[0]['houses']);
    }

    /**
     * TURAR-JOY FILTRI.
     *
     * Kadastrda do'kon, garaj, transformator ham bor. Ularni ro'yxatda
     * ko'rsatish faolni chalg'itardi: u do'konga anketa to'ldirishga
     * urinmaydi, lekin ro'yxatni varaqlab vaqt yo'qotadi.
     */
    public function test_only_residential_buildings_are_offered(): void
    {
        $mahallaId = $this->mahallaWithStreets();
        $streets = app(CadastreDirectory::class)->streets($mahallaId);
        $houses = app(CadastreDirectory::class)->houses($mahallaId, $streets[0]['id']);

        $ids = array_column($houses, 'id');
        $this->assertNotEmpty($ids);

        $nonResidential = DB::connection('master')->table('buildings')
            ->whereIn('id', $ids)
            ->where('type', '!=', 'residential')
            ->count();

        $this->assertSame(0, $nonResidential, 'Turar-joy bo‘lmagan bino ro‘yxatga tushmasligi kerak.');
    }

    /**
     * UY RAQAMI TABIIY TARTIBDA.
     *
     * `house_number` — MATN ustuni va alifbo tartibi «2, 10, 2a» ni
     * buzadi. Faol ro'yxatni ko'chada yurgan tartibda kutadi.
     */
    public function test_house_numbers_are_naturally_sorted(): void
    {
        $mahallaId = $this->mahallaWithStreets();
        $streets = app(CadastreDirectory::class)->streets($mahallaId);
        $houses = app(CadastreDirectory::class)->houses($mahallaId, $streets[0]['id']);

        $numeric = [];

        foreach ($houses as $h) {
            $digits = preg_replace('/\D/', '', $h['house_number']);

            if ($digits !== '') {
                $numeric[] = (int) $digits;
            }
        }

        $sorted = $numeric;
        sort($sorted);

        $this->assertSame($sorted, $numeric, 'Uylar raqam bo‘yicha o‘sish tartibida kelishi kerak.');
    }

    // ---------------------------------------------------------------
    // Doira — IDOR himoyasi
    // ---------------------------------------------------------------

    /**
     * BOSHQA MFY KO'CHALARI YOPIQ.
     *
     * Manzil ro'yxati ham ma'lumot: u qaysi mahallada qancha uy
     * borligini ochadi va boshqa MFY faoliga ko'rinmasligi kerak.
     */
    public function test_activist_cannot_read_streets_of_another_mahalla(): void
    {
        $district = $this->someDistrictId();
        $mine = $this->someMahallaId($district);
        $other = $this->otherMahallaId($mine, $district);

        $user = $this->makeUser(AyollarAccess::ROLE_ACTIVIST, [
            'district_id' => $district,
            'mahalla_id' => $mine,
        ]);

        $this->actingAs($user, 'web')
            ->getJson("/api/ayollar/geo/mahallas/{$other}/streets")
            ->assertForbidden();
    }

    public function test_activist_gets_own_streets_without_parameter(): void
    {
        $district = $this->someDistrictId();
        $mahalla = $this->mahallaWithStreets();

        $user = $this->makeUser(AyollarAccess::ROLE_ACTIVIST, [
            'district_id' => (string) DB::connection('master')->table('mahallas')
                ->where('id', $mahalla)->value('district_id'),
            'mahalla_id' => $mahalla,
        ]);

        $this->actingAs($user, 'web')
            ->getJson('/api/ayollar/geo/streets')
            ->assertOk()
            ->assertJsonPath('mahalla_id', $mahalla)
            ->assertJsonStructure(['streets' => [['id', 'name', 'houses', 'filled']]]);

        unset($district);
    }

    // ---------------------------------------------------------------
    // Hisob ochish
    // ---------------------------------------------------------------

    /**
     * FAQAT ADMINISTRATOR.
     *
     * Hisob ochish — tizimni boshqarish amali. Faol yoki rais uni
     * bajara olsa, ular o'zlariga kengroq rol yozib olardi.
     */
    public function test_only_admin_can_manage_staff(): void
    {
        $activist = $this->makeUser(AyollarAccess::ROLE_ACTIVIST, [
            'district_id' => $this->someDistrictId(),
            'mahalla_id' => $this->someMahallaId(),
        ]);

        $this->actingAs($activist, 'web')->getJson('/api/ayollar/staff')->assertForbidden();
        $this->actingAs($activist, 'web')->postJson('/api/ayollar/staff', [])->assertForbidden();
    }

    public function test_admin_creates_an_activist_with_mahalla(): void
    {
        $admin = $this->makeUser(AyollarAccess::ROLE_ADMIN);
        $mahalla = $this->someMahallaId();
        $login = 'sinov_'.Str::lower(Str::random(6));

        $response = $this->actingAs($admin, 'web')->postJson('/api/ayollar/staff', [
            'login' => $login,
            'name' => 'Sinov Faol Ismi',
            'phone' => '+998900000000',
            'role' => AyollarAccess::ROLE_ACTIVIST,
            'mahalla_id' => $mahalla,
        ])->assertCreated();

        // Parol javobda BIR MARTA qaytadi — keyin uni olib bo'lmaydi.
        $password = $response->json('password');
        $this->assertNotEmpty($password);

        $userId = $response->json('id');

        // Uchala jadval ham to'ldirilgan bo'lishi SHART: bittasi
        // tushib qolsa, hisob yarim ishlaydi (kiradi, lekin bo'sh
        // ekran ko'radi yoki 403 oladi).
        $this->assertDatabaseHas('users', ['id' => $userId, 'login' => $login], 'auth');
        $this->assertDatabaseHas('staff', ['user_id' => $userId, 'mahalla_id' => $mahalla], 'ayollar');

        $systemId = $this->ayollarSystemId();
        $this->assertSame(1, DB::connection('auth')->table('user_system_access')
            ->where('user_id', $userId)->where('system_id', $systemId)->count());
    }

    /**
     * MFYSIZ FAOL — BO'SH EKRAN.
     *
     * MFY darajasidagi rolga MFY biriktirilmasa, foydalanuvchi kiradi
     * va hech narsa ko'rmaydi. Bu eng yomon xato turi: hech narsa
     * buzilmaydi, shunchaki ishlamaydi va sababi ko'rinmaydi.
     */
    public function test_mahalla_scoped_role_requires_a_mahalla(): void
    {
        $admin = $this->makeUser(AyollarAccess::ROLE_ADMIN);

        $this->actingAs($admin, 'web')->postJson('/api/ayollar/staff', [
            'login' => 'sinov_'.Str::lower(Str::random(6)),
            'name' => 'MFYsiz Faol',
            'role' => AyollarAccess::ROLE_ACTIVIST,
        ])->assertStatus(422);
    }

    /**
     * TUMAN MFY'DAN OLINADI.
     *
     * Administrator ikkalasini alohida tanlaganda ular mos kelmasligi
     * mumkin edi va foydalanuvchi «tumani boshqa, MFY'si boshqa»
     * holatga tushardi.
     */
    public function test_district_is_derived_from_the_chosen_mahalla(): void
    {
        $admin = $this->makeUser(AyollarAccess::ROLE_ADMIN);
        $district = $this->someDistrictId();
        $mahalla = $this->someMahallaId($district);
        $wrongDistrict = $this->otherDistrictId($district);

        $id = $this->actingAs($admin, 'web')->postJson('/api/ayollar/staff', [
            'login' => 'sinov_'.Str::lower(Str::random(6)),
            'name' => 'Tuman Sinovi',
            'role' => AyollarAccess::ROLE_ACTIVIST,
            'mahalla_id' => $mahalla,
            // Ataylab NOTO'G'RI tuman yuboramiz.
            'district_id' => $wrongDistrict,
        ])->assertCreated()->json('id');

        $this->assertDatabaseHas('staff', [
            'user_id' => $id,
            'mahalla_id' => $mahalla,
            'district_id' => $district,
        ], 'ayollar');
    }

    /**
     * O'CHIRISH YUMSHOQ.
     *
     * Anketalarda `created_by` shu foydalanuvchiga ishora qiladi va
     * uni yo'qotish «kim to'ldirgan?» savolini javobsiz qoldirardi.
     */
    public function test_deactivation_keeps_the_record(): void
    {
        $admin = $this->makeUser(AyollarAccess::ROLE_ADMIN);
        $userId = $this->makeUser(AyollarAccess::ROLE_ACTIVIST, [
            'district_id' => $this->someDistrictId(),
            'mahalla_id' => $this->someMahallaId(),
        ])->id;

        $this->actingAs($admin, 'web')->deleteJson("/api/ayollar/staff/{$userId}")->assertOk();

        $this->assertDatabaseHas('users', ['id' => $userId, 'is_active' => false], 'auth');
        $this->assertDatabaseHas('staff', ['user_id' => $userId, 'is_active' => false], 'ayollar');
    }

    /** Tasodifiy parolda chalkashadigan belgilar bo'lmasligi kerak. */
    public function test_generated_password_avoids_confusable_characters(): void
    {
        $provisioner = app(StaffProvisioner::class);

        for ($i = 0; $i < 20; $i++) {
            $password = $provisioner->randomPassword();

            $this->assertSame(12, strlen($password));
            $this->assertSame(0, preg_match('/[0O1lI]/', $password),
                "Parol qog‘ozga yozib beriladi — «{$password}» da chalkashadigan belgi bor.");
        }
    }

    // ---------------------------------------------------------------

    /** Kadastrda ko'chasi bor MFY — aks holda sinov ma'nosiz. */
    private function mahallaWithStreets(): string
    {
        $id = DB::connection('master')->table('streets')
            ->join('buildings', function ($j) {
                $j->on('buildings.street_id', '=', 'streets.id')
                    ->where('buildings.type', '=', 'residential');
            })
            ->where('streets.is_active', true)
            ->value('streets.mahalla_id');

        if ($id === null) {
            $this->markTestSkipped('Kadastr ma‘lumoti import qilinmagan.');
        }

        return (string) $id;
    }
}
