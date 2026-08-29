<?php

declare(strict_types=1);

namespace Tests\Feature\Ayollar;

use App\Domains\Ayollar\Models\Anketa;
use App\Domains\Ayollar\Models\BalanceSignature;
use App\Domains\Ayollar\Models\SensitiveAccessLog;
use App\Domains\Ayollar\Services\BalanceCalculator;
use App\Domains\Ayollar\Support\AyollarAccess;
use Illuminate\Support\Str;

/**
 * API xatti-harakati va XAVFSIZLIK CHEGARALARI.
 *
 * Bu yerdagi har test bitta savolga javob beradi: ma'lumot ko'rmasligi
 * kerak bo'lgan odamga chiqib ketadimi?
 */
class AyollarApiTest extends AyollarApiTestCase
{
    // ---------------------------------------------------------------
    // DOIRA (IDOR)
    // ---------------------------------------------------------------

    /** MFY faoli boshqa MFY anketalarini KO'RMAYDI. */
    public function test_activist_sees_only_own_mahalla(): void
    {
        [$ownMahalla, $otherMahalla, $district] = $this->twoMahallas();

        $this->anketaIn($ownMahalla, $district);
        $this->anketaIn($otherMahalla, $district);

        $user = $this->makeUser(AyollarAccess::ROLE_ACTIVIST, [
            'mahalla_id' => $ownMahalla, 'district_id' => $district,
        ]);

        $data = $this->actingAs($user, 'sanctum')->getJson('/api/ayollar/anketas')->assertOk()->json('data');

        $this->assertNotEmpty($data);
        foreach ($data as $row) {
            $this->assertSame($ownMahalla, $row['mahalla_id'], 'Begona MFY yozuvi ko‘rindi.');
        }
    }

    /** Tuman bo'limi boshqa TUMAN anketalarini ko'rmaydi. */
    public function test_district_role_sees_only_own_district(): void
    {
        $d1 = $this->someDistrictId();
        $d2 = $this->otherDistrictId($d1);

        $this->anketaIn($this->someMahallaId($d1), $d1);
        $this->anketaIn($this->someMahallaId($d2), $d2);

        $user = $this->makeUser(AyollarAccess::ROLE_FAMILY_DEPT, ['district_id' => $d1]);

        $data = $this->actingAs($user, 'sanctum')->getJson('/api/ayollar/anketas')->assertOk()->json('data');

        $this->assertNotEmpty($data);
        foreach ($data as $row) {
            $this->assertSame($d1, $row['district_id']);
        }
    }

    /**
     * Doirasiz (staff yozuvisiz) rol HECH NARSA ko'rmaydi.
     *
     * Bo'sh natija — ATAYLAB. Doirasi aniqlanmagan foydalanuvchiga butun
     * viloyatni ochib qo'yish eng xavfli sukut bo'lardi.
     */
    public function test_role_without_scope_sees_nothing(): void
    {
        $d = $this->someDistrictId();
        $this->anketaIn($this->someMahallaId($d), $d);

        $user = $this->makeUser(AyollarAccess::ROLE_ACTIVIST);

        $this->assertSame(
            [],
            $this->actingAs($user, 'sanctum')->getJson('/api/ayollar/anketas')->assertOk()->json('data'),
        );
    }

    /** Boshqa MFY balansini ochishga urinish 403. */
    public function test_cross_mahalla_balance_is_forbidden(): void
    {
        [$own, $other, $district] = $this->twoMahallas();

        $user = $this->makeUser(AyollarAccess::ROLE_CHAIRMAN, [
            'mahalla_id' => $own, 'district_id' => $district,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/ayollar/balances/mahalla/{$other}")
            ->assertForbidden();
    }

    // ---------------------------------------------------------------
    // PII
    // ---------------------------------------------------------------

    /** Reyestr javobida shifrlangan ustunlar UMUMAN yo'q. */
    public function test_registry_response_has_no_pii_columns(): void
    {
        $d = $this->someDistrictId();
        $m = $this->someMahallaId($d);
        $this->anketaIn($m, $d, pinfl: '31234567890201');

        $user = $this->makeUser(AyollarAccess::ROLE_FAMILY_DEPT, ['district_id' => $d]);

        $body = $this->actingAs($user, 'sanctum')
            ->getJson('/api/ayollar/anketas')->assertOk()->content();

        $this->assertStringNotContainsString('31234567890201', $body);
        $this->assertStringNotContainsString('pinfl_encrypted', $body);
        $this->assertStringNotContainsString('pinfl_hash', $body);
    }

    /** Maxfiy maydonni ochish JURNALGA tushadi. */
    public function test_reveal_pii_is_logged(): void
    {
        $d = $this->someDistrictId();
        $m = $this->someMahallaId($d);
        $woman = $this->makeWoman($this->makeHousehold($m, $d), 30, '31234567890202');

        $user = $this->makeUser(AyollarAccess::ROLE_FAMILY_DEPT, ['district_id' => $d]);

        $before = SensitiveAccessLog::query()->count();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/ayollar/women/{$woman->id}/reveal-pii", ['fields' => ['pinfl']])
            ->assertOk()
            ->assertJsonPath('values.pinfl', '31234567890202');

        $this->assertSame($before + 1, SensitiveAccessLog::query()->count(), 'Ochish jurnalga tushmadi.');

        $log = SensitiveAccessLog::query()->latest('accessed_at')->first();
        $this->assertSame((string) $user->id, (string) $log->user_id);
        $this->assertSame('pinfl', $log->field);
    }

    /** Faol PII ocha OLMAYDI — u ma'lumotni yig'adi, u bilan ishlamaydi. */
    public function test_activist_cannot_reveal_pii(): void
    {
        $d = $this->someDistrictId();
        $m = $this->someMahallaId($d);
        $woman = $this->makeWoman($this->makeHousehold($m, $d), 30, '31234567890203');

        $user = $this->makeUser(AyollarAccess::ROLE_ACTIVIST, ['mahalla_id' => $m, 'district_id' => $d]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/ayollar/women/{$woman->id}/reveal-pii", ['fields' => ['pinfl']])
            ->assertForbidden();
    }

    /** Qizil ro'yxat — FAQAT uch rolga. */
    public function test_red_list_restricted_to_three_roles(): void
    {
        $d = $this->someDistrictId();

        $allowed = [
            AyollarAccess::ROLE_CHAIRMAN,
            AyollarAccess::ROLE_HOKIM_ASSISTANT,
            AyollarAccess::ROLE_FAMILY_DEPT,
        ];

        foreach ($allowed as $role) {
            $scope = $role === AyollarAccess::ROLE_FAMILY_DEPT
                ? ['district_id' => $d]
                : ['mahalla_id' => $this->someMahallaId($d), 'district_id' => $d];

            $this->actingAs($this->makeUser($role, $scope), 'sanctum')
                ->getJson('/api/ayollar/red-list')
                ->assertOk();
        }

        foreach ([AyollarAccess::ROLE_ACTIVIST, AyollarAccess::ROLE_ANALYST, AyollarAccess::ROLE_ADMIN, AyollarAccess::ROLE_DISTRICT_ORG] as $role) {
            $this->actingAs($this->makeUser($role, ['district_id' => $d]), 'sanctum')
                ->getJson('/api/ayollar/red-list')
                ->assertForbidden();
        }
    }

    /** V bo'lim javoblari ruxsatsiz rolga BERILMAYDI. */
    public function test_sensitive_answers_are_stripped(): void
    {
        $d = $this->someDistrictId();
        $m = $this->someMahallaId($d);

        $anketa = $this->anketaIn($m, $d, answers: [
            'q11' => 'ishsiz', 'q12' => 'yoq', 'q13' => 'yoq',
            'q30' => ['probation' => true], 'q31' => ['violence' => true],
        ]);

        $analyst = $this->makeUser(AyollarAccess::ROLE_ANALYST, ['district_id' => $d]);

        $answers = $this->actingAs($analyst, 'sanctum')
            ->getJson("/api/ayollar/anketas/{$anketa->id}")
            ->assertOk()
            ->assertJsonPath('sensitive_hidden', true)
            ->json('answers');

        $this->assertArrayNotHasKey('q30', $answers);
        $this->assertArrayNotHasKey('q31', $answers);
    }

    // ---------------------------------------------------------------
    // QR — OCHIQ SAHIFA
    // ---------------------------------------------------------------

    /** QR sahifasi autentifikatsiyasiz ochiladi va PII ko'rsatmaydi. */
    public function test_public_qr_page_has_no_personal_data(): void
    {
        $d = $this->someDistrictId();
        $m = $this->someMahallaId($d);
        $anketa = $this->anketaIn($m, $d, pinfl: '31234567890204');
        $name = $anketa->woman->full_name;

        $body = $this->getJson("/api/ayollar/public/a/{$anketa->qr_token}?v={$anketa->qr_hmac}")
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('document.reg_number', $anketa->reg_number)
            ->content();

        $this->assertStringNotContainsString('31234567890204', $body, 'QR sahifasida JShShIR chiqdi.');
        $this->assertStringNotContainsString($name, $body, 'QR sahifasida ism chiqdi.');
    }

    /** Imzosiz yoki soxta imzoli havola OCHILMAYDI. */
    public function test_public_qr_requires_valid_signature(): void
    {
        $d = $this->someDistrictId();
        $anketa = $this->anketaIn($this->someMahallaId($d), $d);

        $this->getJson("/api/ayollar/public/a/{$anketa->qr_token}")->assertNotFound();
        $this->getJson("/api/ayollar/public/a/{$anketa->qr_token}?v=deadbeef")->assertNotFound();
    }

    /** Mavjud bo'lmagan token ham 404 — javob AYNAN bir xil. */
    public function test_unknown_token_and_bad_signature_are_indistinguishable(): void
    {
        $d = $this->someDistrictId();
        $anketa = $this->anketaIn($this->someMahallaId($d), $d);

        $unknown = $this->getJson('/api/ayollar/public/a/ZZZZZZ?v=deadbeef')->assertNotFound()->json();
        $badSig = $this->getJson("/api/ayollar/public/a/{$anketa->qr_token}?v=deadbeef")->assertNotFound()->json();

        $this->assertSame($unknown, $badSig, 'Javoblar farq qildi — token ro‘yxatlash mumkin bo‘lardi.');
    }

    // ---------------------------------------------------------------
    // OFFLINE NAVBAT
    // ---------------------------------------------------------------

    /** Bir xil `client_uuid` ikkinchi marta kelsa — DUBLIKAT, xato emas. */
    public function test_batch_is_idempotent(): void
    {
        $d = $this->someDistrictId();
        $m = $this->someMahallaId($d);
        $woman = $this->makeWoman($this->makeHousehold($m, $d), 30);

        $user = $this->makeUser(AyollarAccess::ROLE_ACTIVIST, ['mahalla_id' => $m, 'district_id' => $d]);
        $clientUuid = (string) Str::uuid();

        $payload = [
            'device_id' => 'TABLET-01',
            'items' => [[
                'client_uuid' => $clientUuid,
                'woman_id' => (string) $woman->id,
                'answers' => ['q11' => 'ishsiz', 'q12' => 'yoq', 'q13' => 'yoq'],
            ]],
        ];

        $first = $this->actingAs($user, 'sanctum')->postJson('/api/ayollar/anketas/batch', $payload)
            ->assertOk()->json('results.0');
        $this->assertSame('ok', $first['status']);

        $second = $this->actingAs($user, 'sanctum')->postJson('/api/ayollar/anketas/batch', $payload)
            ->assertOk()->json('results.0');
        $this->assertSame('duplicate', $second['status']);
        $this->assertSame($first['anketa_id'], $second['anketa_id']);

        $this->assertSame(1, Anketa::query()->where('client_uuid', $clientUuid)->count());
    }

    /** Paket 100 yozuvdan oshsa rad etiladi. */
    public function test_batch_limit_is_enforced(): void
    {
        $d = $this->someDistrictId();
        $m = $this->someMahallaId($d);
        $user = $this->makeUser(AyollarAccess::ROLE_ACTIVIST, ['mahalla_id' => $m, 'district_id' => $d]);

        $items = array_fill(0, 101, [
            'client_uuid' => (string) Str::uuid(),
            'woman_id' => (string) Str::uuid(),
            'answers' => ['q11' => 'ishsiz'],
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/ayollar/anketas/batch', ['items' => $items])
            ->assertStatus(422);
    }

    // ---------------------------------------------------------------
    // BALANS OQIMI
    // ---------------------------------------------------------------

    /** Yopishdan keyin 6 ta MFY imzo o'rni ochiladi. */
    public function test_closing_mahalla_balance_opens_signature_slots(): void
    {
        $d = $this->someDistrictId();
        $m = $this->someMahallaId($d);
        $this->anketaIn($m, $d);

        $balance = app(BalanceCalculator::class)->calculateMahalla($m, (int) now()->year, (int) now()->month);
        $user = $this->makeUser(AyollarAccess::ROLE_CHAIRMAN, ['mahalla_id' => $m, 'district_id' => $d]);

        $signatures = $this->actingAs($user, 'sanctum')
            ->postJson("/api/ayollar/balances/mahalla/{$balance->id}/close")
            ->assertOk()
            ->json('signatures');

        $this->assertCount(6, $signatures);
        $this->assertSame('pending', $signatures[0]['status']);
    }

    /** Yakuniy imzo (iqtisodiyot) oldingi 7 tasidan OLDIN ochilmaydi. */
    public function test_final_signature_is_locked_until_others_sign(): void
    {
        $d = $this->someDistrictId();
        $m = $this->someMahallaId($d);
        $this->anketaIn($m, $d);

        $calc = app(BalanceCalculator::class);
        $calc->calculateMahalla($m, (int) now()->year, (int) now()->month);
        $district = $calc->calculateDistrict($d, [$m], (int) now()->year, (int) now()->month);

        $family = $this->makeUser(AyollarAccess::ROLE_FAMILY_DEPT, ['district_id' => $d]);

        $state = $this->actingAs($family, 'sanctum')
            ->postJson("/api/ayollar/balances/district/{$district->id}/close")
            ->assertOk()
            ->json('signatures');

        $this->assertCount(8, $state);

        $final = collect($state)->firstWhere('org_code', BalanceSignature::FINAL_ORG);
        $this->assertTrue($final['locked'], 'Yakuniy imzo boshidanoq ochiq qoldi.');

        // Iqtisodiyot idorasi imzo qo'yishga urinsa — rad etiladi.
        $economy = $this->makeUser(AyollarAccess::ROLE_DISTRICT_ORG, [
            'district_id' => $d, 'org_code' => BalanceSignature::FINAL_ORG,
        ]);

        $this->actingAs($economy, 'sanctum')
            ->postJson("/api/ayollar/balances/district/{$district->id}/sign")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Yakuniy tasdiq oldingi 7 imzodan keyin ochiladi.');
    }

    /** Qaytarish sababsiz bo'lmaydi. */
    public function test_return_requires_reason(): void
    {
        $d = $this->someDistrictId();
        $m = $this->someMahallaId($d);
        $this->anketaIn($m, $d);

        $balance = app(BalanceCalculator::class)->calculateMahalla($m, (int) now()->year, (int) now()->month);
        $family = $this->makeUser(AyollarAccess::ROLE_FAMILY_DEPT, ['district_id' => $d]);

        $this->actingAs($family, 'sanctum')
            ->postJson("/api/ayollar/balances/mahalla/{$balance->id}/return", ['reason' => ''])
            ->assertStatus(422);
    }

    /** Balans shakli `metric_registry` dan generatsiya qilinadi. */
    public function test_balance_form_comes_from_registry(): void
    {
        $d = $this->someDistrictId();
        $m = $this->someMahallaId($d);
        $this->anketaIn($m, $d);

        $user = $this->makeUser(AyollarAccess::ROLE_CHAIRMAN, ['mahalla_id' => $m, 'district_id' => $d]);

        $form = $this->actingAs($user, 'sanctum')
            ->getJson("/api/ayollar/balances/mahalla/{$m}")
            ->assertOk()
            ->json('form');

        $codes = array_column($form, 'code');

        // §4.1 dagi «yo'qolgan» uch qator uchala shaklda ham bo'lishi kerak.
        foreach (['protection_order', 'divorced_widowed', 'social_registry'] as $code) {
            $this->assertContains($code, $codes, "«{$code}» shakldan tushib qoldi.");
        }
    }

    // ---------------------------------------------------------------
    // QOIDA FAYLI
    // ---------------------------------------------------------------

    /** Frontend uchun qoida fayli beriladi va u zinapoyani o'z ichiga oladi. */
    public function test_rules_endpoint_serves_ladder(): void
    {
        $d = $this->someDistrictId();
        $user = $this->makeUser(AyollarAccess::ROLE_ACTIVIST, [
            'mahalla_id' => $this->someMahallaId($d), 'district_id' => $d,
        ]);

        $rules = $this->actingAs($user, 'sanctum')->getJson('/api/ayollar/rules')->assertOk()->json();

        $this->assertCount(22, $rules['category_ladder']);
        $this->assertCount(13, $rules['red_flags']);
        $this->assertSame(23, $rules['incomplete']['step']);
    }

    /** Offline paket qoidalarni ham o'z ichiga oladi. */
    public function test_bootstrap_contains_rules_and_scope(): void
    {
        $d = $this->someDistrictId();
        $m = $this->someMahallaId($d);
        $user = $this->makeUser(AyollarAccess::ROLE_ACTIVIST, ['mahalla_id' => $m, 'district_id' => $d]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/ayollar/bootstrap')
            ->assertOk()
            ->assertJsonStructure(['rules', 'rules_version', 'districts', 'mahallas', 'metrics', 'scope'])
            ->assertJsonPath('scope.mahalla_id', $m);
    }
}
