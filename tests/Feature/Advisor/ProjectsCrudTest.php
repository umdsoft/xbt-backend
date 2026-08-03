<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor;

use Illuminate\Support\Facades\DB;

/**
 * Loyiha yaratish + ro'yxat + ko'rish + o'chirish (spec §6).
 */
class ProjectsCrudTest extends AdvisorTestCase
{
    public function test_viloyat_creates_project_and_it_appears_in_list_and_show(): void
    {
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');
        $district = $this->someDistrictId();

        $id = $this->actingAs($viloyat, 'sanctum')
            ->postJson('/api/advisor/projects', [
                'district_id' => $district,
                'category_id' => $this->aCategoryId(),
                'title' => 'СИ чат-бот жорий этиш',
                'description' => 'Тавсиф',
                'planned_start' => now()->toDateString(),
                'planned_end' => now()->addMonth()->toDateString(),
                'status' => 'in_progress',
            ])
            ->assertCreated()
            ->json('id');

        $this->assertNotNull($id);
        $this->assertSame(0, (int) DB::connection('advisor')->table('projects')->where('id', $id)->value('progress_percent'));

        // Ro'yxat shakli (frontend shartnomasi).
        $body = $this->actingAs($viloyat, 'sanctum')
            ->getJson('/api/advisor/projects')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'district' => ['id', 'name'], 'title', 'status', 'progress_percent', 'planned_end', 'updated_at']],
                'meta' => ['total', 'current_page', 'last_page', 'per_page'],
            ])
            ->json();

        $row = collect($body['data'])->firstWhere('id', $id);
        $this->assertNotNull($row);
        $this->assertSame('in_progress', $row['status']);
        $this->assertSame($district, $row['district']['id']);
        $this->assertNotEmpty($row['district']['name']);

        // Ko'rish shakli.
        $this->actingAs($viloyat, 'sanctum')
            ->getJson('/api/advisor/projects/'.$id)
            ->assertOk()
            ->assertJsonStructure([
                'project' => ['id', 'title', 'status', 'progress_percent', 'planned_end', 'district' => ['id', 'name']],
                'updates',
                'files',
            ])
            ->assertJsonPath('project.id', $id);
    }

    public function test_status_filter_and_pagination_meta(): void
    {
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');
        $district = $this->someDistrictId();

        $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/projects', [
            'district_id' => $district, 'title' => 'Режалаштирилган', 'status' => 'planned',
        ])->assertCreated();
        $doneId = $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/projects', [
            'district_id' => $district, 'title' => 'Тугалланган', 'status' => 'done',
        ])->json('id');

        $data = $this->actingAs($viloyat, 'sanctum')
            ->getJson('/api/advisor/projects?status=done')
            ->assertOk()
            ->json('data');

        $ids = collect($data)->pluck('id')->all();
        $this->assertContains($doneId, $ids);
        foreach ($data as $row) {
            $this->assertSame('done', $row['status']);
        }
    }

    public function test_viloyat_soft_deletes_project(): void
    {
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');
        $district = $this->someDistrictId();

        $id = $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/projects', [
            'district_id' => $district, 'title' => 'Ўчириладиган',
        ])->json('id');

        $this->actingAs($viloyat, 'sanctum')->deleteJson('/api/advisor/projects/'.$id)->assertOk();

        // Soft delete: qator qoladi, deleted_at to'ldiriladi; ro'yxat/ko'rishda yo'q.
        $this->assertNotNull(DB::connection('advisor')->table('projects')->where('id', $id)->value('deleted_at'));
        $this->actingAs($viloyat, 'sanctum')->getJson('/api/advisor/projects/'.$id)->assertNotFound();

        $ids = collect($this->actingAs($viloyat, 'sanctum')->getJson('/api/advisor/projects')->json('data'))->pluck('id')->all();
        $this->assertNotContains($id, $ids);
    }
}
