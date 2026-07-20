<?php

declare(strict_types=1);

namespace Tests\Feature\Mahalla;

use App\Domains\Mahalla\Services\RaisCadastre;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Маҳалла раиси кадастрни тузатади — фақат ЎЗ маҳалласида.
 *
 * Bu yerdagi eng katta xavf — qamrov. Rais yozish huquqiga ega, ya'ni
 * qamrov buzilsa u boshqa mahallaning ma'lumotini o'zgartira oladi.
 * Avvalgi auditda aynan shu sinf xatosi (IDOR) topilgan edi, shuning
 * uchun har yo'l alohida tekshiriladi.
 */
class RaisCadastreTest extends TestCase
{
    use DatabaseTransactions;

    public function test_rais_sees_only_own_mahalla_buildings(): void
    {
        [$mine, $other] = $this->twoMahallas();

        $result = app(RaisCadastre::class)->buildings($mine);

        foreach ($result['items'] as $item) {
            $belongs = DB::connection('master')->table('buildings')
                ->where('id', $item['id'])->where('mahalla_id', $mine)->exists();
            $this->assertTrue($belongs, 'Бегона маҳалла биноси рўйхатга тушди');
        }

        $otherResult = app(RaisCadastre::class)->buildings($other);
        $mineIds = array_column($result['items'], 'id');

        foreach ($otherResult['items'] as $item) {
            $this->assertNotContains($item['id'], $mineIds);
        }
    }

    /**
     * Eng muhim test: begona mahalla binosini tuzatishga urinish RAD ETILADI.
     *
     * `classify()` mahallani o'zi tekshiradi — chaqiruvchiga ishonilmaydi.
     */
    public function test_classify_rejects_building_from_another_mahalla(): void
    {
        [$mine, $other] = $this->twoMahallas();

        $foreign = DB::connection('master')->table('buildings')
            ->where('mahalla_id', $other)
            ->where('type', '!=', 'residential')
            ->first(['id', 'object_type_id']);

        if ($foreign === null) {
            $this->markTestSkipped('Иккинчи маҳаллада нотурар бино йўқ');
        }

        $type = DB::connection('master')->table('object_types')->where('code', 'maktab')->value('id');

        $ok = app(RaisCadastre::class)->classify(
            (string) $foreign->id, $mine, (string) $type, (string) Str::uuid(),
        );

        $this->assertFalse($ok, 'Бегона маҳалла биноси тузатилди — қамров бузилган');

        $after = DB::connection('master')->table('buildings')->where('id', $foreign->id)->first(['object_type_id']);
        $this->assertSame($foreign->object_type_id, $after->object_type_id, 'Тури ўзгариб кетди');
    }

    public function test_classify_updates_type_and_writes_history(): void
    {
        [$mine] = $this->twoMahallas();

        $building = DB::connection('master')->table('buildings')
            ->where('mahalla_id', $mine)->where('type', '!=', 'residential')
            ->first(['id', 'object_type_id']);

        if ($building === null) {
            $this->markTestSkipped('Маҳаллада нотурар бино йўқ');
        }

        $type = DB::connection('master')->table('object_types')->where('code', 'maktab')->value('id');
        $userId = (string) Str::uuid();

        $ok = app(RaisCadastre::class)->classify((string) $building->id, $mine, (string) $type, $userId, 'синов');

        $this->assertTrue($ok);
        $this->assertSame(
            $type,
            DB::connection('master')->table('buildings')->where('id', $building->id)->value('object_type_id'),
        );

        // Tarix yozilishi SHART: kim nimani o'zgartirgani bilinmasa,
        // noto'g'ri tuzatishni topib bo'lmaydi.
        $change = DB::connection('master')->table('building_type_changes')
            ->where('building_id', $building->id)->where('user_id', $userId)
            ->orderByDesc('created_at')->first();

        $this->assertNotNull($change, 'Ўзгариш тарихга ёзилмади');
        $this->assertSame($building->object_type_id, $change->from_type_id);
        $this->assertSame($type, $change->to_type_id);
        $this->assertSame('синов', $change->note);
    }

    public function test_classify_rejects_unknown_type(): void
    {
        [$mine] = $this->twoMahallas();

        $building = DB::connection('master')->table('buildings')
            ->where('mahalla_id', $mine)->where('type', '!=', 'residential')->first(['id']);

        if ($building === null) {
            $this->markTestSkipped('Маҳаллада нотурар бино йўқ');
        }

        $this->assertFalse(app(RaisCadastre::class)->classify(
            (string) $building->id, $mine, (string) Str::uuid(), (string) Str::uuid(),
        ));
    }

    /**
     * BO'SHLIQ: `overview` endpointini HTTP darajasida sinamaganim uchun
     * u prod'da 500 bergan edi (`object_types.is_active` ustuni yo'q).
     * Servis testlari o'tgan, chunki ular kontrollerni chetlab o'tadi.
     */
    public function test_overview_endpoint_returns_full_mahalla_data(): void
    {
        $rais = $this->makeRaisWithMahalla();

        $body = $this->actingAs($rais, 'sanctum')
            ->getJson('/api/mahalla/rais/overview')
            ->assertOk()
            ->assertJsonStructure([
                'mahalla' => ['id', 'name', 'soato', 'district' => ['id', 'name']],
                'households', 'social_objects', 'recent_changes',
                'object_types' => [['id', 'code', 'name', 'is_social']],
            ])
            ->json();

        $this->assertNotEmpty($body['object_types'], 'Тур рўйхати бўш - танлаб бўлмайди');
        $this->assertArrayHasKey('indicators', $body);
    }

    public function test_buildings_endpoint_works_for_rais(): void
    {
        $this->actingAs($this->makeRaisWithMahalla(), 'sanctum')
            ->getJson('/api/mahalla/rais/buildings')
            ->assertOk()
            ->assertJsonStructure(['total', 'items']);
    }

    /** Profilida mahalla ko'rsatilmagan rais tushunarli xabar oladi, 500 emas. */
    public function test_rais_without_mahalla_gets_clear_message(): void
    {
        $this->actingAs($this->makeUser('rais'), 'sanctum')
            ->getJson('/api/mahalla/rais/overview')
            ->assertStatus(409)
            ->assertJsonStructure(['message']);
    }

    /**
     * BO'SHLIQ: `WorklistController` faqat `streetIds` bo'yicha filtrlagan va
     * `restrictToStreets` ni e'tiborga olmagan. Rais ko'chaga biriktirilmagani
     * uchun unga BO'SH ro'yxat qaytardi — xato yo'q, natija ham yo'q.
     */
    public function test_rais_sees_own_mahalla_households_in_worklist(): void
    {
        $rais = $this->makeRaisWithMahalla();
        $mahallaId = DB::connection('mahalla')->table('users')->where('id', $rais->id)->value('mahalla_id');

        $expected = DB::connection('master')->table('buildings')
            ->where('mahalla_id', $mahallaId)
            ->where('type', 'residential')
            ->whereNotNull('street_id')
            ->count();

        if ($expected === 0) {
            $this->markTestSkipped('Маҳаллада турар-жой биноси йўқ');
        }

        $body = $this->actingAs($rais, 'sanctum')
            ->getJson('/api/mahalla/worklist')
            ->assertOk()
            ->json();

        $this->assertSame($expected, $body['meta']['total'], 'Раис ўз маҳалласининг ҲАММА хонадонини кўриши керак');
    }

    /** Ro'yxat va bitta yozuv bir xil qamrovda: aks holda IDOR. */
    public function test_rais_cannot_open_building_from_another_mahalla(): void
    {
        $rais = $this->makeRaisWithMahalla();
        $mine = DB::connection('mahalla')->table('users')->where('id', $rais->id)->value('mahalla_id');

        $foreign = DB::connection('master')->table('buildings')
            ->where('mahalla_id', '!=', $mine)
            ->where('type', 'residential')
            ->whereNotNull('street_id')
            ->value('id');

        if ($foreign === null) {
            $this->markTestSkipped('Бошқа маҳаллада турар-жой биноси йўқ');
        }

        $this->actingAs($rais, 'sanctum')
            ->getJson('/api/mahalla/buildings/'.$foreign)
            ->assertNotFound();
    }

    /** Xarita uchun chegara va ijtimoiy nuqtalar javobda bo'lishi shart. */
    public function test_overview_carries_boundary_and_social_points(): void
    {
        $rais = $this->makeRaisWithMahalla();

        $body = $this->actingAs($rais, 'sanctum')
            ->getJson('/api/mahalla/rais/overview')
            ->assertOk()
            ->assertJsonStructure(['boundary' => ['type', 'features'], 'social_points'])
            ->json();

        $this->assertSame('FeatureCollection', $body['boundary']['type']);

        // Chegarasi bor mahallada aynan BITTA feature bo'lishi kerak —
        // ko'p bo'lsa boshqa mahalla ham tushib qolgan.
        $mahallaId = DB::connection('mahalla')->table('users')->where('id', $rais->id)->value('mahalla_id');
        $hasBoundary = DB::connection('master')->table('mahallas')
            ->where('id', $mahallaId)->whereNotNull('boundary')->exists();

        $this->assertCount($hasBoundary ? 1 : 0, $body['boundary']['features']);

        foreach ($body['social_points'] as $p) {
            $this->assertNotNull($p['lat']);
            $this->assertNotNull($p['lng']);
        }
    }

    public function test_viewer_role_cannot_reach_rais_endpoints(): void
    {
        // `viloyat` — ko'rish roli. Kadastr tuzatish YOZISH amali, shuning
        // uchun u bu bo'limga kira olmasligi kerak.
        $this->actingAs($this->makeUser('viloyat'), 'sanctum')
            ->getJson('/api/mahalla/rais/overview')
            ->assertForbidden();

        $this->actingAs($this->makeUser('deputat'), 'sanctum')
            ->getJson('/api/mahalla/rais/buildings')
            ->assertForbidden();
    }

    /**
     * Turar-joy binolari ro'yxatga tushmaydi — ular xonadon, ijtimoiy
     * obyekt emas. 34 ming qatorni ko'rsatish ro'yxatni yaroqsiz qiladi.
     */
    public function test_residential_buildings_are_excluded(): void
    {
        [$mine] = $this->twoMahallas();

        foreach (app(RaisCadastre::class)->buildings($mine)['items'] as $item) {
            $type = DB::connection('master')->table('buildings')->where('id', $item['id'])->value('type');
            $this->assertNotSame('residential', $type);
        }
    }

    /** @return array{0: string, 1: string} */
    private function twoMahallas(): array
    {
        $ids = DB::connection('master')->table('buildings')
            ->whereNotNull('mahalla_id')
            ->where('type', '!=', 'residential')
            ->distinct()
            ->limit(2)
            ->pluck('mahalla_id')
            ->all();

        if (count($ids) < 2) {
            $this->markTestSkipped('Базада нотурар бинога эга иккита маҳалла йўқ');
        }

        return [(string) $ids[0], (string) $ids[1]];
    }

    /** Mahallaga biriktirilgan rais — `mahalla.users` profilida mahalla bo'lishi shart. */
    private function makeRaisWithMahalla(): User
    {
        $user = $this->makeUser('rais');
        [$mahallaId] = $this->twoMahallas();
        $districtId = DB::connection('master')->table('mahallas')->where('id', $mahallaId)->value('district_id');

        DB::connection('mahalla')->table('users')->insert([
            'id' => $user->id, 'name' => 'Синов раис', 'login' => 'r_'.substr((string) $user->id, 0, 8),
            'password' => bcrypt('secret'), 'district_id' => $districtId, 'mahalla_id' => $mahallaId,
            'position' => 'rais', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    /** Foydalanuvchi markaziy `auth` sxemasida, roli `user_system_access` da. */
    private function makeUser(string $role): User
    {
        $userId = (string) Str::uuid();
        $now = now();

        DB::connection('auth')->table('users')->insert([
            'id' => $userId, 'login' => 'test_'.substr($userId, 0, 8),
            'password' => bcrypt('secret'), 'name' => 'ТЕСТ фойдаланувчи',
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        DB::connection('auth')->table('user_system_access')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'system_id' => DB::connection('auth')->table('systems')->where('code', 'mahalla')->value('id'),
            'role' => $role,
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return User::on('auth')->findOrFail($userId);
    }
}
