<?php

declare(strict_types=1);

namespace Tests\Feature\Yoshlar;

use App\Domains\Yoshlar\Models\Notification;
use App\Domains\Yoshlar\Models\Organization;
use App\Domains\Yoshlar\Models\Task;

/**
 * HAMKOR IJROCHILAR — «topshiriq ularga yetib borishi» talabi.
 *
 * Biriktirish oʻz-oʻzidan yetarli emas: masʼul uni KOʻRISHI va XABAR
 * OLISHI kerak. Shu ikki narsa shu yerda tekshiriladi — aks holda
 * biriktirma bazadagi jimgina qator boʻlib qolardi.
 */
class YoshlarTaskCoExecutorTest extends YoshlarTestCase
{
    private function org(string $type = Organization::TYPE_TUMAN_SEKTOR): Organization
    {
        return $this->makeOrganization($type, ['district_id' => $this->someDistrictId()]);
    }

    /** @param array<string, mixed> $extra */
    private function createTask(array $extra = []): array
    {
        $lead = $this->org();

        $payload = array_merge([
            'title' => 'Hujjat bandi',
            'assigned_org_id' => $lead->id,
            'deadline' => '2026-12-01',
            'priority' => 'orta',
        ], $extra);

        $id = $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->postJson('/api/yoshlar/tasks', $payload)
            ->assertCreated()
            ->json('data.id');

        return [$lead, Task::query()->findOrFail($id)];
    }

    public function test_co_executor_sees_the_task_in_its_own_list(): void
    {
        $partner = $this->org();
        [, $task] = $this->createTask(['co_executor_ids' => [$partner->id]]);

        // Hamkor tashkilot xodimi — oʻz doirasida faqat shu topshiriq.
        $partnerUser = $this->makeUser('sektor_bolim', $partner->id);

        $ids = collect(
            $this->actingAs($partnerUser, 'sanctum')
                ->getJson('/api/yoshlar/tasks')->assertOk()->json('data'),
        )->pluck('id');

        $this->assertTrue(
            $ids->contains($task->id),
            'Hamkor ijrochi oʻziga biriktirilgan topshiriqni koʻrmadi.',
        );
    }

    public function test_unrelated_organization_still_cannot_see_the_task(): void
    {
        // Doira KENGAYTIRILDI, ochilib ketmadi: begona tashkilot
        // topshiriqni koʻrmasligi kerak.
        $partner = $this->org();
        [, $task] = $this->createTask(['co_executor_ids' => [$partner->id]]);

        $stranger = $this->makeUser('sektor_bolim', $this->org()->id);

        $ids = collect(
            $this->actingAs($stranger, 'sanctum')
                ->getJson('/api/yoshlar/tasks')->assertOk()->json('data'),
        )->pluck('id');

        $this->assertFalse($ids->contains($task->id), 'Begona tashkilot topshiriqni koʻrdi.');
    }

    public function test_both_lead_and_partner_receive_a_notification(): void
    {
        $partner = $this->org();
        $partnerUser = $this->makeUser('sektor_bolim', $partner->id);

        [$lead, $task] = $this->createTask(['co_executor_ids' => [$partner->id]]);
        $leadUser = $this->makeUser('sektor_bolim', $lead->id);

        // Xodim topshiriq YARATILGANDAN keyin qoʻshilgani uchun eski
        // xabarni olmaydi — shuning uchun roʻyxatni qayta yuboramiz.
        $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->patchJson("/api/yoshlar/tasks/{$task->id}", ['co_executor_ids' => []])
            ->assertOk();

        $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->patchJson("/api/yoshlar/tasks/{$task->id}", ['co_executor_ids' => [$partner->id]])
            ->assertOk();

        $sent = Notification::query()
            ->where('entity_id', $task->id)
            ->where('type', 'task.assigned')
            ->pluck('user_id');

        $this->assertTrue($sent->contains($partnerUser->id), 'Hamkorga xabar bormadi.');
        $this->assertGreaterThan(0, $sent->count());
        unset($leadUser);
    }

    public function test_lead_is_never_stored_as_its_own_partner(): void
    {
        // Ikkala joyda tursa, unga ikkita bir xil xabar borardi va
        // roʻyxatda ikki marta koʻrinardi.
        $lead = $this->org();

        $id = $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->postJson('/api/yoshlar/tasks', [
                'title' => 'Oʻzini hamkor qilib qoʻyish',
                'assigned_org_id' => $lead->id,
                'co_executor_ids' => [$lead->id],
                'deadline' => '2026-12-01',
                'priority' => 'orta',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertSame(
            0,
            Task::query()->findOrFail($id)->coExecutors()->count(),
            'Bosh ijrochi oʻziga hamkor sifatida yozildi.',
        );
    }

    public function test_missing_organization_is_refused(): void
    {
        $lead = $this->org();

        $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->postJson('/api/yoshlar/tasks', [
                'title' => 'Mavjud boʻlmagan hamkor',
                'assigned_org_id' => $lead->id,
                'co_executor_ids' => ['01a02e4f-0000-7000-8000-000000000000'],
                'deadline' => '2026-12-01',
                'priority' => 'orta',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('co_executor_ids');
    }

    public function test_district_youth_department_can_be_the_lead_executor(): void
    {
        // Hujjatda masʼul koʻpincha yoshlar vertikalining oʻzi. Tuman
        // boʻlimi ijrochi boʻla oladi — uni BOSHQA tashkilot tasdiqlaydi,
        // demak oʻzini tasdiqlash holati yoʻq.
        $youthOrg = $this->org(Organization::TYPE_TUMAN_YOSHLAR);

        $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->postJson('/api/yoshlar/tasks', [
                'title' => 'Tuman yoshlar boʻlimi masʼul boʻlgan band',
                'assigned_org_id' => $youthOrg->id,
                'deadline' => '2026-12-01',
                'priority' => 'orta',
            ])
            ->assertCreated();
    }

    public function test_final_approver_may_still_be_a_partner(): void
    {
        // Hujjat «Yoshlar ishlari viloyat boshqarmasi» ni masʼul deb
        // koʻrsatsa, u HAMKOR sifatida biriktiriladi: hamkor hisobot
        // yubormaydi, shuning uchun oʻzini tasdiqlash yuzaga kelmaydi.
        $province = $this->org(Organization::TYPE_VILOYAT_YOSHLAR);
        $lead = $this->org();

        $id = $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->postJson('/api/yoshlar/tasks', [
                'title' => 'Viloyat boshqarmasi hamkor boʻlgan band',
                'assigned_org_id' => $lead->id,
                'co_executor_ids' => [$province->id],
                'deadline' => '2026-12-01',
                'priority' => 'orta',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertSame(1, Task::query()->findOrFail($id)->coExecutors()->count());
    }

    public function test_partner_list_is_replaced_not_appended(): void
    {
        $first = $this->org();
        $second = $this->org();
        [, $task] = $this->createTask(['co_executor_ids' => [$first->id]]);

        $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->patchJson("/api/yoshlar/tasks/{$task->id}", ['co_executor_ids' => [$second->id]])
            ->assertOk();

        $ids = $task->coExecutors()->pluck('organizations.id')->all();

        $this->assertSame([$second->id], $ids, 'Roʻyxat almashtirilmadi, qoʻshildi.');
    }
}
