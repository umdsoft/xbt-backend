<?php

declare(strict_types=1);

namespace Tests\Feature\Qurilish;

use App\Domains\Qurilish\Models\AdminAuditLog;
use App\Domains\Qurilish\Models\ConstructionObject;
use App\Domains\Qurilish\Models\Program;
use App\Domains\Qurilish\Models\QurilishProfile;
use App\Domains\Qurilish\Models\Sector;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tizim moderatori ish o'rni: hisoblar, dasturlar, sohalar.
 *
 * Asosiy kafolat — VAKOLATLAR BO'LINISHI: hisob ochuvchi odam qurilish
 * bosqichini tasdiqlay olmaydi va obyekt ma'lumotini o'zgartira olmaydi.
 * Aks holda u o'ziga buyurtmachi hisobi ochib, o'zi yuborib, o'zi
 * tasdiqlay olardi.
 */
class QurilishAdminTest extends QurilishObjectTestCase
{
    // ---------- Vakolatlar ----------

    public function test_moderator_cannot_moderate_stages_or_edit_objects(): void
    {
        $moderator = $this->makeUser('qurilish_moderator');
        $object = $this->makeObject(['name' => $this->tag('A')]);

        $this->actingAs($moderator, 'sanctum')
            ->postJson("/api/qurilish/objects/{$object->id}/stages/designer_selection/approve")
            ->assertStatus(403);

        $this->actingAs($moderator, 'sanctum')
            ->patchJson("/api/qurilish/objects/{$object->id}", ['note' => 'ўзгартириш'])
            ->assertStatus(403);
    }

    public function test_only_moderator_and_admin_reach_admin_endpoints(): void
    {
        foreach (['qurilish_hokimlik', 'qurilish_prokuratura', 'qurilish_boshqarma'] as $role) {
            $this->actingAs($this->makeUser($role), 'sanctum')
                ->getJson('/api/qurilish/admin/users')->assertStatus(403);
        }

        $this->actingAs($this->makeUser('qurilish_moderator'), 'sanctum')
            ->getJson('/api/qurilish/admin/users')->assertOk();

        $this->actingAs($this->makeUser('qurilish_admin'), 'sanctum')
            ->getJson('/api/qurilish/admin/users')->assertOk();
    }

    // ---------- Hisoblar ----------

    public function test_creating_a_user_returns_password_once_and_grants_access(): void
    {
        $login = 'test_'.Str::lower(Str::random(8));

        $res = $this->actingAs($this->makeUser('qurilish_moderator'), 'sanctum')
            ->postJson('/api/qurilish/admin/users', [
                'login' => $login,
                'name' => 'Синов ҳисоби',
                'role' => 'qurilish_prokuratura',
            ])->assertStatus(201);

        // Parol javobda BOR va u yagona marta ko'rsatiladi.
        $this->assertNotEmpty($res->json('password'));
        $this->assertSame('qurilish_prokuratura', $res->json('data.role'));
        $this->assertTrue($res->json('data.is_active'));

        $created = User::query()->where('login', $login)->firstOrFail();

        // Kirish huquqi ham, profil ham yaratilgan bo'lishi shart: bittasi
        // yetishmasa foydalanuvchi kiradi-yu hech nima ko'rmaydi.
        $this->assertTrue(
            DB::connection('auth')->table('user_system_access')
                ->where('user_id', $created->id)->where('is_active', true)->exists(),
        );
        $this->assertNotNull(QurilishProfile::query()->where('user_id', $created->id)->first());
    }

    public function test_password_is_never_written_to_the_audit_log(): void
    {
        $login = 'test_'.Str::lower(Str::random(8));

        $this->actingAs($this->makeUser('qurilish_moderator'), 'sanctum')
            ->postJson('/api/qurilish/admin/users', [
                'login' => $login,
                'name' => 'Синов ҳисоби',
                'role' => 'qurilish_hokimlik',
                'password' => 'ParolNiTekshir_2026',
            ])->assertStatus(201);

        $log = AdminAuditLog::query()->where('entity_label', $login)->firstOrFail();

        $this->assertSame('user_create', $log->action);
        $this->assertStringNotContainsString(
            'ParolNiTekshir_2026',
            json_encode($log->changes, JSON_UNESCAPED_UNICODE) ?: '',
        );
    }

    public function test_customer_role_requires_an_organization(): void
    {
        $moderator = $this->makeUser('qurilish_moderator');

        // Tashkilotsiz buyurtmachi HECH NARSA ko'rmaydi — shuning uchun
        // bunday hisob umuman yaratilmaydi.
        $this->actingAs($moderator, 'sanctum')
            ->postJson('/api/qurilish/admin/users', [
                'login' => 'test_'.Str::lower(Str::random(8)),
                'name' => 'Ташкилотсиз буюртмачи',
                'role' => 'qurilish_buyurtmachi',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('organization_id');
    }

    public function test_duplicate_login_is_rejected(): void
    {
        $moderator = $this->makeUser('qurilish_moderator');
        $login = 'test_'.Str::lower(Str::random(8));

        $payload = ['login' => $login, 'name' => 'Биринчи ҳисоб', 'role' => 'qurilish_hokimlik'];

        $this->actingAs($moderator, 'sanctum')->postJson('/api/qurilish/admin/users', $payload)->assertStatus(201);
        $this->actingAs($moderator, 'sanctum')->postJson('/api/qurilish/admin/users', $payload)
            ->assertStatus(422)->assertJsonValidationErrors('login');
    }

    public function test_password_reset_changes_the_stored_hash(): void
    {
        $moderator = $this->makeUser('qurilish_moderator');
        $target = $this->makeUser('qurilish_hokimlik');
        $before = User::query()->findOrFail($target->id)->password;

        $res = $this->actingAs($moderator, 'sanctum')
            ->postJson("/api/qurilish/admin/users/{$target->id}/password")
            ->assertOk();

        $this->assertNotEmpty($res->json('password'));
        $this->assertNotSame($before, User::query()->findOrFail($target->id)->password);
    }

    public function test_user_can_be_blocked_but_not_self(): void
    {
        $moderator = $this->makeUser('qurilish_moderator');
        $target = $this->makeUser('qurilish_hokimlik');

        $this->actingAs($moderator, 'sanctum')
            ->postJson("/api/qurilish/admin/users/{$target->id}/active", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        // Bloklangan hisob domenga kira olmasligi kerak.
        $this->actingAs($target->fresh(), 'sanctum')
            ->getJson('/api/qurilish/context')->assertStatus(403);

        // O'zini bloklash — tizimni administratorsiz qoldirishi mumkin.
        $this->actingAs($moderator, 'sanctum')
            ->postJson("/api/qurilish/admin/users/{$moderator->id}/active", ['is_active' => false])
            ->assertStatus(422);
    }

    // ---------- Dasturlar va sohalar ----------

    public function test_program_crud_with_unique_code(): void
    {
        $moderator = $this->makeUser('qurilish_moderator');
        $code = 'test_prog_'.Str::lower(Str::random(6));

        $res = $this->actingAs($moderator, 'sanctum')
            ->postJson('/api/qurilish/admin/programs', [
                'code' => $code, 'name' => 'Синов дастури', 'year' => 2026,
            ])->assertStatus(201);

        $id = $res->json('data.id');

        $this->actingAs($moderator, 'sanctum')
            ->postJson('/api/qurilish/admin/programs', ['code' => $code, 'name' => 'Иккинчи'])
            ->assertStatus(422)->assertJsonValidationErrors('code');

        $this->actingAs($moderator, 'sanctum')
            ->patchJson("/api/qurilish/admin/programs/{$id}", ['name' => 'Янгиланган дастур'])
            ->assertOk();

        $this->assertSame('Янгиланган дастур', Program::query()->findOrFail($id)->name_cyr);

        // Ishlatilmagan dastur haqiqatan o'chadi.
        $this->actingAs($moderator, 'sanctum')
            ->deleteJson("/api/qurilish/admin/programs/{$id}")->assertOk();
        $this->assertNull(Program::query()->find($id));
    }

    public function test_code_must_be_machine_safe(): void
    {
        $this->actingAs($this->makeUser('qurilish_moderator'), 'sanctum')
            ->postJson('/api/qurilish/admin/sectors', ['code' => 'Соҳа Коди', 'name' => 'Синов'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_sector_in_use_is_disabled_not_deleted(): void
    {
        $moderator = $this->makeUser('qurilish_moderator');
        $code = 'test_sect_'.Str::lower(Str::random(6));

        $id = $this->actingAs($moderator, 'sanctum')
            ->postJson('/api/qurilish/admin/sectors', ['code' => $code, 'name' => 'Ишлатилаётган соҳа'])
            ->assertStatus(201)->json('data.id');

        $this->makeObject(['name' => $this->tag('S'), 'sector_id' => $id]);

        $this->actingAs($moderator, 'sanctum')
            ->deleteJson("/api/qurilish/admin/sectors/{$id}")->assertOk();

        // Yozuv saqlanadi — aks holda unga bog'langan obyektlar «sohasiz»
        // qolib, jamlanmalar jim buzilardi.
        $sector = Sector::query()->find($id);
        $this->assertNotNull($sector);
        $this->assertFalse((bool) $sector->is_active);
        $this->assertSame(1, ConstructionObject::query()->where('sector_id', $id)->count());
    }

    public function test_admin_actions_are_logged(): void
    {
        $moderator = $this->makeUser('qurilish_moderator');
        $code = 'test_sect_'.Str::lower(Str::random(6));

        $this->actingAs($moderator, 'sanctum')
            ->postJson('/api/qurilish/admin/sectors', ['code' => $code, 'name' => 'Журнал синови'])
            ->assertStatus(201);

        $res = $this->actingAs($moderator, 'sanctum')
            ->getJson('/api/qurilish/admin/audit')->assertOk();

        $row = collect($res->json('data'))->firstWhere('entity_label', 'Журнал синови');
        $this->assertNotNull($row);
        $this->assertSame('sector_create', $row['action']);
        $this->assertNotSame('—', $row['actor_name']);
    }
}
