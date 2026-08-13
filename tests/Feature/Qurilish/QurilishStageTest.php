<?php

declare(strict_types=1);

namespace Tests\Feature\Qurilish;

use App\Domains\Qurilish\Models\ConstructionObject;
use App\Domains\Qurilish\Models\ObjectAuditLog;
use App\Domains\Qurilish\Models\ObjectStage;

/**
 * Bosqich holat mashinasi: ketma-ketlik majburlanishi, shartli bosqich,
 * qoralama taqiqи, `current_stage`/`lifecycle` qayta hisoblanishi.
 */
class QurilishStageTest extends QurilishObjectTestCase
{
    public function test_stages_index_returns_eight_ordered_stages(): void
    {
        $object = $this->makeObject(['name' => $this->tag('S')]);

        $res = $this->actingAs($this->makeUser('qurilish_hokimlik'), 'sanctum')
            ->getJson('/api/qurilish/objects/'.$object->id.'/stages')->assertOk();

        $this->assertCount(8, $res->json('data'));
        $this->assertSame('designer_selection', $res->json('data.0.stage_code'));
        $this->assertSame(1, $res->json('data.0.order'));
        $this->assertSame('handover', $res->json('data.7.stage_code'));
    }

    public function test_first_stage_can_be_started(): void
    {
        [$object, $user] = $this->objectWithCustomer();

        $this->actingAs($user, 'sanctum')
            ->patchJson($this->url($object, 'designer_selection'), ['status' => 'jarayonda'])
            ->assertOk()
            ->assertJsonPath('data.status', 'jarayonda')
            ->assertJsonPath('current_stage', 'designer_selection');
    }

    public function test_skipping_a_stage_is_rejected(): void
    {
        [$object, $user] = $this->objectWithCustomer();

        // Loyihachi hali aniqlanmagan — to'g'ridan-to'g'ri tenderga o'tib bo'lmaydi.
        $this->actingAs($user, 'sanctum')
            ->patchJson($this->url($object, 'tender'), ['status' => 'yakunlangan'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame('boshlanmagan', $this->statusOf($object, 'tender'));
    }

    public function test_stage_allowed_once_predecessors_complete(): void
    {
        [$object, $user] = $this->objectWithCustomer();
        $this->completeStagesBefore($object, 'tender');

        $this->actingAs($user, 'sanctum')
            ->patchJson($this->url($object, 'tender'), ['status' => 'yakunlangan'])
            ->assertOk();

        $this->assertSame('yakunlangan', $this->statusOf($object, 'tender'));
    }

    public function test_not_required_status_only_for_complex_expertise(): void
    {
        [$object, $user] = $this->objectWithCustomer();

        $this->actingAs($user, 'sanctum')
            ->patchJson($this->url($object, 'tender'), ['status' => 'talab_etilmaydi'])
            ->assertStatus(422);

        $this->actingAs($user, 'sanctum')
            ->patchJson($this->url($object, 'complex_expertise'), ['status' => 'talab_etilmaydi'])
            ->assertOk();
    }

    public function test_objection_status_only_for_complex_expertise(): void
    {
        [$object, $user] = $this->objectWithCustomer();

        $this->actingAs($user, 'sanctum')
            ->patchJson($this->url($object, 'design_estimate'), ['status' => 'etiroz_bilan_qaytarilgan'])
            ->assertStatus(422);
    }

    public function test_not_required_expertise_unblocks_next_stage(): void
    {
        [$object, $user] = $this->objectWithCustomer();
        $this->completeStagesBefore($object, 'complex_expertise');
        $this->setStage($object, 'complex_expertise', 'talab_etilmaydi');

        // 517 obyektda kompleks ekspertiza talab etilmaydi — tender ochiq bo'lishi shart.
        $this->actingAs($user, 'sanctum')
            ->patchJson($this->url($object, 'tender'), ['status' => 'jarayonda'])
            ->assertOk();
    }

    public function test_draft_object_rejects_stage_change(): void
    {
        $dept = $this->makeOrganization('Бошқарма', ['is_department' => true]);
        $object = $this->makeObject([
            'name' => $this->tag('D'), 'department_org_id' => $dept, 'lifecycle' => 'qoralama',
        ]);

        $this->actingAs($this->makeUser('qurilish_admin'), 'sanctum')
            ->patchJson($this->url($object, 'designer_selection'), ['status' => 'jarayonda'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('stage');
    }

    public function test_unknown_stage_is_404_and_unknown_status_is_422(): void
    {
        [$object, $user] = $this->objectWithCustomer();

        $this->actingAs($user, 'sanctum')
            ->patchJson($this->url($object, 'nonexistent'), ['status' => 'jarayonda'])
            ->assertStatus(404);

        $this->actingAs($user, 'sanctum')
            ->patchJson($this->url($object, 'designer_selection'), ['status' => 'allaqanday'])
            ->assertStatus(422);
    }

    public function test_completing_all_stages_marks_object_finished(): void
    {
        [$object, $user] = $this->objectWithCustomer();
        $this->completeStagesBefore($object, 'handover');

        $this->actingAs($user, 'sanctum')
            ->patchJson($this->url($object, 'handover'), ['status' => 'yakunlangan'])
            ->assertOk()
            ->assertJsonPath('lifecycle', 'tugallangan');

        $object->refresh();
        $this->assertTrue($object->handover_done);
        $this->assertFalse($object->is_overdue, 'Topshirilgan obyekt kechikkan hisoblanmaydi');
    }

    public function test_stage_change_is_audited(): void
    {
        [$object, $user] = $this->objectWithCustomer();

        $this->actingAs($user, 'sanctum')
            ->patchJson($this->url($object, 'designer_selection'), ['status' => 'jarayonda'])
            ->assertOk();

        $log = ObjectAuditLog::query()
            ->where('object_id', $object->id)->where('action', 'stage_change')->firstOrFail();

        $this->assertSame('designer_selection', $log->field);
        $this->assertSame('boshlanmagan', $log->old_value);
        $this->assertSame('jarayonda', $log->new_value);
    }

    public function test_viewer_and_boshqarma_cannot_change_stages(): void
    {
        $object = $this->makeObject(['name' => $this->tag('S')]);
        $dept = $this->makeOrganization('Бошқарма', ['is_department' => true]);

        foreach ([
            $this->makeUser('qurilish_hokimlik'),
            $this->makeUser('qurilish_prokuratura'),
            $this->makeUser('qurilish_boshqarma', $dept),
        ] as $user) {
            $this->actingAs($user, 'sanctum')
                ->patchJson($this->url($object, 'designer_selection'), ['status' => 'jarayonda'])
                ->assertStatus(403);
        }
    }

    public function test_stage_dates_and_note_are_saved(): void
    {
        [$object, $user] = $this->objectWithCustomer();

        $this->actingAs($user, 'sanctum')
            ->patchJson($this->url($object, 'designer_selection'), [
                'status' => 'yakunlangan',
                'started_at' => '2026-02-01',
                'completed_at' => '2026-03-15',
                'note' => 'Тендер орқали аниқланди',
            ])->assertOk();

        $stage = ObjectStage::query()
            ->where('object_id', $object->id)->where('stage_code', 'designer_selection')->firstOrFail();

        $this->assertSame('2026-02-01', $stage->started_at->toDateString());
        $this->assertSame('2026-03-15', $stage->completed_at->toDateString());
        $this->assertSame('Тендер орқали аниқланди', $stage->note);
        $this->assertSame($user->id, $stage->responsible_user_id);
    }

    // ---------- yordamchilar ----------

    /** @return array{0: ConstructionObject, 1: \App\Models\User} */
    private function objectWithCustomer(): array
    {
        $org = $this->makeOrganization('Буюртмачи', ['is_customer' => true]);

        return [
            $this->makeObject(['name' => $this->tag('S'), 'customer_org_id' => $org]),
            $this->makeUser('qurilish_buyurtmachi', $org),
        ];
    }

    private function url(ConstructionObject $object, string $stage): string
    {
        return "/api/qurilish/objects/{$object->id}/stages/{$stage}";
    }

    private function statusOf(ConstructionObject $object, string $stage): string
    {
        return (string) ObjectStage::query()
            ->where('object_id', $object->id)->where('stage_code', $stage)->value('status');
    }
}
