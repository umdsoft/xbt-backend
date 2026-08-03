<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor;

use Illuminate\Support\Facades\DB;

/**
 * Faoliyat nazorati — har maslahatchi bo'yicha agregat satr (spec §9). FAQAT
 * viloyat/bo'linma; tuman -> 403.
 */
class OversightTest extends AdvisorTestCase
{
    public function test_viloyat_sees_per_advisor_oversight_rows(): void
    {
        $district = $this->someDistrictId();
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);

        // Ochiq (muddati o'tган) topshiriq + tuman hisoboti (so'nggi faoliyat/pending).
        $taskId = $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/tasks', [
            'title' => 'Назорат топшириғи',
            'deadline' => '2020-01-01',
            'district_ids' => [$district],
        ])->json('id');
        $targetId = DB::connection('advisor')->table('task_targets')
            ->where('task_id', $taskId)->where('district_id', $district)->value('id');
        $this->actingAs($tuman, 'sanctum')->postJson('/api/advisor/tasks/'.$taskId.'/report', [
            'target_id' => $targetId, 'body' => 'Иш',
        ])->assertCreated();

        $rows = $this->actingAs($viloyat, 'sanctum')->getJson('/api/advisor/oversight')
            ->assertOk()
            ->assertJsonStructure([[
                'advisor' => ['id', 'name'], 'district', 'level',
                'open_tasks', 'overdue', 'reports_pending', 'kpi_avg', 'last_activity',
            ]])
            ->json();

        $tumanAdvisorId = DB::connection('advisor')->table('advisors')->where('user_id', $tuman->id)->value('id');
        $row = collect($rows)->firstWhere('advisor.id', $tumanAdvisorId);

        $this->assertNotNull($row);
        $this->assertSame($district, $row['district']['id']);
        $this->assertSame('tuman', $row['level']);
        $this->assertSame(1, $row['open_tasks']);
        $this->assertSame(1, $row['overdue']);
        $this->assertSame(1, $row['reports_pending']);
        $this->assertNotNull($row['last_activity']);
    }

    public function test_bolinma_row_is_region_wide(): void
    {
        $district = $this->someDistrictId();
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');
        $bolinma = $this->makeAdvisor('advisor_bolinma', 'bolinma');

        $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/tasks', [
            'title' => 'Жами очиқ',
            'district_ids' => [$district],
        ])->assertCreated();

        $rows = $this->actingAs($viloyat, 'sanctum')->getJson('/api/advisor/oversight')->assertOk()->json();

        $bolinmaAdvisorId = DB::connection('advisor')->table('advisors')->where('user_id', $bolinma->id)->value('id');
        $row = collect($rows)->firstWhere('advisor.id', $bolinmaAdvisorId);

        // Tumansiz (bo'linma) satri butun viloyat yig'indisini ko'rsatadi.
        $this->assertNotNull($row);
        $this->assertNull($row['district']);
        $this->assertSame('bolinma', $row['level']);
        $this->assertGreaterThanOrEqual(1, $row['open_tasks']);
    }

    public function test_tuman_cannot_access_oversight(): void
    {
        $district = $this->someDistrictId();
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);

        $this->actingAs($tuman, 'sanctum')->getJson('/api/advisor/oversight')->assertForbidden();
    }
}
