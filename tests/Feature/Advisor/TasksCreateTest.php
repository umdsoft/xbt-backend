<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor;

use Illuminate\Support\Facades\DB;

/**
 * Topshiriq yaratish + tumanlarga tarqatish + qamrovli ro'yxat (spec §5).
 */
class TasksCreateTest extends AdvisorTestCase
{
    public function test_viloyat_creates_task_and_distributes_to_districts(): void
    {
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');
        $d1 = $this->someDistrictId();
        $d2 = $this->anotherDistrictId($d1);

        $id = $this->actingAs($viloyat, 'sanctum')
            ->postJson('/api/advisor/tasks', [
                'source' => 'Вазирлик',
                'title' => 'СИ лойиҳасини жорий этиш',
                'description' => 'Тавсиф',
                'expected_result' => 'Кутилган натижа',
                'category_id' => $this->aCategoryId(),
                'priority' => 'high',
                'deadline' => now()->addWeek()->toDateString(),
                'district_ids' => [$d1, $d2],
                'tags' => ['си', 'рейтинг'],
            ])
            ->assertCreated()
            ->json('id');

        $this->assertNotNull($id);

        // Ikkita nishon (tuman) yaratildi.
        $this->assertSame(2, DB::connection('advisor')
            ->table('task_targets')->where('task_id', $id)->count());

        // Ro'yxatda ko'rinadi (targets bilan).
        $body = $this->actingAs($viloyat, 'sanctum')
            ->getJson('/api/advisor/tasks')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'source', 'title', 'deadline', 'status', 'category', 'targets', 'created_at']],
                'meta' => ['total', 'current_page', 'last_page', 'per_page'],
            ])
            ->json();

        $task = collect($body['data'])->firstWhere('id', $id);
        $this->assertNotNull($task);
        $this->assertSame('open', $task['status']);
        $this->assertCount(2, $task['targets']);
        $this->assertSame('СИ лойиҳасини жорий этиш', $task['title']);
    }

    public function test_tuman_cannot_create_task(): void
    {
        $d = $this->someDistrictId();
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $d);

        $this->actingAs($tuman, 'sanctum')
            ->postJson('/api/advisor/tasks', [
                'title' => 'Ноқонуний',
                'district_ids' => [$d],
            ])
            ->assertForbidden();
    }

    public function test_tuman_sees_only_own_district_tasks(): void
    {
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');
        $mine = $this->someDistrictId();
        $other = $this->anotherDistrictId($mine);
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $mine);

        // Faqat boshqa tumanга topshiriq.
        $otherId = $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/tasks', [
            'title' => 'Бошқа туман иши', 'district_ids' => [$other],
        ])->json('id');

        // Mening tumanимга topshiriq.
        $mineId = $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/tasks', [
            'title' => 'Менинг ишим', 'district_ids' => [$mine],
        ])->json('id');

        $ids = collect($this->actingAs($tuman, 'sanctum')->getJson('/api/advisor/tasks')->json('data'))
            ->pluck('id')->all();

        $this->assertContains($mineId, $ids);
        $this->assertNotContains($otherId, $ids);

        // Boshqa tuman topshirig'ini ochib bo'lmaydi (404).
        $this->actingAs($tuman, 'sanctum')->getJson('/api/advisor/tasks/'.$otherId)->assertNotFound();
    }
}
