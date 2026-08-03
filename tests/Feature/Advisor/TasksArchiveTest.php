<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor;

use Illuminate\Support\Facades\DB;

/**
 * Arxiv / bilim bazasi: qidiruv-filtr + xlsx eksport (spec §5).
 */
class TasksArchiveTest extends AdvisorTestCase
{
    public function test_archive_search_filters_by_query_tag_and_district(): void
    {
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');
        $d1 = $this->someDistrictId();
        $d2 = $this->anotherDistrictId($d1);

        $matchId = $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/tasks', [
            'source' => 'Президент Администрацияси',
            'title' => 'Ноёб калит СИ платформа',
            'district_ids' => [$d1],
            'tags' => ['ноёбтег'],
        ])->json('id');

        $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/tasks', [
            'title' => 'Бошқа мавзу', 'district_ids' => [$d2], 'tags' => ['бошқатег'],
        ])->json('id');

        // q bo'yicha.
        $byQuery = collect($this->actingAs($viloyat, 'sanctum')
            ->getJson('/api/advisor/archive?q=Ноёб калит')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'source', 'title', 'category', 'status', 'districts', 'tags', 'created_at']], 'meta'])
            ->json('data'))->pluck('id')->all();
        $this->assertContains($matchId, $byQuery);

        // tag bo'yicha.
        $byTag = collect($this->actingAs($viloyat, 'sanctum')
            ->getJson('/api/advisor/archive?tag=ноёбтег')->json('data'))->pluck('id')->all();
        $this->assertContains($matchId, $byTag);
        $this->assertCount(1, $byTag);
    }

    public function test_tuman_archive_is_scoped_and_cannot_export(): void
    {
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');
        $mine = $this->someDistrictId();
        $other = $this->anotherDistrictId($mine);
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $mine);

        $mineId = $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/tasks', [
            'title' => 'Менинг архивим', 'district_ids' => [$mine],
        ])->json('id');
        $otherId = $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/tasks', [
            'title' => 'Бегона архив', 'district_ids' => [$other],
        ])->json('id');

        $ids = collect($this->actingAs($tuman, 'sanctum')->getJson('/api/advisor/archive')->json('data'))
            ->pluck('id')->all();
        $this->assertContains($mineId, $ids);
        $this->assertNotContains($otherId, $ids);

        // Tuman eksport qila olmaydi (viloyat/bo'linma huquqi).
        $this->actingAs($tuman, 'sanctum')->get('/api/advisor/archive/export')->assertForbidden();
    }

    public function test_viloyat_exports_xlsx(): void
    {
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');
        $district = $this->someDistrictId();
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);

        $taskId = $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/tasks', [
            'title' => 'Эксport иши', 'district_ids' => [$district],
        ])->json('id');
        $targetId = DB::connection('advisor')
            ->table('task_targets')->where('task_id', $taskId)->value('id');
        $this->actingAs($tuman, 'sanctum')->postJson('/api/advisor/tasks/'.$taskId.'/report', [
            'target_id' => $targetId, 'body' => 'Ҳисобот',
        ])->assertCreated();

        $res = $this->actingAs($viloyat, 'sanctum')->get('/api/advisor/archive/export');
        $res->assertOk();
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $res->headers->get('Content-Type'),
        );
        $this->assertStringContainsString('.xlsx', (string) $res->headers->get('Content-Disposition'));
        // XLSX = ZIP (PK sarlavhasi).
        $this->assertStringStartsWith('PK', $res->getContent());
    }
}
