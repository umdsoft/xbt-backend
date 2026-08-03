<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor;

use Illuminate\Support\Facades\DB;

/**
 * Rol-ruxsat va qamrov chegaralari (spec §2, §5).
 */
class TasksAccessTest extends AdvisorTestCase
{
    public function test_unauthenticated_gets_401(): void
    {
        $this->getJson('/api/advisor/tasks')->assertUnauthorized();
    }

    public function test_non_advisor_gets_403(): void
    {
        $this->actingAs($this->makeOutsider(), 'sanctum')
            ->getJson('/api/advisor/tasks')->assertForbidden();
    }

    public function test_bolinma_cannot_create_or_approve(): void
    {
        $district = $this->someDistrictId();
        $bolinma = $this->makeAdvisor('advisor_bolinma', 'bolinma');
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');

        // Yarata olmaydi.
        $this->actingAs($bolinma, 'sanctum')->postJson('/api/advisor/tasks', [
            'title' => 'Йўқ', 'district_ids' => [$district],
        ])->assertForbidden();

        // Topshiriq + hisobot tayyorlaymiz.
        $taskId = $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/tasks', [
            'title' => 'Тест', 'district_ids' => [$district],
        ])->json('id');
        $targetId = DB::connection('advisor')->table('task_targets')->where('task_id', $taskId)->value('id');
        $reportId = $this->actingAs($tuman, 'sanctum')->postJson('/api/advisor/tasks/'.$taskId.'/report', [
            'target_id' => $targetId, 'body' => 'Иш',
        ])->json('id');

        // Bo'linma tasdiqlay OLMAYDI (viloyat huquqi), lekin qaytara OLADI.
        $this->actingAs($bolinma, 'sanctum')->postJson('/api/advisor/reports/'.$reportId.'/approve')->assertForbidden();
        $this->actingAs($bolinma, 'sanctum')->postJson('/api/advisor/reports/'.$reportId.'/return', ['comment' => 'Қайтди'])->assertOk();
    }

    public function test_tuman_cannot_qa_or_approve(): void
    {
        $district = $this->someDistrictId();
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');

        $taskId = $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/tasks', [
            'title' => 'Тест', 'district_ids' => [$district],
        ])->json('id');
        $targetId = DB::connection('advisor')->table('task_targets')->where('task_id', $taskId)->value('id');
        $reportId = $this->actingAs($tuman, 'sanctum')->postJson('/api/advisor/tasks/'.$taskId.'/report', [
            'target_id' => $targetId, 'body' => 'Иш',
        ])->json('id');

        $this->actingAs($tuman, 'sanctum')->postJson('/api/advisor/reports/'.$reportId.'/qa')->assertForbidden();
        $this->actingAs($tuman, 'sanctum')->postJson('/api/advisor/reports/'.$reportId.'/approve')->assertForbidden();
    }

    public function test_tuman_cannot_report_on_other_district_target(): void
    {
        $mine = $this->someDistrictId();
        $other = $this->anotherDistrictId($mine);
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $mine);
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');

        // Boshqa tumanга topshiriq.
        $taskId = $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/tasks', [
            'title' => 'Бошқа', 'district_ids' => [$other],
        ])->json('id');
        $foreignTarget = DB::connection('advisor')->table('task_targets')->where('task_id', $taskId)->value('id');

        $this->actingAs($tuman, 'sanctum')->postJson('/api/advisor/tasks/'.$taskId.'/report', [
            'target_id' => $foreignTarget, 'body' => 'Ноқонуний',
        ])->assertForbidden();
    }
}
