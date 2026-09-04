<?php

declare(strict_types=1);

namespace Tests\Feature\Yoshlar;

use App\Domains\Yoshlar\Models\Notification;
use App\Domains\Yoshlar\Models\Organization;
use App\Domains\Yoshlar\Models\Sector;
use App\Domains\Yoshlar\Models\Task;
use App\Domains\Yoshlar\Models\Youth;
use App\Domains\Yoshlar\Models\YouthCase;
use App\Domains\Yoshlar\Services\NotificationService;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * TZ 5.8 — in-app ogohlantirish va TZ 5.2 — muddat eskalatsiyasi.
 *
 * Asosiy g'oya: zanjirdagi har o'tish KEYINGI bo'g'inga xabar beradi,
 * muddat buzilsa esa xabar YUQORIGA ham chiqadi (eskalatsiya).
 */
class YoshlarNotificationTest extends YoshlarTestCase
{
    private Organization $province;

    private Organization $district;

    private string $districtId;

    protected function setUp(): void
    {
        parent::setUp();

        $sector = Sector::query()->create([
            'code' => 'test_n_'.Str::random(6),
            'name_cyr' => 'Синов',
            'name_lat' => 'Sinov',
        ]);

        $this->districtId = $this->someDistrictId();
        $this->province = $this->makeOrganization(Organization::TYPE_VILOYAT_SEKTOR, ['sector_id' => $sector->id]);
        $this->district = $this->makeOrganization(Organization::TYPE_TUMAN_SEKTOR, [
            'sector_id' => $sector->id,
            'parent_id' => $this->province->id,
            'district_id' => $this->districtId,
        ]);
    }

    public function test_submitting_report_notifies_next_link_in_chain(): void
    {
        $task = $this->makeTask();
        $reviewer = $this->makeUser('sektor_boshqarma', $this->province->id);

        $this->actingAs($this->executor(), 'sanctum')
            ->postJson("/api/yoshlar/tasks/{$task->id}/submit", ['progress' => 50])
            ->assertCreated();

        // Tasdiqlovchi (sektor boshqarmasi) xabar oldi.
        $this->assertTrue(
            Notification::query()->where('user_id', $reviewer->id)
                ->where('type', 'task.pending_review')->where('entity_id', $task->id)->exists(),
            'Zanjirdagi keyingi boʻgʻin xabar olmadi',
        );
    }

    public function test_returning_report_notifies_executor(): void
    {
        $task = $this->makeTask();
        $executor = $this->executor();

        $updateId = $this->actingAs($executor, 'sanctum')
            ->postJson("/api/yoshlar/tasks/{$task->id}/submit", ['progress' => 40])
            ->assertCreated()->json('data.id');

        $this->actingAs($this->makeUser('sektor_boshqarma', $this->province->id), 'sanctum')
            ->postJson("/api/yoshlar/task-updates/{$updateId}/review", ['approve' => false, 'comment' => 'Tuzating'])
            ->assertOk();

        $this->assertTrue(
            Notification::query()->where('user_id', $executor->id)
                ->where('type', 'task.returned')->exists(),
            'Ijrochi qaytarish haqida xabar olmadi',
        );
    }

    public function test_user_sees_only_own_notifications(): void
    {
        $mine = $this->makeUser('sektor_bolim', $this->district->id);
        $other = $this->makeUser('yoshlar_admin');

        Notification::query()->create([
            'user_id' => $other->id,
            'type' => 'task.overdue',
            'title' => 'Begona xabar',
        ]);

        $data = $this->actingAs($mine, 'sanctum')
            ->getJson('/api/yoshlar/notifications')->assertOk()->json('data');

        foreach ($data as $row) {
            $this->assertSame($mine->id, $row['user_id']);
        }
    }

    public function test_mark_read_reduces_unread_count(): void
    {
        $user = $this->makeUser('yoshlar_admin');

        Notification::query()->create([
            'user_id' => $user->id,
            'type' => 'task.overdue',
            'title' => 'Sinov xabari',
            'entity_id' => (string) Str::uuid(),
        ]);

        $before = $this->actingAs($user, 'sanctum')
            ->getJson('/api/yoshlar/notifications')->assertOk()->json('unread');

        $this->assertGreaterThan(0, $before);

        $after = $this->actingAs($user, 'sanctum')
            ->postJson('/api/yoshlar/notifications/read')->assertOk()->json('unread');

        $this->assertSame(0, $after);
    }

    public function test_duplicate_unread_notification_is_not_created(): void
    {
        $user = $this->makeUser('sektor_bolim', $this->district->id);
        $entityId = (string) Str::uuid();

        $service = app(NotificationService::class);

        $payload = ['title' => 'Takror', 'entity_id' => $entityId, 'entity_type' => 'task'];

        $first = $service->notifyUsers([$user->id], 'task.overdue', $payload);
        $second = $service->notifyUsers([$user->id], 'task.overdue', $payload);

        $this->assertSame(1, $first);
        $this->assertSame(0, $second, 'Bir xil oʻqilmagan xabar takrorlandi');
    }

    // ---------------- Eskalatsiya ----------------

    public function test_overdue_task_escalates_upward(): void
    {
        $task = $this->makeTask(['deadline' => now()->subDays(3)->toDateString()]);

        $executor = $this->makeUser('sektor_bolim', $this->district->id);
        $parentUser = $this->makeUser('sektor_boshqarma', $this->province->id);
        $provinceYouth = $this->makeUser('yoshlar_boshqarma');

        $this->artisan('yoshlar:check-deadlines')->assertSuccessful();

        // Ijrochi xabar oladi...
        $this->assertTrue(
            Notification::query()->where('user_id', $executor->id)
                ->where('type', 'task.overdue')->where('entity_id', $task->id)->exists(),
        );

        // ...VA yuqori tashkilot ham (eskalatsiya).
        $this->assertTrue(
            Notification::query()->where('user_id', $parentUser->id)
                ->where('type', 'task.overdue')->where('entity_id', $task->id)->exists(),
            'Eskalatsiya yuqoriga chiqmadi',
        );

        // ...VA viloyat yoshlar boshqarmasi.
        $this->assertTrue(
            Notification::query()->where('user_id', $provinceYouth->id)
                ->where('type', 'task.overdue')->where('entity_id', $task->id)->exists(),
        );
    }

    public function test_deadline_near_notifies_executor_only(): void
    {
        $task = $this->makeTask(['deadline' => now()->addDay()->toDateString()]);
        $executor = $this->makeUser('sektor_bolim', $this->district->id);

        $this->artisan('yoshlar:check-deadlines')->assertSuccessful();

        $this->assertTrue(
            Notification::query()->where('user_id', $executor->id)
                ->where('type', 'task.deadline_near')->where('entity_id', $task->id)->exists(),
        );
    }

    public function test_dry_run_sends_nothing(): void
    {
        $this->makeTask(['deadline' => now()->subDays(5)->toDateString()]);
        $executor = $this->makeUser('sektor_bolim', $this->district->id);

        $this->artisan('yoshlar:check-deadlines --dry-run')->assertSuccessful();

        $this->assertSame(
            0,
            Notification::query()->where('user_id', $executor->id)->count(),
        );
    }

    public function test_overdue_case_notifies_assigned_org(): void
    {
        $youth = Youth::query()->create([
            'last_name' => 'SLA', 'first_name' => 'Test'.Str::random(4),
            'birth_date' => now()->subYears(18)->toDateString(), 'gender' => 'erkak',
            'district_id' => $this->districtId, 'mahalla_id' => $this->someMahallaId($this->districtId),
        ]);

        YouthCase::query()->create([
            'youth_id' => $youth->id,
            'district_id' => $this->districtId,
            'category' => 'bandlik',
            'source' => 'mahalla',
            'title' => 'SLA buzilgan muammo',
            'status' => 'jarayonda',
            'assigned_org_id' => $this->district->id,
            'sla_deadline' => now()->subDays(2)->toDateString(),
        ]);

        $officer = $this->makeUser('sektor_bolim', $this->district->id);

        $this->artisan('yoshlar:check-deadlines')->assertSuccessful();

        $this->assertTrue(
            Notification::query()->where('user_id', $officer->id)
                ->where('type', 'case.overdue')->exists(),
        );
    }

    private function executor(): User
    {
        return $this->makeUser('sektor_bolim', $this->district->id);
    }

    /** @param array<string, mixed> $attrs */
    private function makeTask(array $attrs = []): Task
    {
        return Task::query()->create(array_merge([
            'title' => 'TEST-'.Str::random(8),
            'assigned_org_id' => $this->district->id,
            'district_id' => $this->districtId,
            'deadline' => now()->addDays(10)->toDateString(),
            'priority' => 'orta',
            'status' => 'belgilandi',
        ], $attrs));
    }
}
