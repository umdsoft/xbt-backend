<?php

declare(strict_types=1);

namespace Tests\Feature\Yoshlar;

use App\Domains\Yoshlar\Models\Organization;
use App\Domains\Yoshlar\Models\Youth;
use App\Models\User;

/**
 * Tasdiqlash sikli: kim tasdiqlaydi, kim tasdiqlay olmaydi, natija nima.
 */
class YoshlarVerificationTest extends YoshlarTestCase
{
    public function test_district_youth_office_can_verify(): void
    {
        $district = $this->someDistrictId();
        $youth = $this->pendingYouth($district);
        $user = $this->youthOfficer($district);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/yoshlar/youth/{$youth->id}/verify")
            ->assertOk()
            ->assertJsonPath('data.verification_status', 'verified');

        $this->assertSame($user->id, $youth->fresh()->verified_by);
    }

    public function test_admin_cannot_verify(): void
    {
        $youth = $this->pendingYouth($this->someDistrictId());

        $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->postJson("/api/yoshlar/youth/{$youth->id}/verify")
            ->assertStatus(403);
    }

    public function test_reject_requires_reason_and_stores_it(): void
    {
        $district = $this->someDistrictId();
        $youth = $this->pendingYouth($district);
        $user = $this->youthOfficer($district);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/yoshlar/youth/{$youth->id}/reject", [])
            ->assertStatus(422);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/yoshlar/youth/{$youth->id}/reject", ['reason' => 'Mahalla noto‘g‘ri'])
            ->assertOk()
            ->assertJsonPath('data.verification_status', 'rejected');

        $this->assertSame('Mahalla noto‘g‘ri', $youth->fresh()->reject_reason);
    }

    public function test_pending_queue_is_visible_to_district_office(): void
    {
        $district = $this->someDistrictId();
        $youth = $this->pendingYouth($district);

        $ids = $this->actingAs($this->youthOfficer($district), 'sanctum')
            ->getJson('/api/yoshlar/youth?verification_status=pending&per_page=200')
            ->assertOk()->json('data.*.id');

        $this->assertContains($youth->id, $ids);
    }

    public function test_verify_outside_own_district_is_forbidden(): void
    {
        $own = $this->someDistrictId();
        $youth = $this->pendingYouth($this->otherDistrictId($own));

        $this->actingAs($this->youthOfficer($own), 'sanctum')
            ->postJson("/api/yoshlar/youth/{$youth->id}/verify")
            ->assertStatus(404);
    }

    private function youthOfficer(string $districtId): User
    {
        return $this->makeUser('yoshlar_bolim', $this->makeOrganization(
            Organization::TYPE_TUMAN_YOSHLAR, ['district_id' => $districtId],
        )->id);
    }

    private function pendingYouth(string $districtId): Youth
    {
        return Youth::query()->create([
            'last_name' => 'Kutilayotgan',
            'first_name' => 'Yosh',
            'birth_date' => now()->subYears(18)->toDateString(),
            'gender' => 'ayol',
            'district_id' => $districtId,
            'mahalla_id' => $this->someMahallaId($districtId),
            'verification_status' => 'pending',
        ]);
    }
}
