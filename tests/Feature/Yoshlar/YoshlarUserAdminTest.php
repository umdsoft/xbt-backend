<?php

declare(strict_types=1);

namespace Tests\Feature\Yoshlar;

use App\Domains\Yoshlar\Models\Organization;
use App\Domains\Yoshlar\Models\Staff;
use App\Domains\Yoshlar\Services\UserAdminService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hisob ochish: fail-closed tuzoqni oldini olish (org majburiyligi) va
 * rol/tashkilot turi mosligi.
 */
class YoshlarUserAdminTest extends YoshlarTestCase
{
    public function test_district_role_requires_organization(): void
    {
        $this->expectException(ValidationException::class);

        app(UserAdminService::class)->create('t_'.uniqid(), 'Sinov', 'yoshlar_bolim', null, null, false);
    }

    public function test_role_must_match_organization_type(): void
    {
        $viloyat = $this->makeOrganization(Organization::TYPE_VILOYAT_YOSHLAR);

        $this->expectException(ValidationException::class);

        // tuman roli — viloyat tashkilotiga biriktirilmoqda
        app(UserAdminService::class)->create('t_'.uniqid(), 'Sinov', 'yoshlar_bolim', $viloyat->id, null, false);
    }

    public function test_created_user_gets_staff_and_system_access(): void
    {
        $org = $this->makeOrganization(Organization::TYPE_TUMAN_YOSHLAR, [
            'district_id' => $this->someDistrictId(),
        ]);
        $login = 't_'.uniqid();

        $result = app(UserAdminService::class)->create($login, 'Sinov Xodim', 'yoshlar_bolim', $org->id, 'Boshliq', false);

        $this->assertNotEmpty($result['password']);
        $this->assertTrue(Staff::query()->where('user_id', $result['user_id'])->where('is_active', true)->exists());

        $role = DB::connection('auth')->table('user_system_access as usa')
            ->join('systems as s', 's.id', '=', 'usa.system_id')
            ->where('usa.user_id', $result['user_id'])->where('s.code', 'yoshlar')
            ->value('usa.role');

        $this->assertSame('yoshlar_bolim', $role);
    }

    public function test_duplicate_login_is_rejected(): void
    {
        $org = $this->makeOrganization(Organization::TYPE_TUMAN_YOSHLAR, [
            'district_id' => $this->someDistrictId(),
        ]);
        $login = 't_'.uniqid();

        app(UserAdminService::class)->create($login, 'Birinchi', 'yoshlar_bolim', $org->id, null, false);

        $this->expectException(ValidationException::class);

        app(UserAdminService::class)->create($login, 'Ikkinchi', 'yoshlar_bolim', $org->id, null, false);
    }

    public function test_only_admin_can_call_user_endpoint(): void
    {
        $this->actingAs($this->makeUser('yoshlar_boshqarma'), 'sanctum')
            ->getJson('/api/yoshlar/admin/users')
            ->assertStatus(403);

        $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->getJson('/api/yoshlar/admin/users')
            ->assertOk();
    }

    public function test_admin_can_create_user_via_api(): void
    {
        $org = $this->makeOrganization(Organization::TYPE_TUMAN_YOSHLAR, [
            'district_id' => $this->someDistrictId(),
        ]);

        $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->postJson('/api/yoshlar/admin/users', [
                'login' => 'api_'.uniqid(),
                'name' => 'API orqali',
                'role' => 'yoshlar_bolim',
                'org_id' => $org->id,
            ])
            ->assertCreated()
            ->assertJsonStructure(['user_id', 'password']);
    }
}
