<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Loyiha tahriri (PATCH) + progress yangilanishi + fayl yuklash/stream (spec §6).
 */
class ProjectsUpdateTest extends AdvisorTestCase
{
    /** Bitta tuman uchun loyiha yaratadi va id qaytaradi. */
    private function projectForDistrict(string $district): string
    {
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');

        return $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/projects', [
            'district_id' => $district, 'title' => 'Синов лойиҳа',
        ])->json('id');
    }

    public function test_patch_updates_status_and_fields(): void
    {
        $district = $this->someDistrictId();
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');
        $id = $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/projects', [
            'district_id' => $district, 'title' => 'Эски ном',
        ])->json('id');

        $this->actingAs($viloyat, 'sanctum')->patchJson('/api/advisor/projects/'.$id, [
            'title' => 'Янги ном',
            'status' => 'done',
            'progress_percent' => 100,
            'actual_end' => now()->toDateString(),
        ])->assertOk();

        $row = DB::connection('advisor')->table('projects')->where('id', $id)->first();
        $this->assertSame('Янги ном', $row->title);
        $this->assertSame('done', $row->status);
        $this->assertSame(100, (int) $row->progress_percent);
        $this->assertNotNull($row->actual_end);
    }

    public function test_add_update_bumps_progress_and_shows_in_history(): void
    {
        $district = $this->someDistrictId();
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);
        $id = $this->projectForDistrict($district);

        $updateId = $this->actingAs($tuman, 'sanctum')
            ->postJson('/api/advisor/projects/'.$id.'/updates', [
                'body' => 'Иккинчи босқич бошланди',
                'progress_percent' => 45,
            ])
            ->assertCreated()
            ->json('id');

        $this->assertNotNull($updateId);
        // Loyiha progressi yangilandi.
        $this->assertSame(45, (int) DB::connection('advisor')->table('projects')->where('id', $id)->value('progress_percent'));

        // Progress berilmagan yangilanish progressni O'ZGARTIRMAYDI.
        $this->actingAs($tuman, 'sanctum')->postJson('/api/advisor/projects/'.$id.'/updates', [
            'body' => 'Оддий изоҳ',
        ])->assertCreated();
        $this->assertSame(45, (int) DB::connection('advisor')->table('projects')->where('id', $id)->value('progress_percent'));

        // show — tarixда 2 yangilanish (user.name bilan).
        $show = $this->actingAs($tuman, 'sanctum')->getJson('/api/advisor/projects/'.$id)
            ->assertOk()
            ->assertJsonStructure([
                'updates' => [['id', 'body', 'progress_percent', 'occurred_at', 'user' => ['name']]],
            ])
            ->json();
        $this->assertCount(2, $show['updates']);
        $this->assertNotEmpty($show['updates'][0]['user']['name']);
    }

    public function test_upload_and_stream_project_file(): void
    {
        Storage::fake('local');
        $district = $this->someDistrictId();
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);
        $id = $this->projectForDistrict($district);

        $this->actingAs($tuman, 'sanctum')
            ->postJson('/api/advisor/projects/'.$id.'/files', [
                'files' => [UploadedFile::fake()->image('reja.png')],
            ])
            ->assertCreated()
            ->assertJsonStructure(['ids']);

        $fileId = DB::connection('advisor')->table('project_files')->where('project_id', $id)->value('id');
        $this->assertNotNull($fileId);

        // show fayllarni qaytaradi.
        $files = $this->actingAs($tuman, 'sanctum')->getJson('/api/advisor/projects/'.$id)
            ->assertOk()
            ->assertJsonStructure(['files' => [['id', 'original_name', 'mime']]])
            ->json('files');
        $this->assertCount(1, $files);

        // Maxfiy diskdan stream.
        $this->actingAs($tuman, 'sanctum')->get('/api/advisor/projects/files/'.$fileId)->assertOk();
    }
}
