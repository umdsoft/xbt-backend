<?php

declare(strict_types=1);

namespace Tests\Feature\Yoshlar;

use App\Domains\Yoshlar\Models\Organization;

/**
 * RBAC: yoshlar gvardiyasi (401/403), rol ruxsatlari, `/context` javobi.
 */
class YoshlarAccessTest extends YoshlarTestCase
{
    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/yoshlar/context')->assertStatus(401);
    }

    public function test_outsider_gets_403(): void
    {
        $this->actingAs($this->makeOutsider(), 'sanctum')
            ->getJson('/api/yoshlar/context')->assertStatus(403);
    }

    public function test_admin_sees_context(): void
    {
        $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->getJson('/api/yoshlar/context')
            ->assertOk()
            ->assertJsonPath('role', 'yoshlar_admin')
            ->assertJsonPath('sees_everything', true)
            ->assertJsonStructure([
                'user' => ['id', 'name', 'login'],
                'role', 'role_name', 'permissions', 'sees_everything', 'viewer_only',
                'scope' => ['org_id', 'org_name', 'org_type', 'district_id', 'sector_id'],
            ]);
    }

    public function test_district_role_scope_is_reported(): void
    {
        $districtId = $this->someDistrictId();
        $org = $this->makeOrganization(Organization::TYPE_TUMAN_YOSHLAR, ['district_id' => $districtId]);

        $this->actingAs($this->makeUser('yoshlar_bolim', $org->id), 'sanctum')
            ->getJson('/api/yoshlar/context')
            ->assertOk()
            ->assertJsonPath('scope.org_id', $org->id)
            ->assertJsonPath('scope.district_id', $districtId)
            ->assertJsonPath('scope.org_type', Organization::TYPE_TUMAN_YOSHLAR)
            ->assertJsonPath('sees_everything', false);
    }

    public function test_hokim_orinbosari_is_viewer_only(): void
    {
        $permissions = $this->actingAs($this->makeUser('yoshlar_hokim_orinbosari'), 'sanctum')
            ->getJson('/api/yoshlar/context')
            ->assertOk()
            ->assertJsonPath('viewer_only', true)
            ->json('permissions');

        $this->assertNotContains('yoshlar.pii.reveal', $permissions);
        $this->assertNotContains('yoshlar.youth.update', $permissions);
    }
}
