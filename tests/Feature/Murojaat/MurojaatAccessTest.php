<?php

declare(strict_types=1);

namespace Tests\Feature\Murojaat;

/**
 * RBAC: murojaat gvardiyasi (403), ruxsatlar (import/manage), tuman scoping (IDOR).
 */
class MurojaatAccessTest extends MurojaatTestCase
{
    public function test_outsider_forbidden(): void
    {
        $this->actingAs($this->makeOutsider(), 'sanctum')
            ->getJson('/api/murojaat/dashboard')->assertStatus(403);
    }

    public function test_viewer_cannot_import_but_can_view(): void
    {
        $viewer = $this->makeUser('murojaat_viewer', 'tuman', $this->someDistrictId());
        $this->actingAs($viewer, 'sanctum')->postJson('/api/murojaat/import', ['rows' => [$this->row()]])
            ->assertStatus(403);
        $this->actingAs($viewer, 'sanctum')->getJson('/api/murojaat/dashboard')->assertOk();
    }

    public function test_district_scoping(): void
    {
        $a = $this->someDistrictId();
        $b = $this->anotherDistrictId($a);
        $adminA = $this->makeUser('murojaat_admin', 'tuman', $a);
        $adminB = $this->makeUser('murojaat_admin', 'tuman', $b);
        $viloyat = $this->makeUser('murojaat_viloyat', 'viloyat', null);

        $this->actingAs($adminA, 'sanctum')->postJson('/api/murojaat/import', [
            'rows' => [$this->row(), $this->row()],
        ])->assertCreated();

        // B tumani A ning ma'lumotini KO'RMAYDI (o'z active sessiyasi yo'q).
        $this->actingAs($adminB, 'sanctum')->getJson('/api/murojaat/dashboard')
            ->assertOk()->assertJsonPath('empty', true);

        // Viloyat hammasini ko'radi.
        $this->actingAs($viloyat, 'sanctum')->getJson('/api/murojaat/dashboard')
            ->assertOk()->assertJsonPath('empty', false);
    }

    public function test_users_management_requires_manage(): void
    {
        $xodim = $this->makeUser('murojaat_xodim', 'tuman', $this->someDistrictId());
        $this->actingAs($xodim, 'sanctum')->getJson('/api/murojaat/users')->assertStatus(403);

        $viloyat = $this->makeUser('murojaat_viloyat', 'viloyat', null);
        $this->actingAs($viloyat, 'sanctum')->getJson('/api/murojaat/users')->assertOk()
            ->assertJsonStructure(['users']);
    }
}
