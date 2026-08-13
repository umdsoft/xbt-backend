<?php

declare(strict_types=1);

namespace Tests\Feature\Qurilish;

/**
 * RBAC: qurilish gvardiyasi (401/403), rol ruxsatlari, `/context` javobi.
 */
class QurilishAccessTest extends QurilishTestCase
{
    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/qurilish/context')->assertStatus(401);
    }

    public function test_outsider_gets_403(): void
    {
        $this->actingAs($this->makeOutsider(), 'sanctum')
            ->getJson('/api/qurilish/context')->assertStatus(403);
    }

    public function test_hokimlik_sees_context_with_view_permission(): void
    {
        $this->actingAs($this->makeUser('qurilish_hokimlik'), 'sanctum')
            ->getJson('/api/qurilish/context')
            ->assertOk()
            ->assertJsonPath('role', 'qurilish_hokimlik')
            ->assertJsonPath('sees_everything', true)
            ->assertJsonStructure([
                'user' => ['id', 'name', 'login'],
                'role', 'permissions', 'sees_everything',
                'scope' => ['organization_id', 'organization_name', 'district_id'],
                'reference' => ['programs', 'sectors', 'districts', 'stages', 'lifecycles', 'work_types'],
            ]);
    }

    public function test_viewer_roles_have_no_write_permissions(): void
    {
        foreach (['qurilish_hokimlik', 'qurilish_prokuratura'] as $role) {
            $perms = $this->actingAs($this->makeUser($role), 'sanctum')
                ->getJson('/api/qurilish/context')->assertOk()->json('permissions');

            $this->assertContains('qurilish.view', $perms);
            $this->assertNotContains('qurilish.object.create', $perms);
            $this->assertNotContains('qurilish.object.update', $perms);
            $this->assertNotContains('*', $perms);
        }
    }

    public function test_buyurtmachi_scope_is_reported(): void
    {
        $orgId = $this->makeOrganization('Синов буюртмачи', ['is_customer' => true]);

        $this->actingAs($this->makeUser('qurilish_buyurtmachi', $orgId), 'sanctum')
            ->getJson('/api/qurilish/context')
            ->assertOk()
            ->assertJsonPath('scope.organization_id', $orgId)
            ->assertJsonPath('scope.organization_name', 'Синов буюртмачи')
            ->assertJsonPath('sees_everything', false);
    }

    public function test_admin_has_wildcard_permission(): void
    {
        $this->actingAs($this->makeUser('qurilish_admin'), 'sanctum')
            ->getJson('/api/qurilish/context')
            ->assertOk()
            ->assertJsonPath('permissions', ['*']);
    }

    public function test_reference_contains_programs_stages_districts(): void
    {
        $res = $this->actingAs($this->makeUser('qurilish_admin'), 'sanctum')
            ->getJson('/api/qurilish/context')->assertOk();

        $this->assertGreaterThanOrEqual(8, count($res->json('reference.programs')));
        $this->assertGreaterThanOrEqual(20, count($res->json('reference.sectors')));
        $this->assertCount(8, $res->json('reference.stages'));
        $this->assertGreaterThanOrEqual(13, count($res->json('reference.districts')));
        $this->assertSame('designer_selection', $res->json('reference.stages.0.code'));
        $this->assertSame('handover', $res->json('reference.stages.7.code'));
    }
}
