<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Maslahatchilar (hisoblar) boshqaruvi — FAQAT viloyat super-admin. Ro'yxat /
 * yaratish (parol generatsiya) / parol reset / faol-nofaol; bo'linma va tuman 403.
 */
class AdvisorAdminTest extends AdvisorTestCase
{
    public function test_viloyat_can_list_advisors(): void
    {
        $district = $this->someDistrictId();
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');
        $this->makeAdvisor('advisor_tuman', 'tuman', $district);

        $rows = $this->actingAs($viloyat, 'sanctum')->getJson('/api/advisor/users')
            ->assertOk()
            ->assertJsonStructure([['id', 'user_id', 'login', 'name', 'level', 'district', 'active']])
            ->json();

        $levels = collect($rows)->pluck('level')->all();
        $this->assertContains('viloyat', $levels);
        $this->assertContains('tuman', $levels);
        // Viloyat ro'yxatda birinchi (daraja tartibi).
        $this->assertSame('viloyat', $rows[0]['level']);
    }

    public function test_viloyat_can_create_tuman_advisor_with_generated_password(): void
    {
        $district = $this->someDistrictId();
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');

        $creds = $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/users', [
            'login' => 'yangi_tuman_adv',
            'name' => 'Янги туман маслаҳатчиси',
            'level' => 'tuman',
            'district_id' => $district,
        ])
            ->assertCreated()
            ->assertJsonStructure(['ok', 'credentials' => ['user_id', 'login', 'name', 'password']])
            ->json('credentials');

        $this->assertSame('yangi_tuman_adv', $creds['login']);
        $this->assertGreaterThanOrEqual(12, strlen($creds['password']));

        // User + rol + advisor profili yaratilган; parol generatsiya qilinган bilan mos.
        $user = User::on('auth')->where('login', 'yangi_tuman_adv')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check($creds['password'], $user->password));

        $role = DB::connection('auth')->table('user_system_access as usa')
            ->join('systems as s', 's.id', '=', 'usa.system_id')
            ->where('s.code', 'advisor')->where('usa.user_id', $user->id)->value('usa.role');
        $this->assertSame('advisor_tuman', $role);

        $advisor = DB::connection('advisor')->table('advisors')->where('user_id', $user->id)->first();
        $this->assertSame('tuman', $advisor->level);
        $this->assertSame($district, $advisor->district_id);
    }

    public function test_create_rejects_duplicate_login_and_tuman_without_district(): void
    {
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');
        $existingLogin = User::on('auth')->where('id', $viloyat->id)->value('login');

        $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/users', [
            'login' => $existingLogin,
            'name' => 'Дубликат',
            'level' => 'tuman',
            'district_id' => $this->someDistrictId(),
        ])->assertStatus(422);

        // Tuman darajasi uchun hudud majburiy.
        $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/users', [
            'login' => 'tuman_hududsiz',
            'name' => 'Ҳудудсиз туман',
            'level' => 'tuman',
        ])->assertStatus(422);
    }

    public function test_viloyat_can_reset_password_and_toggle_active(): void
    {
        $district = $this->someDistrictId();
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);
        $oldHash = User::on('auth')->where('id', $tuman->id)->value('password');

        $creds = $this->actingAs($viloyat, 'sanctum')
            ->postJson('/api/advisor/users/'.$tuman->id.'/reset-password')
            ->assertOk()
            ->json('credentials');

        $newHash = User::on('auth')->where('id', $tuman->id)->value('password');
        $this->assertNotSame($oldHash, $newHash);
        $this->assertTrue(Hash::check($creds['password'], $newHash));

        // Faol-nofaol.
        $this->actingAs($viloyat, 'sanctum')
            ->patchJson('/api/advisor/users/'.$tuman->id, ['active' => false])
            ->assertOk();
        $this->assertFalse((bool) User::on('auth')->where('id', $tuman->id)->value('is_active'));
    }

    public function test_tuman_and_bolinma_cannot_manage_advisors(): void
    {
        $district = $this->someDistrictId();
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);
        $bolinma = $this->makeAdvisor('advisor_bolinma', 'bolinma');

        $this->actingAs($tuman, 'sanctum')->getJson('/api/advisor/users')->assertForbidden();
        $this->actingAs($bolinma, 'sanctum')->getJson('/api/advisor/users')->assertForbidden();
        $this->actingAs($tuman, 'sanctum')->postJson('/api/advisor/users', [
            'login' => 'xxx', 'name' => 'X', 'level' => 'tuman', 'district_id' => $district,
        ])->assertForbidden();
    }
}
