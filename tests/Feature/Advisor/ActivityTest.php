<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor;

use Illuminate\Support\Facades\DB;

/**
 * Faoliyat lentasi (spec §9) — derived UNION. Viloyat hammani; tuman FAQAT o'z
 * tumani (IDOR himoyasi).
 */
class ActivityTest extends AdvisorTestCase
{
    public function test_viloyat_sees_task_and_report_activity(): void
    {
        $district = $this->someDistrictId();
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);

        $taskId = $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/tasks', [
            'title' => 'Лента топшириғи',
            'district_ids' => [$district],
        ])->json('id');
        $targetId = DB::connection('advisor')->table('task_targets')
            ->where('task_id', $taskId)->where('district_id', $district)->value('id');
        $this->actingAs($tuman, 'sanctum')->postJson('/api/advisor/tasks/'.$taskId.'/report', [
            'target_id' => $targetId, 'body' => 'Бажарилди',
        ])->assertCreated();

        $feed = $this->actingAs($viloyat, 'sanctum')->getJson('/api/advisor/activity')
            ->assertOk()
            ->assertJsonStructure([['time', 'advisor', 'type', 'summary', 'ref']])
            ->json();

        $types = collect($feed)->pluck('type')->all();
        $this->assertContains('task_created', $types);
        $this->assertContains('report_submitted', $types);

        // Hisobot lentasida maslahatchi FIO bo'ladi (advisor_id -> auth.users).
        $report = collect($feed)->firstWhere('type', 'report_submitted');
        $this->assertNotNull($report['advisor']['name'] ?? null);
    }

    public function test_tuman_sees_only_own_district(): void
    {
        $mine = $this->someDistrictId();
        $other = $this->anotherDistrictId($mine);
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $mine);

        // Boshqa tumanga topshiriq -> tuman(mine) ko'rmasin.
        $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/tasks', [
            'title' => 'БЕГОНА туман топшириғи',
            'district_ids' => [$other],
        ])->assertCreated();
        // O'z tumaniga topshiriq -> ko'rsin.
        $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/tasks', [
            'title' => 'МЕНИНГ туман топшириғи',
            'district_ids' => [$mine],
        ])->assertCreated();

        $feed = $this->actingAs($tuman, 'sanctum')->getJson('/api/advisor/activity')->assertOk()->json();

        $summaries = collect($feed)->pluck('summary')->implode(' | ');
        $this->assertStringContainsString('МЕНИНГ', $summaries);
        $this->assertStringNotContainsString('БЕГОНА', $summaries);
    }

    public function test_limit_is_respected(): void
    {
        $district = $this->someDistrictId();
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/tasks', [
                'title' => 'Топшириқ '.$i,
                'district_ids' => [$district],
            ])->assertCreated();
        }

        $feed = $this->actingAs($viloyat, 'sanctum')->getJson('/api/advisor/activity?limit=3')->assertOk()->json();
        $this->assertCount(3, $feed);
    }
}
