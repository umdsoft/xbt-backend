<?php

declare(strict_types=1);

namespace Tests\Feature\Yoshlar;

use App\Domains\Yoshlar\Models\Organization;
use App\Domains\Yoshlar\Models\Sector;
use App\Domains\Yoshlar\Models\Task;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * F2 — topshiriq ijrosi va tasdiqlash zanjiri.
 *
 * Eng muhim tekshiruvlar: ijrochi o'z topshirig'ini O'ZI yopa olmaydi,
 * zanjir bosqichlari tartibda o'tadi va doiradan tashqari topshiriq ko'rinmaydi.
 */
class YoshlarTaskTest extends YoshlarTestCase
{
    private Organization $viloyatSektor;

    private Organization $tumanSektor;

    private string $districtId;

    protected function setUp(): void
    {
        parent::setUp();

        $sector = Sector::query()->create([
            'code' => 'test_s_'.Str::random(6),
            'name_cyr' => 'Синов сектор',
            'name_lat' => 'Sinov sektor',
        ]);

        $this->districtId = $this->someDistrictId();
        $this->viloyatSektor = $this->makeOrganization(Organization::TYPE_VILOYAT_SEKTOR, ['sector_id' => $sector->id]);
        $this->tumanSektor = $this->makeOrganization(Organization::TYPE_TUMAN_SEKTOR, [
            'sector_id' => $sector->id,
            'parent_id' => $this->viloyatSektor->id,
            'district_id' => $this->districtId,
        ]);
    }

    public function test_admin_creates_task_and_executor_sees_it(): void
    {
        $admin = $this->makeUser('yoshlar_admin');

        $id = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/yoshlar/tasks', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'belgilandi')
            ->json('data.id');

        $ids = $this->actingAs($this->executor(), 'sanctum')
            ->getJson('/api/yoshlar/tasks?per_page=200')->assertOk()->json('data.*.id');

        $this->assertContains($id, $ids);
    }

    public function test_executor_from_other_org_does_not_see_task(): void
    {
        $task = $this->makeTask();

        $otherOrg = $this->makeOrganization(Organization::TYPE_TUMAN_SEKTOR, [
            'sector_id' => $this->tumanSektor->sector_id,
            'parent_id' => $this->viloyatSektor->id,
            'district_id' => $this->otherDistrictId($this->districtId),
        ]);
        $stranger = $this->makeUser('sektor_bolim', $otherOrg->id);

        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/yoshlar/tasks/{$task->id}")
            ->assertStatus(404);
    }

    public function test_executor_cannot_close_task_alone(): void
    {
        $task = $this->makeTask();

        $this->actingAs($this->executor(), 'sanctum')
            ->postJson("/api/yoshlar/tasks/{$task->id}/submit", ['progress' => 100, 'comment' => 'Bajarildi'])
            ->assertCreated();

        // 100% yuborilgan bo'lsa ham topshiriq YOPILMAYDI — bu faqat da'vo.
        $this->assertSame('tasdiq_kutilmoqda', $task->fresh()->status);
        $this->assertSame(0, $task->fresh()->progress);
    }

    public function test_full_chain_closes_the_task(): void
    {
        $task = $this->makeTask();

        $updateId = $this->actingAs($this->executor(), 'sanctum')
            ->postJson("/api/yoshlar/tasks/{$task->id}/submit", ['progress' => 100, 'comment' => 'Bajarildi'])
            ->assertCreated()->json('data.id');

        // 1-bosqich: sektor boshqarmasi.
        $this->actingAs($this->makeUser('sektor_boshqarma', $this->viloyatSektor->id), 'sanctum')
            ->postJson("/api/yoshlar/task-updates/{$updateId}/review", ['approve' => true])
            ->assertOk()
            ->assertJsonPath('data.review_stage', 'youth_review');

        $this->assertSame('tasdiq_kutilmoqda', $task->fresh()->status);

        // 2-bosqich: yoshlar boshqarmasi — yakuniy.
        $this->actingAs($this->makeUser('yoshlar_boshqarma'), 'sanctum')
            ->postJson("/api/yoshlar/task-updates/{$updateId}/review", ['approve' => true])
            ->assertOk()
            ->assertJsonPath('data.review_stage', 'approved');

        $this->assertSame('tasdiqlandi', $task->fresh()->status);
        $this->assertSame(100, $task->fresh()->progress);
    }

    public function test_youth_office_cannot_skip_sector_stage(): void
    {
        $task = $this->makeTask();

        $updateId = $this->actingAs($this->executor(), 'sanctum')
            ->postJson("/api/yoshlar/tasks/{$task->id}/submit", ['progress' => 50])
            ->assertCreated()->json('data.id');

        // Hisobot hali `sector_review` da — yoshlar boshqarmasi tegishi mumkin emas.
        $this->actingAs($this->makeUser('yoshlar_boshqarma'), 'sanctum')
            ->postJson("/api/yoshlar/task-updates/{$updateId}/review", ['approve' => true])
            ->assertStatus(403);
    }

    public function test_return_requires_reason(): void
    {
        $task = $this->makeTask();
        $updateId = $this->actingAs($this->executor(), 'sanctum')
            ->postJson("/api/yoshlar/tasks/{$task->id}/submit", ['progress' => 40])
            ->assertCreated()->json('data.id');

        $reviewer = $this->makeUser('sektor_boshqarma', $this->viloyatSektor->id);

        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/yoshlar/task-updates/{$updateId}/review", ['approve' => false])
            ->assertStatus(422);

        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/yoshlar/task-updates/{$updateId}/review", ['approve' => false, 'comment' => 'Hujjat yoʻq'])
            ->assertOk()
            ->assertJsonPath('data.review_stage', 'returned');

        $this->assertSame('qaytarildi', $task->fresh()->status);
    }

    public function test_second_open_submission_is_rejected(): void
    {
        $task = $this->makeTask();
        $executor = $this->executor();

        $this->actingAs($executor, 'sanctum')
            ->postJson("/api/yoshlar/tasks/{$task->id}/submit", ['progress' => 30])
            ->assertCreated();

        $this->actingAs($executor, 'sanctum')
            ->postJson("/api/yoshlar/tasks/{$task->id}/submit", ['progress' => 60])
            ->assertStatus(422);
    }

    public function test_returned_task_can_be_resubmitted(): void
    {
        $task = $this->makeTask();
        $executor = $this->executor();

        $updateId = $this->actingAs($executor, 'sanctum')
            ->postJson("/api/yoshlar/tasks/{$task->id}/submit", ['progress' => 30])
            ->assertCreated()->json('data.id');

        $this->actingAs($this->makeUser('sektor_boshqarma', $this->viloyatSektor->id), 'sanctum')
            ->postJson("/api/yoshlar/task-updates/{$updateId}/review", ['approve' => false, 'comment' => 'Tuzating'])
            ->assertOk();

        $this->actingAs($executor, 'sanctum')
            ->postJson("/api/yoshlar/tasks/{$task->id}/submit", ['progress' => 70, 'comment' => 'Tuzatildi'])
            ->assertCreated();
    }

    public function test_deadline_state_is_computed(): void
    {
        $overdue = $this->makeTask(['deadline' => now()->subDays(2)->toDateString()]);
        $amber = $this->makeTask(['deadline' => now()->addDay()->toDateString()]);
        $green = $this->makeTask(['deadline' => now()->addDays(20)->toDateString()]);

        $this->assertSame('red', $overdue->deadline_state);
        $this->assertTrue($overdue->is_overdue);
        $this->assertSame('amber', $amber->deadline_state);
        $this->assertSame('green', $green->deadline_state);
    }

    public function test_queue_shows_only_own_stage(): void
    {
        $task = $this->makeTask();
        $updateId = $this->actingAs($this->executor(), 'sanctum')
            ->postJson("/api/yoshlar/tasks/{$task->id}/submit", ['progress' => 50])
            ->assertCreated()->json('data.id');

        // Sektor boshqarmasida ko'rinadi (sector_review bosqichi).
        $sectorQueue = $this->actingAs($this->makeUser('sektor_boshqarma', $this->viloyatSektor->id), 'sanctum')
            ->getJson('/api/yoshlar/tasks/queue')->assertOk()->json('data.*.id');
        $this->assertContains($updateId, $sectorQueue);

        // Yoshlar boshqarmasida hali ko'rinmaydi.
        $youthQueue = $this->actingAs($this->makeUser('yoshlar_boshqarma'), 'sanctum')
            ->getJson('/api/yoshlar/tasks/queue')->assertOk()->json('data.*.id');
        $this->assertNotContains($updateId, $youthQueue);
    }

    public function test_stats_report_on_time_rate(): void
    {
        $this->makeTask(['deadline' => now()->subDays(5)->toDateString()]);
        $this->makeTask(['deadline' => now()->addDays(5)->toDateString()]);

        $stats = $this->actingAs($this->makeUser('yoshlar_hokim_orinbosari'), 'sanctum')
            ->getJson('/api/yoshlar/tasks/stats')->assertOk()->json();

        $this->assertArrayHasKey('on_time_rate', $stats);
        $this->assertGreaterThanOrEqual(1, $stats['overdue']);
    }

    public function test_executor_cannot_create_task(): void
    {
        $this->actingAs($this->executor(), 'sanctum')
            ->postJson('/api/yoshlar/tasks', $this->payload())
            ->assertStatus(403);
    }

    private function executor(): User
    {
        return $this->makeUser('sektor_bolim', $this->tumanSektor->id);
    }

    /** @param array<string, mixed> $attrs */
    private function makeTask(array $attrs = []): Task
    {
        return Task::query()->create(array_merge([
            'title' => 'TEST-'.Str::random(8),
            'assigned_org_id' => $this->tumanSektor->id,
            'district_id' => $this->districtId,
            'deadline' => now()->addDays(10)->toDateString(),
            'priority' => 'orta',
            'status' => 'belgilandi',
        ], $attrs));
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'title' => 'Yangi topshiriq '.Str::random(6),
            'assigned_org_id' => $this->tumanSektor->id,
            'district_id' => $this->districtId,
            'deadline' => now()->addDays(14)->toDateString(),
            'priority' => 'yuqori',
        ];
    }
}
