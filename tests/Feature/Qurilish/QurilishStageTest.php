<?php

declare(strict_types=1);

namespace Tests\Feature\Qurilish;

use App\Domains\Qurilish\Models\ConstructionObject;
use App\Domains\Qurilish\Models\ObjectAuditLog;
use App\Domains\Qurilish\Models\ObjectStage;
use App\Models\User;

/**
 * XNP TZ v2.0 (2.3) moderatsiya sikli:
 *   ochilgan → qoralama → tasdiqlash_kutilmoqda → korib_chiqilmoqda →
 *   tasdiqlangan;   rad_etilgan → qoralama
 *
 * Tekshiriladigan kafolatlar: bosqichni buyurtmachi o'zi yopa olmaydi,
 * moderator tasdig'isiz keyingi bosqich ochilmaydi, rad etish sababsiz
 * bo'lmaydi, ketma-ketlik buzilmaydi.
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

        // TZ guruhi ham qaytadi — rasmiy 6 bosqichli timeline shundan chiziladi.
        $this->assertSame(2, $res->json('data.0.tz_stage'));
        $this->assertSame(6, $res->json('data.7.tz_stage'));
    }

    public function test_new_object_opens_only_the_first_stage(): void
    {
        $object = $this->makeObject(['name' => $this->tag('S')]);

        $this->assertSame('ochilgan', $this->statusOf($object, 'designer_selection'));
        $this->assertSame('kutilmoqda', $this->statusOf($object, 'design_estimate'));
        $this->assertSame('kutilmoqda', $this->statusOf($object, 'handover'));
    }

    public function test_full_moderation_cycle_from_draft_to_approved(): void
    {
        [$object, $customer] = $this->objectWithCustomer();
        $moderator = $this->makeUser('qurilish_prokuratura');

        $this->actingAs($customer, 'sanctum')
            ->patchJson($this->url($object, 'designer_selection'), ['note' => 'Тендер эълон қилинди'])
            ->assertOk()
            ->assertJsonPath('data.status', 'qoralama');

        $this->actingAs($customer, 'sanctum')
            ->postJson($this->url($object, 'designer_selection').'/submit')
            ->assertOk()
            ->assertJsonPath('data.status', 'tasdiqlash_kutilmoqda');

        $this->actingAs($moderator, 'sanctum')
            ->postJson($this->url($object, 'designer_selection').'/review')
            ->assertOk()
            ->assertJsonPath('data.status', 'korib_chiqilmoqda');

        $this->actingAs($moderator, 'sanctum')
            ->postJson($this->url($object, 'designer_selection').'/approve')
            ->assertOk()
            ->assertJsonPath('data.status', 'tasdiqlangan');

        // Tasdiq keyingi bosqichni ochadi — bu strict-sequential zanjirining bo'g'ini.
        $this->assertSame('ochilgan', $this->statusOf($object, 'design_estimate'));
        $this->assertSame('design_estimate', $object->fresh()->current_stage);
    }

    public function test_customer_cannot_approve_own_stage(): void
    {
        [$object, $customer] = $this->objectWithCustomer();

        $this->actingAs($customer, 'sanctum')
            ->patchJson($this->url($object, 'designer_selection'), ['note' => 'Тайёр'])->assertOk();
        $this->actingAs($customer, 'sanctum')
            ->postJson($this->url($object, 'designer_selection').'/submit')->assertOk();

        // Nazorat organi platformasining asosiy qoidasi: ijrochi o'z ishini
        // o'zi yopa olmaydi.
        $this->actingAs($customer, 'sanctum')
            ->postJson($this->url($object, 'designer_selection').'/review')->assertStatus(403);
        $this->actingAs($customer, 'sanctum')
            ->postJson($this->url($object, 'designer_selection').'/approve')->assertStatus(403);

        $this->assertSame('tasdiqlash_kutilmoqda', $this->statusOf($object, 'designer_selection'));
    }

    public function test_rejection_requires_reason_and_returns_stage_to_customer(): void
    {
        [$object, $customer] = $this->objectWithCustomer();
        $moderator = $this->makeUser('qurilish_prokuratura');

        $this->submitStage($object, $customer, 'designer_selection');
        $this->actingAs($moderator, 'sanctum')
            ->postJson($this->url($object, 'designer_selection').'/review')->assertOk();

        $this->actingAs($moderator, 'sanctum')
            ->postJson($this->url($object, 'designer_selection').'/reject', ['reason' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->actingAs($moderator, 'sanctum')
            ->postJson($this->url($object, 'designer_selection').'/reject', [
                'reason' => 'Шартнома нусхаси илова қилинмаган',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rad_etilgan')
            ->assertJsonPath('data.rejection_reason', 'Шартнома нусхаси илова қилинмаган');

        // Rad etilgan bosqich keyingisini ochmaydi.
        $this->assertSame('kutilmoqda', $this->statusOf($object, 'design_estimate'));

        // Buyurtmachi tuzatib, qayta yuboradi.
        $this->actingAs($customer, 'sanctum')
            ->patchJson($this->url($object, 'designer_selection'), ['note' => 'Нусха илова қилинди'])
            ->assertOk()
            ->assertJsonPath('data.status', 'qoralama');
    }

    public function test_skipping_a_stage_is_rejected(): void
    {
        [$object, $customer] = $this->objectWithCustomer();

        // Loyihachi hali tasdiqlanmagan — tenderni tasdiqqa yuborib bo'lmaydi.
        $this->actingAs($customer, 'sanctum')
            ->patchJson($this->url($object, 'tender'), ['note' => 'Эълон'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame('kutilmoqda', $this->statusOf($object, 'tender'));
    }

    public function test_stage_allowed_once_predecessors_approved(): void
    {
        [$object, $customer] = $this->objectWithCustomer();
        $this->completeStagesBefore($object, 'tender');

        $this->actingAs($customer, 'sanctum')
            ->patchJson($this->url($object, 'tender'), ['note' => 'Эълон берилди'])
            ->assertOk()
            ->assertJsonPath('data.status', 'qoralama');
    }

    public function test_not_required_only_for_complex_expertise_and_only_by_moderator(): void
    {
        [$object, $customer] = $this->objectWithCustomer();
        $moderator = $this->makeUser('qurilish_prokuratura');

        $this->actingAs($moderator, 'sanctum')
            ->postJson($this->url($object, 'tender').'/not-required')
            ->assertStatus(422);

        // Buyurtmachi o'zi «талаб этилмайди» deb bosqichni chetlab o'ta olmaydi.
        $this->actingAs($customer, 'sanctum')
            ->postJson($this->url($object, 'complex_expertise').'/not-required')
            ->assertStatus(403);

        $this->actingAs($moderator, 'sanctum')
            ->postJson($this->url($object, 'complex_expertise').'/not-required')
            ->assertOk()
            ->assertJsonPath('data.status', 'talab_etilmaydi');
    }

    public function test_not_required_expertise_unblocks_next_stage(): void
    {
        [$object, $customer] = $this->objectWithCustomer();
        $this->completeStagesBefore($object, 'complex_expertise');

        // 517 obyektda kompleks ekspertiza talab etilmaydi — tender ochilishi shart.
        $this->actingAs($this->makeUser('qurilish_prokuratura'), 'sanctum')
            ->postJson($this->url($object, 'complex_expertise').'/not-required')->assertOk();

        $this->assertSame('ochilgan', $this->statusOf($object, 'tender'));

        $this->actingAs($customer, 'sanctum')
            ->patchJson($this->url($object, 'tender'), ['note' => 'Эълон'])->assertOk();
    }

    public function test_draft_object_rejects_stage_change(): void
    {
        $dept = $this->makeOrganization('Бошқарма', ['is_department' => true]);
        $object = $this->makeObject([
            'name' => $this->tag('D'), 'department_org_id' => $dept, 'lifecycle' => 'qoralama',
        ]);

        $this->actingAs($this->makeUser('qurilish_admin'), 'sanctum')
            ->patchJson($this->url($object, 'designer_selection'), ['note' => 'Изоҳ'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('stage');
    }

    public function test_unknown_stage_is_404(): void
    {
        [$object, $customer] = $this->objectWithCustomer();

        $this->actingAs($customer, 'sanctum')
            ->patchJson($this->url($object, 'nonexistent'), ['note' => 'Изоҳ'])
            ->assertStatus(404);
    }

    public function test_completing_all_stages_marks_object_finished(): void
    {
        [$object, $customer] = $this->objectWithCustomer();
        $moderator = $this->makeUser('qurilish_prokuratura');
        $this->completeStagesBefore($object, 'handover');

        $this->submitStage($object, $customer, 'handover');
        $this->actingAs($moderator, 'sanctum')
            ->postJson($this->url($object, 'handover').'/review')->assertOk();

        $this->actingAs($moderator, 'sanctum')
            ->postJson($this->url($object, 'handover').'/approve')
            ->assertOk()
            ->assertJsonPath('lifecycle', 'tugallangan');

        $object->refresh();
        $this->assertTrue($object->handover_done);
        $this->assertFalse($object->is_overdue, 'Topshirilgan obyekt kechikkan hisoblanmaydi');
    }

    public function test_moderation_queue_lists_submitted_stages(): void
    {
        [$object, $customer] = $this->objectWithCustomer();
        $this->submitStage($object, $customer, 'designer_selection');

        $res = $this->actingAs($this->makeUser('qurilish_prokuratura'), 'sanctum')
            ->getJson('/api/qurilish/moderation/queue')->assertOk();

        $this->assertTrue($res->json('can_moderate'));

        $mine = collect($res->json('data'))->firstWhere('object_id', $object->id);
        $this->assertNotNull($mine, 'Юборилган босқич навбатда кўринмади');
        $this->assertSame('designer_selection', $mine['stage_code']);
        $this->assertSame('tasdiqlash_kutilmoqda', $mine['status']);
    }

    public function test_queue_is_scoped_for_customer(): void
    {
        [$object, $customer] = $this->objectWithCustomer();
        $this->submitStage($object, $customer, 'designer_selection');

        // Boshqa buyurtmachi — o'zga tashkilot navbatini ko'rmaydi (IDOR himoyasi).
        $otherOrg = $this->makeOrganization('Бошқа буюртмачи', ['is_customer' => true]);
        $other = $this->makeUser('qurilish_buyurtmachi', $otherOrg);

        $res = $this->actingAs($other, 'sanctum')
            ->getJson('/api/qurilish/moderation/queue')->assertOk();

        $this->assertNull(collect($res->json('data'))->firstWhere('object_id', $object->id));
        $this->assertFalse($res->json('can_moderate'));
    }

    public function test_actions_reflect_role_and_status(): void
    {
        [$object, $customer] = $this->objectWithCustomer();

        $res = $this->actingAs($customer, 'sanctum')
            ->getJson('/api/qurilish/objects/'.$object->id.'/stages')->assertOk();
        $this->assertSame(['draft'], $res->json('data.0.actions'));

        // Kuzatuvchi hech qanday amal ko'rmaydi.
        $res = $this->actingAs($this->makeUser('qurilish_hokimlik'), 'sanctum')
            ->getJson('/api/qurilish/objects/'.$object->id.'/stages')->assertOk();
        $this->assertSame([], $res->json('data.0.actions'));

        $this->submitStage($object, $customer, 'designer_selection');

        $res = $this->actingAs($this->makeUser('qurilish_prokuratura'), 'sanctum')
            ->getJson('/api/qurilish/objects/'.$object->id.'/stages')->assertOk();
        $this->assertSame(['review'], $res->json('data.0.actions'));
    }

    public function test_stage_change_is_audited(): void
    {
        [$object, $customer] = $this->objectWithCustomer();

        $this->actingAs($customer, 'sanctum')
            ->patchJson($this->url($object, 'designer_selection'), ['note' => 'Изоҳ'])->assertOk();

        $log = ObjectAuditLog::query()
            ->where('object_id', $object->id)->where('action', 'stage_change')->firstOrFail();

        $this->assertSame('designer_selection', $log->field);
        $this->assertSame('ochilgan', $log->old_value);
        $this->assertSame('qoralama', $log->new_value);
    }

    public function test_rejection_is_audited_with_reason(): void
    {
        [$object, $customer] = $this->objectWithCustomer();
        $moderator = $this->makeUser('qurilish_prokuratura');

        $this->submitStage($object, $customer, 'designer_selection');
        $this->actingAs($moderator, 'sanctum')
            ->postJson($this->url($object, 'designer_selection').'/review')->assertOk();
        $this->actingAs($moderator, 'sanctum')
            ->postJson($this->url($object, 'designer_selection').'/reject', [
                'reason' => 'Ҳужжат тўлиқ эмас',
            ])->assertOk();

        $log = ObjectAuditLog::query()
            ->where('object_id', $object->id)->where('action', 'stage_reject')->firstOrFail();

        $this->assertSame('Ҳужжат тўлиқ эмас', $log->new_value);
    }

    public function test_viewer_and_boshqarma_cannot_fill_stages(): void
    {
        // Boshqarma obyektni KO'RADI (o'z boshqarmasi kiritgan), lekin bosqich
        // yuritish uning ishi emas — buyurtmachiniki. Shuning uchun obyekt
        // uning scope'iga qo'yiladi: aks holda 404 chiqib, testning ma'nosi
        // (403 = ruxsat yo'q) yo'qolardi.
        $dept = $this->makeOrganization('Бошқарма', ['is_department' => true]);
        $object = $this->makeObject(['name' => $this->tag('S'), 'department_org_id' => $dept]);

        foreach ([
            $this->makeUser('qurilish_hokimlik'),
            $this->makeUser('qurilish_prokuratura'),
            $this->makeUser('qurilish_boshqarma', $dept),
        ] as $user) {
            $this->actingAs($user, 'sanctum')
                ->patchJson($this->url($object, 'designer_selection'), ['note' => 'Изоҳ'])
                ->assertStatus(403);
        }
    }

    public function test_stage_data_and_note_are_saved(): void
    {
        [$object, $customer] = $this->objectWithCustomer();

        $this->actingAs($customer, 'sanctum')
            ->patchJson($this->url($object, 'designer_selection'), [
                'started_at' => '2026-02-01',
                'note' => 'Тендер орқали аниқланди',
                'malumot' => ['tender_raqami' => 'ЛТ-2026-114'],
            ])->assertOk();

        $stage = ObjectStage::query()
            ->where('object_id', $object->id)->where('stage_code', 'designer_selection')->firstOrFail();

        $this->assertSame('2026-02-01', $stage->started_at->toDateString());
        $this->assertSame('Тендер орқали аниқланди', $stage->note);
        $this->assertSame(['tender_raqami' => 'ЛТ-2026-114'], $stage->malumot);
        $this->assertSame($customer->id, $stage->responsible_user_id);
    }

    // ---------- yordamchilar ----------

    /** @return array{0: ConstructionObject, 1: User} */
    private function objectWithCustomer(): array
    {
        $org = $this->makeOrganization('Буюртмачи', ['is_customer' => true]);

        return [
            $this->makeObject(['name' => $this->tag('S'), 'customer_org_id' => $org]),
            $this->makeUser('qurilish_buyurtmachi', $org),
        ];
    }

    /** Bosqichni to'ldirib, tasdiqqa yuboradi (API orqali — sikl haqiqiy bo'lsin). */
    private function submitStage(ConstructionObject $object, User $customer, string $stage): void
    {
        $this->actingAs($customer, 'sanctum')
            ->patchJson($this->url($object, $stage), ['note' => 'Тайёр'])->assertOk();
        $this->actingAs($customer, 'sanctum')
            ->postJson($this->url($object, $stage).'/submit')->assertOk();
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
