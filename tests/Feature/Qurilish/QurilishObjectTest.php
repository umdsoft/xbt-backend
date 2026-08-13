<?php

declare(strict_types=1);

namespace Tests\Feature\Qurilish;

use App\Domains\Qurilish\Models\ConstructionObject;
use App\Domains\Qurilish\Models\ObjectAuditLog;
use App\Domains\Qurilish\Models\ObjectStage;
use App\Domains\Qurilish\Models\Sector;

/**
 * Obyekt reyestri API: ro'yxat/filtr, kartochka, yaratish, tahrirlash,
 * IDOR himoyasi va audit jurnali.
 */
class QurilishObjectTest extends QurilishObjectTestCase
{
    // ---------- ro'yxat va ko'rish ----------

    public function test_viewer_sees_objects_list(): void
    {
        $this->makeObject(['name' => $this->tag('A')]);

        $res = $this->actingAs($this->makeUser('qurilish_hokimlik'), 'sanctum')
            ->getJson('/api/qurilish/objects?q='.$this->prefix)->assertOk();

        $this->assertSame(1, $res->json('meta.total'));
        $this->assertSame($this->tag('A'), $res->json('data.0.name'));
    }

    public function test_buyurtmachi_list_is_scoped_to_own_organization(): void
    {
        $mine = $this->makeOrganization('Меники', ['is_customer' => true]);
        $other = $this->makeOrganization('Бегона', ['is_customer' => true]);

        $this->makeObject(['name' => $this->tag('MINE'), 'customer_org_id' => $mine]);
        $this->makeObject(['name' => $this->tag('OTHER'), 'customer_org_id' => $other]);

        $res = $this->actingAs($this->makeUser('qurilish_buyurtmachi', $mine), 'sanctum')
            ->getJson('/api/qurilish/objects?q='.$this->prefix)->assertOk();

        $this->assertSame(1, $res->json('meta.total'));
        $this->assertSame($this->tag('MINE'), $res->json('data.0.name'));
    }

    public function test_foreign_object_returns_404_not_403(): void
    {
        // 403 obyektning MAVJUDLIGINI oshkor qilardi — 404 qaytishi shart.
        $mine = $this->makeOrganization('Меники', ['is_customer' => true]);
        $other = $this->makeOrganization('Бегона', ['is_customer' => true]);
        $foreign = $this->makeObject(['name' => $this->tag('F'), 'customer_org_id' => $other]);

        $this->actingAs($this->makeUser('qurilish_buyurtmachi', $mine), 'sanctum')
            ->getJson('/api/qurilish/objects/'.$foreign->id)->assertStatus(404);
    }

    public function test_show_returns_card_with_stages_and_tender_saving(): void
    {
        $object = $this->makeObject([
            'name' => $this->tag('CARD'),
            'limit_amount' => 12000, 'tender_amount' => 11500,
            'contract_amount' => 11500, 'disbursed_amount' => 5750,
        ]);

        $res = $this->actingAs($this->makeUser('qurilish_hokimlik'), 'sanctum')
            ->getJson('/api/qurilish/objects/'.$object->id)->assertOk();

        $this->assertCount(8, $res->json('data.stages'));
        // JSON'da 500.0 -> 500 bo'lib qaytadi: sonli solishtiruv (assertSame emas).
        $this->assertEqualsWithDelta(500, $res->json('data.tender_saving'), 0.001);
        $this->assertEqualsWithDelta(50, $res->json('data.progress_pct'), 0.001);
    }

    public function test_registry_id_hides_synthetic_keys(): void
    {
        $real = $this->makeObject(['name' => $this->tag('R'), 'external_id' => '9911334060102001']);
        $fake = $this->makeObject(['name' => $this->tag('S'), 'external_id' => 'dxsh:9911']);
        $user = $this->makeUser('qurilish_hokimlik');

        $this->assertSame('9911334060102001', $this->actingAs($user, 'sanctum')
            ->getJson('/api/qurilish/objects/'.$real->id)->json('data.registry_id'));
        $this->assertNull($this->actingAs($user, 'sanctum')
            ->getJson('/api/qurilish/objects/'.$fake->id)->json('data.registry_id'));
    }

    // ---------- filtrlar ----------

    public function test_overdue_filter(): void
    {
        $this->makeObject(['name' => $this->tag('LATE'), 'deadline_date' => now()->subDays(5), 'handover_done' => false]);
        $this->makeObject(['name' => $this->tag('OK'), 'deadline_date' => now()->addDays(5)]);
        $this->makeObject(['name' => $this->tag('DONE'), 'deadline_date' => now()->subDays(5), 'handover_done' => true]);

        $res = $this->actingAs($this->makeUser('qurilish_hokimlik'), 'sanctum')
            ->getJson('/api/qurilish/objects?overdue=1&q='.$this->prefix)->assertOk();

        $this->assertSame(1, $res->json('meta.total'));
        $this->assertSame($this->tag('LATE'), $res->json('data.0.name'));
    }

    public function test_sector_and_lifecycle_filters(): void
    {
        $sector = Sector::query()->where('code', 'mtt')->firstOrFail();
        $this->makeObject(['name' => $this->tag('M'), 'sector_id' => $sector->id, 'lifecycle' => 'jarayonda']);
        $this->makeObject(['name' => $this->tag('X'), 'lifecycle' => 'reja']);
        $user = $this->makeUser('qurilish_hokimlik');

        $this->assertSame(1, $this->actingAs($user, 'sanctum')
            ->getJson('/api/qurilish/objects?sector_id='.$sector->id.'&q='.$this->prefix)
            ->assertOk()->json('meta.total'));

        $this->assertSame(1, $this->actingAs($user, 'sanctum')
            ->getJson('/api/qurilish/objects?lifecycle=jarayonda&q='.$this->prefix)
            ->assertOk()->json('meta.total'));
    }

    // ---------- yaratish ----------

    public function test_boshqarma_creates_draft_object_bound_to_its_organization(): void
    {
        $dept = $this->makeOrganization('Бошқарма', ['is_department' => true]);

        $res = $this->actingAs($this->makeUser('qurilish_boshqarma', $dept), 'sanctum')
            ->postJson('/api/qurilish/objects', ['name' => $this->tag('NEW')])
            ->assertStatus(201);

        $object = ConstructionObject::query()->findOrFail($res->json('data.id'));

        // Dastursiz kiritilgan obyekt — kelajakdagi (qoralama).
        $this->assertSame('qoralama', $object->lifecycle);
        $this->assertSame($dept, $object->department_org_id);
        $this->assertSame('manual', $object->source);
        $this->assertSame(8, ObjectStage::query()->where('object_id', $object->id)->count());
    }

    public function test_viewer_roles_cannot_create_or_update(): void
    {
        $object = $this->makeObject(['name' => $this->tag('V')]);

        foreach (['qurilish_hokimlik', 'qurilish_prokuratura'] as $role) {
            $user = $this->makeUser($role);
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/qurilish/objects', ['name' => 'X'])->assertStatus(403);
            $this->actingAs($user, 'sanctum')
                ->patchJson('/api/qurilish/objects/'.$object->id, ['note' => 'X'])->assertStatus(403);
        }
    }

    public function test_buyurtmachi_cannot_create_objects(): void
    {
        $org = $this->makeOrganization('Буюртмачи', ['is_customer' => true]);

        $this->actingAs($this->makeUser('qurilish_buyurtmachi', $org), 'sanctum')
            ->postJson('/api/qurilish/objects', ['name' => 'X'])->assertStatus(403);
    }

    // ---------- tahrirlash ----------

    public function test_buyurtmachi_can_edit_finance_but_not_name(): void
    {
        $org = $this->makeOrganization('Буюртмачи', ['is_customer' => true]);
        $object = $this->makeObject(['name' => $this->tag('KEEP'), 'customer_org_id' => $org]);

        $this->actingAs($this->makeUser('qurilish_buyurtmachi', $org), 'sanctum')
            ->patchJson('/api/qurilish/objects/'.$object->id, [
                'name' => 'ЎЗГАРТИРИЛГАН',
                'disbursed_amount' => 999,
            ])->assertOk();

        $object->refresh();
        $this->assertSame($this->tag('KEEP'), $object->name, 'Nom o‘zgarmasligi kerak');
        $this->assertSame('999.000', $object->disbursed_amount);
    }

    public function test_assigning_program_promotes_draft_to_plan(): void
    {
        $dept = $this->makeOrganization('Бошқарма', ['is_department' => true]);
        $object = $this->makeObject([
            'name' => $this->tag('D'), 'department_org_id' => $dept, 'lifecycle' => 'qoralama',
        ]);
        $programId = \App\Domains\Qurilish\Models\Program::query()->where('code', 'pq393')->value('id');

        $this->actingAs($this->makeUser('qurilish_boshqarma', $dept), 'sanctum')
            ->patchJson('/api/qurilish/objects/'.$object->id, ['program_id' => $programId])
            ->assertOk();

        $this->assertSame('reja', $object->refresh()->lifecycle);
    }

    public function test_update_writes_audit_log(): void
    {
        $org = $this->makeOrganization('Буюртмачи', ['is_customer' => true]);
        $object = $this->makeObject(['name' => $this->tag('A'), 'customer_org_id' => $org, 'note' => 'эски']);

        $this->actingAs($this->makeUser('qurilish_buyurtmachi', $org), 'sanctum')
            ->patchJson('/api/qurilish/objects/'.$object->id, ['note' => 'янги'])->assertOk();

        $log = ObjectAuditLog::query()->where('object_id', $object->id)->where('field', 'note')->firstOrFail();

        $this->assertSame('update', $log->action);
        $this->assertSame('эски', $log->old_value);
        $this->assertSame('янги', $log->new_value);
        $this->assertNotNull($log->user_id);
    }

    public function test_audit_endpoint_lists_changes(): void
    {
        $org = $this->makeOrganization('Буюртмачи', ['is_customer' => true]);
        $object = $this->makeObject(['name' => $this->tag('A'), 'customer_org_id' => $org]);

        $this->actingAs($this->makeUser('qurilish_buyurtmachi', $org), 'sanctum')
            ->patchJson('/api/qurilish/objects/'.$object->id, ['note' => 'изоҳ'])->assertOk();

        $res = $this->actingAs($this->makeUser('qurilish_prokuratura'), 'sanctum')
            ->getJson('/api/qurilish/objects/'.$object->id.'/audit')->assertOk();

        $this->assertGreaterThanOrEqual(1, count($res->json('data')));
        $this->assertSame('note', $res->json('data.0.field'));
        $this->assertNotNull($res->json('data.0.user'));
    }

    // ---------- oylik grafik ----------

    public function test_monthly_returns_twelve_months_and_accepts_upsert(): void
    {
        $org = $this->makeOrganization('Буюртмачи', ['is_customer' => true]);
        $object = $this->makeObject(['name' => $this->tag('M'), 'customer_org_id' => $org]);
        $user = $this->makeUser('qurilish_buyurtmachi', $org);

        $res = $this->actingAs($user, 'sanctum')
            ->getJson('/api/qurilish/objects/'.$object->id.'/monthly?year=2026')->assertOk();
        $this->assertCount(12, $res->json('data'));

        $res = $this->actingAs($user, 'sanctum')
            ->putJson('/api/qurilish/objects/'.$object->id.'/monthly', [
                'year' => 2026,
                'months' => [['month' => 5, 'planned_amount' => 3000, 'actual_amount' => 2500]],
            ])->assertOk();

        $this->assertEqualsWithDelta(3000, $res->json('data.4.planned_amount'), 0.001);
        $this->assertEqualsWithDelta(2500, $res->json('data.4.actual_amount'), 0.001);
    }

    public function test_viewer_cannot_upsert_monthly(): void
    {
        $object = $this->makeObject(['name' => $this->tag('M')]);

        $this->actingAs($this->makeUser('qurilish_hokimlik'), 'sanctum')
            ->putJson('/api/qurilish/objects/'.$object->id.'/monthly', [
                'year' => 2026, 'months' => [['month' => 1, 'planned_amount' => 1]],
            ])->assertStatus(403);
    }
}
