<?php

declare(strict_types=1);

namespace Tests\Feature\Yoshlar;

use App\Domains\Yoshlar\Models\Organization;
use App\Domains\Yoshlar\Models\Youth;
use App\Models\User;

/**
 * Reyestr API: doira (IDOR), yozish huquqi, filtr va PII sizmasligi.
 */
class YoshlarYouthApiTest extends YoshlarTestCase
{
    public function test_district_role_does_not_see_other_district_youth(): void
    {
        $ownDistrict = $this->someDistrictId();
        $otherDistrict = $this->otherDistrictId($ownDistrict);

        $mine = $this->makeYouth($ownDistrict);
        $foreign = $this->makeYouth($otherDistrict);

        $ids = $this->actingAs($this->districtUser('yoshlar_bolim', $ownDistrict), 'sanctum')
            ->getJson('/api/yoshlar/youth?per_page=200')
            ->assertOk()
            ->json('data.*.id');

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($foreign->id, $ids);
    }

    public function test_district_role_cannot_open_foreign_youth(): void
    {
        $ownDistrict = $this->someDistrictId();
        $foreign = $this->makeYouth($this->otherDistrictId($ownDistrict));

        $this->actingAs($this->districtUser('yoshlar_bolim', $ownDistrict), 'sanctum')
            ->getJson("/api/yoshlar/youth/{$foreign->id}")
            ->assertStatus(404);
    }

    public function test_staffless_role_sees_empty_registry(): void
    {
        $this->makeYouth($this->someDistrictId());

        $this->actingAs($this->makeUser('yoshlar_bolim'), 'sanctum')
            ->getJson('/api/yoshlar/youth')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_pii_never_appears_in_list_response(): void
    {
        $district = $this->someDistrictId();
        $this->makeYouth($district, ['pinfl' => '3121212'.random_int(1000000, 9999999)]);

        $response = $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->getJson('/api/yoshlar/youth?per_page=200')
            ->assertOk();

        $this->assertStringNotContainsString('pinfl', $response->getContent());
    }

    public function test_district_role_can_create_verified_youth(): void
    {
        $district = $this->someDistrictId();

        $this->actingAs($this->districtUser('yoshlar_bolim', $district), 'sanctum')
            ->postJson('/api/yoshlar/youth', $this->payload($district))
            ->assertCreated()
            ->assertJsonPath('data.verification_status', 'verified');
    }

    public function test_sector_bolim_creates_pending_youth(): void
    {
        $district = $this->someDistrictId();

        $id = $this->actingAs($this->districtUser('sektor_bolim', $district), 'sanctum')
            ->postJson('/api/yoshlar/youth', $this->payload($district))
            ->assertCreated()
            ->assertJsonPath('data.verification_status', 'pending')
            ->json('data.id');

        // Tasdiqlanmagan yozuv umumiy reyestrda ko'rinmaydi.
        $ids = $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->getJson('/api/yoshlar/youth?per_page=200')->json('data.*.id');

        $this->assertNotContains($id, $ids);
    }

    public function test_sector_bolim_cannot_update_verified_youth(): void
    {
        $district = $this->someDistrictId();
        $youth = $this->makeYouth($district);

        $this->actingAs($this->districtUser('sektor_bolim', $district), 'sanctum')
            ->patchJson("/api/yoshlar/youth/{$youth->id}", ['phone' => '+998901234567'])
            ->assertStatus(403);
    }

    public function test_hokim_orinbosari_cannot_create(): void
    {
        $district = $this->someDistrictId();

        $this->actingAs($this->makeUser('yoshlar_hokim_orinbosari'), 'sanctum')
            ->postJson('/api/yoshlar/youth', $this->payload($district))
            ->assertStatus(403);
    }

    public function test_age_filter_uses_boundaries(): void
    {
        $district = $this->someDistrictId();
        $teen = $this->makeYouth($district, ['birth_date' => now()->subYears(15)->toDateString()]);
        $adult = $this->makeYouth($district, ['birth_date' => now()->subYears(29)->toDateString()]);

        $ids = $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->getJson('/api/yoshlar/youth?age_min=25&age_max=30&per_page=200')
            ->assertOk()->json('data.*.id');

        $this->assertContains($adult->id, $ids);
        $this->assertNotContains($teen->id, $ids);
    }

    public function test_duplicate_warning_when_pinfl_is_absent(): void
    {
        $district = $this->someDistrictId();
        $payload = $this->payload($district);
        $payload['last_name'] = 'Takrorov';
        $payload['first_name'] = 'Yosh';

        $user = $this->districtUser('yoshlar_bolim', $district);

        // Birinchi yozuv — ogohlantirish yo'q.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/yoshlar/youth', $payload)
            ->assertCreated()
            ->assertJsonPath('duplicate_warning', 0);

        // Ikkinchisi — bir xil FIO + sana + mahalla: ogohlantiradi, LEKIN saqlaydi.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/yoshlar/youth', $payload)
            ->assertCreated()
            ->assertJsonPath('duplicate_warning', 1);
    }

    public function test_search_works_in_cyrillic_for_latin_data(): void
    {
        $district = $this->someDistrictId();
        $youth = $this->makeYouth($district, ['last_name' => 'Qodirov', 'first_name' => 'Sardor']);

        $ids = $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->getJson('/api/yoshlar/youth?search=Қодиров&per_page=200')
            ->assertOk()->json('data.*.id');

        $this->assertContains($youth->id, $ids);
    }

    private function districtUser(string $role, string $districtId): User
    {
        $type = $role === 'sektor_bolim'
            ? Organization::TYPE_TUMAN_SEKTOR
            : Organization::TYPE_TUMAN_YOSHLAR;

        return $this->makeUser($role, $this->makeOrganization($type, ['district_id' => $districtId])->id);
    }

    /** @return array<string, mixed> */
    private function payload(string $districtId): array
    {
        return [
            'last_name' => 'Yangi',
            'first_name' => 'Yosh',
            'birth_date' => now()->subYears(19)->toDateString(),
            'gender' => 'erkak',
            'district_id' => $districtId,
            'mahalla_id' => $this->someMahallaId($districtId),
            'education_status' => 'oqimaydi',
            'employment_status' => 'band_emas',
        ];
    }

    /** @param array<string, mixed> $attrs */
    private function makeYouth(string $districtId, array $attrs = []): Youth
    {
        return Youth::query()->create(array_merge([
            'last_name' => 'Testov',
            'first_name' => 'Test',
            'birth_date' => now()->subYears(20)->toDateString(),
            'gender' => 'erkak',
            'district_id' => $districtId,
            'mahalla_id' => $this->someMahallaId($districtId),
        ], $attrs));
    }
}
