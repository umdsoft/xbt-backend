<?php

declare(strict_types=1);

namespace Tests\Feature\Qurilish;

use App\Domains\Qurilish\Models\ConstructionObject;
use App\Domains\Qurilish\Models\ObjectStage;
use App\Domains\Qurilish\Models\RepairNeed;
use App\Domains\Qurilish\Models\Sector;
use App\Models\User;

/**
 * Ta'mirtalab reyestr (loyihaning 2-maqsadi): kiritish, scope, `promote`
 * pipeline'i va `ПАСПОРТ` jonli hisoboti.
 *
 * Barcha testlar YANGI boshqarma tashkiloti ostida yuradi — `applyRepair`
 * scope'i jamlanmani shu testning yozuvlariga cheklaydi (umumiy dev bazasi).
 */
class QurilishRepairNeedTest extends QurilishObjectTestCase
{
    private string $dept;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dept = $this->makeOrganization('Таъмир-бошқарма '.$this->prefix, ['is_department' => true]);
        $this->user = $this->makeUser('qurilish_boshqarma', $this->dept);
    }

    public function test_boshqarma_creates_need_bound_to_its_organization(): void
    {
        $res = $this->actingAs($this->user, 'sanctum')->postJson('/api/qurilish/repair-needs', [
            'name' => $this->tag('MAKTAB'),
            'condition_desc' => 'Том оқмоқда',
            'estimated_amount' => 1500,
            'target_year' => 2027,
            'priority' => 1,
        ])->assertStatus(201);

        $need = RepairNeed::query()->findOrFail($res->json('data.id'));

        $this->assertSame($this->dept, $need->department_org_id);
        $this->assertSame('yigilgan', $need->status);
        $this->assertFalse($need->funding_source_known);
        $this->assertSame(2027, $need->target_year);
    }

    public function test_boshqarma_cannot_write_into_another_departments_registry(): void
    {
        $other = $this->makeOrganization('Бегона бошқарма '.$this->prefix, ['is_department' => true]);

        // department_org_id ni so'rovda yuborsa ham — o'z tashkiloti majburan qo'yiladi.
        $res = $this->actingAs($this->user, 'sanctum')->postJson('/api/qurilish/repair-needs', [
            'name' => $this->tag('X'),
            'department_org_id' => $other,
        ])->assertStatus(201);

        $this->assertSame($this->dept, RepairNeed::query()->findOrFail($res->json('data.id'))->department_org_id);
    }

    public function test_list_is_scoped_to_own_department(): void
    {
        $this->need(['name' => $this->tag('MINE')]);

        $other = $this->makeOrganization('Бегона '.$this->prefix, ['is_department' => true]);
        RepairNeed::query()->create(['name' => $this->tag('OTHER'), 'department_org_id' => $other]);

        $res = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/qurilish/repair-needs?q='.$this->prefix)->assertOk();

        $this->assertSame(1, $res->json('meta.total'));
        $this->assertSame($this->tag('MINE'), $res->json('data.0.name'));
    }

    public function test_viewer_can_read_but_not_write(): void
    {
        $need = $this->need(['name' => $this->tag('V')]);
        $viewer = $this->makeUser('qurilish_prokuratura');

        $this->actingAs($viewer, 'sanctum')->getJson('/api/qurilish/repair-needs')->assertOk();
        $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/qurilish/repair-needs', ['name' => 'X'])->assertStatus(403);
        $this->actingAs($viewer, 'sanctum')
            ->patchJson('/api/qurilish/repair-needs/'.$need->id, ['name' => 'X'])->assertStatus(403);
    }

    public function test_buyurtmachi_cannot_manage_repair_registry(): void
    {
        $org = $this->makeOrganization('Буюртмачи '.$this->prefix, ['is_customer' => true]);

        $this->actingAs($this->makeUser('qurilish_buyurtmachi', $org), 'sanctum')
            ->postJson('/api/qurilish/repair-needs', ['name' => 'X'])->assertStatus(403);
    }

    public function test_filters_by_year_and_funding(): void
    {
        $this->need(['name' => $this->tag('A'), 'target_year' => 2026, 'funding_source_known' => true]);
        $this->need(['name' => $this->tag('B'), 'target_year' => 2027, 'funding_source_known' => false]);

        $this->assertSame(1, $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/qurilish/repair-needs?target_year=2026&q='.$this->prefix)
            ->assertOk()->json('meta.total'));

        $this->assertSame(1, $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/qurilish/repair-needs?funding_source_known=0&q='.$this->prefix)
            ->assertOk()->json('meta.total'));
    }

    // ---------- promote pipeline ----------

    public function test_promote_creates_draft_object_and_links_back(): void
    {
        $sector = Sector::query()->where('code', 'umumtalim_maktab')->firstOrFail();
        $need = $this->need([
            'name' => $this->tag('PROMOTE'),
            'sector_id' => $sector->id,
            'district_id' => $this->someDistrictId(),
            'estimated_amount' => 4200,
            'target_year' => 2027,
            'condition_desc' => 'Девор ёрилган',
        ]);

        $res = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/qurilish/repair-needs/'.$need->id.'/promote')->assertStatus(201);

        $object = ConstructionObject::query()->findOrFail($res->json('object.id'));

        // Obyekt QORALAMA bo'lib tug'iladi — dastursiz dashboardga tushmaydi.
        $this->assertSame('qoralama', $object->lifecycle);
        $this->assertSame($this->tag('PROMOTE'), $object->name);
        $this->assertSame($sector->id, $object->sector_id);
        $this->assertSame($this->dept, $object->department_org_id);
        $this->assertSame('4200.000', $object->limit_amount);
        $this->assertSame(2027, $object->deadline_year);
        $this->assertSame('Девор ёрилган', $object->note);
        $this->assertSame(8, ObjectStage::query()->where('object_id', $object->id)->count());

        // Bog'lanish ikki tomonlama saqlanadi — tarix yo'qolmaydi.
        $need->refresh();
        $this->assertSame('dasturga_kiritildi', $need->status);
        $this->assertSame($object->id, $need->promoted_object_id);
    }

    public function test_promote_twice_is_rejected(): void
    {
        $need = $this->need(['name' => $this->tag('P')]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/qurilish/repair-needs/'.$need->id.'/promote')->assertStatus(201);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/qurilish/repair-needs/'.$need->id.'/promote')->assertStatus(422);

        $this->assertSame(1, ConstructionObject::query()->where('name', $this->tag('P'))->count());
    }

    public function test_promoted_need_cannot_be_edited(): void
    {
        $need = $this->need(['name' => $this->tag('P')]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/qurilish/repair-needs/'.$need->id.'/promote')->assertStatus(201);

        $this->actingAs($this->user, 'sanctum')
            ->patchJson('/api/qurilish/repair-needs/'.$need->id, ['name' => 'ЎЗГАРТИРИШ'])
            ->assertStatus(422);
    }

    public function test_update_cannot_change_status_or_department(): void
    {
        $need = $this->need(['name' => $this->tag('U')]);
        $other = $this->makeOrganization('Бегона '.$this->prefix, ['is_department' => true]);

        $this->actingAs($this->user, 'sanctum')
            ->patchJson('/api/qurilish/repair-needs/'.$need->id, [
                'name' => $this->tag('YANGI'),
                'estimated_amount' => 777,
            ])->assertOk();

        $need->refresh();
        $this->assertSame($this->tag('YANGI'), $need->name);
        $this->assertSame('777.000', $need->estimated_amount);
        $this->assertSame('yigilgan', $need->status);
        $this->assertSame($this->dept, $need->department_org_id);
    }

    // ---------- ПАСПОРТ ----------

    public function test_pasport_aggregates_by_sector(): void
    {
        $maktab = Sector::query()->where('code', 'umumtalim_maktab')->value('id');
        $mtt = Sector::query()->where('code', 'mtt')->value('id');

        $this->need(['sector_id' => $maktab, 'estimated_amount' => 1000, 'target_year' => 2026, 'funding_source_known' => true]);
        $this->need(['sector_id' => $maktab, 'estimated_amount' => 2000, 'target_year' => 2027, 'funding_source_known' => false]);
        $this->need(['sector_id' => $mtt, 'estimated_amount' => 500, 'target_year' => null, 'funding_source_known' => false]);

        $res = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/qurilish/repair-needs/pasport')->assertOk();

        $rows = collect($res->json('data'));
        $school = $rows->firstWhere('key', $maktab);

        $this->assertSame(2, $school['needs']);
        $this->assertEqualsWithDelta(3000, $school['amount_total'], 0.001);
        $this->assertSame(1, $school['year_2026']);
        $this->assertEqualsWithDelta(1000, $school['amount_2026'], 0.001);
        $this->assertSame(1, $school['year_2027']);
        $this->assertSame(1, $school['funding_unknown']);

        // Jamlanma — manbadagi `ПАСПОРТ` varag'ining «Жами соҳа бўйича» qatori.
        $this->assertSame(3, $res->json('totals.needs'));
        $this->assertEqualsWithDelta(3500, $res->json('totals.amount_total'), 0.001);
        $this->assertSame(2, $res->json('totals.funding_unknown'));
    }

    public function test_pasport_is_scoped(): void
    {
        $this->need(['estimated_amount' => 100]);

        $other = $this->makeOrganization('Бегона '.$this->prefix, ['is_department' => true]);
        RepairNeed::query()->create([
            'name' => $this->tag('OTHER'), 'department_org_id' => $other, 'estimated_amount' => 999999,
        ]);

        $res = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/qurilish/repair-needs/pasport')->assertOk();

        $this->assertSame(1, $res->json('totals.needs'));
        $this->assertEqualsWithDelta(100, $res->json('totals.amount_total'), 0.001);
    }

    // ---------- yordamchi ----------

    /** @param array<string, mixed> $attrs */
    private function need(array $attrs = []): RepairNeed
    {
        return RepairNeed::query()->create(array_merge([
            'name' => $this->tag('N'.random_int(1000, 9999)),
            'department_org_id' => $this->dept,
            'status' => 'yigilgan',
        ], $attrs));
    }
}
