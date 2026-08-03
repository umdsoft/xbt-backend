<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Rol-scoping va IDOR chegaralari — Loyihalar (spec §2, §6).
 *
 * viloyat: barcha tuman (yaratish/tahrir/o'chirish);
 * tuman:   FAQAT o'z tumani (yaratish/tahrir/yangilanish; o'chira olmaydi);
 * bo'linma: faqat ko'rish.
 */
class ProjectsAccessTest extends AdvisorTestCase
{
    public function test_unauthenticated_401_and_non_advisor_403(): void
    {
        $this->getJson('/api/advisor/projects')->assertUnauthorized();

        $this->actingAs($this->makeOutsider(), 'sanctum')
            ->getJson('/api/advisor/projects')->assertForbidden();
    }

    public function test_tuman_sees_only_own_district_projects(): void
    {
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');
        $mine = $this->someDistrictId();
        $other = $this->anotherDistrictId($mine);
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $mine);

        $otherId = $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/projects', [
            'district_id' => $other, 'title' => 'Бошқа туман лойиҳаси',
        ])->json('id');
        $mineId = $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/projects', [
            'district_id' => $mine, 'title' => 'Менинг лойиҳам',
        ])->json('id');

        $ids = collect($this->actingAs($tuman, 'sanctum')->getJson('/api/advisor/projects')->json('data'))
            ->pluck('id')->all();

        $this->assertContains($mineId, $ids);
        $this->assertNotContains($otherId, $ids);

        // Boshqa tuman loyihasini ochib bo'lmaydi (404 — IDOR himoyasi).
        $this->actingAs($tuman, 'sanctum')->getJson('/api/advisor/projects/'.$otherId)->assertNotFound();
    }

    public function test_tuman_cannot_create_for_other_district(): void
    {
        $mine = $this->someDistrictId();
        $other = $this->anotherDistrictId($mine);
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $mine);

        // O'z tumani — OK.
        $this->actingAs($tuman, 'sanctum')->postJson('/api/advisor/projects', [
            'district_id' => $mine, 'title' => 'Ўз тумани',
        ])->assertCreated();

        // Boshqa tuman — 403.
        $this->actingAs($tuman, 'sanctum')->postJson('/api/advisor/projects', [
            'district_id' => $other, 'title' => 'Ноқонуний',
        ])->assertForbidden();
    }

    public function test_tuman_cannot_edit_other_district_project(): void
    {
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');
        $mine = $this->someDistrictId();
        $other = $this->anotherDistrictId($mine);
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $mine);

        $otherId = $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/projects', [
            'district_id' => $other, 'title' => 'Бошқа',
        ])->json('id');

        $this->actingAs($tuman, 'sanctum')->patchJson('/api/advisor/projects/'.$otherId, ['status' => 'done'])
            ->assertForbidden();
        $this->actingAs($tuman, 'sanctum')->postJson('/api/advisor/projects/'.$otherId.'/updates', ['body' => 'X'])
            ->assertForbidden();
    }

    public function test_bolinma_can_view_but_not_manage(): void
    {
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');
        $bolinma = $this->makeAdvisor('advisor_bolinma', 'bolinma');
        $district = $this->someDistrictId();

        $id = $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/projects', [
            'district_id' => $district, 'title' => 'Кўриш учун',
        ])->json('id');

        // Ko'radi.
        $this->actingAs($bolinma, 'sanctum')->getJson('/api/advisor/projects')->assertOk();
        $this->actingAs($bolinma, 'sanctum')->getJson('/api/advisor/projects/'.$id)->assertOk();

        // Yarata/tahrir/o'chira OLMAYDI.
        $this->actingAs($bolinma, 'sanctum')->postJson('/api/advisor/projects', [
            'district_id' => $district, 'title' => 'Йўқ',
        ])->assertForbidden();
        $this->actingAs($bolinma, 'sanctum')->patchJson('/api/advisor/projects/'.$id, ['status' => 'done'])->assertForbidden();
        $this->actingAs($bolinma, 'sanctum')->deleteJson('/api/advisor/projects/'.$id)->assertForbidden();
    }

    public function test_tuman_cannot_delete_project(): void
    {
        $district = $this->someDistrictId();
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);

        $id = $this->actingAs($tuman, 'sanctum')->postJson('/api/advisor/projects', [
            'district_id' => $district, 'title' => 'Ўз лойиҳам',
        ])->json('id');

        // O'chirish faqat viloyat huquqi.
        $this->actingAs($tuman, 'sanctum')->deleteJson('/api/advisor/projects/'.$id)->assertForbidden();
    }

    public function test_tuman_cannot_stream_other_district_file(): void
    {
        Storage::fake('local');
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');
        $mine = $this->someDistrictId();
        $other = $this->anotherDistrictId($mine);
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $mine);

        $otherId = $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/projects', [
            'district_id' => $other, 'title' => 'Бошқа туман',
        ])->json('id');
        $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/projects/'.$otherId.'/files', [
            'files' => [UploadedFile::fake()->image('m.png')],
        ])->assertCreated();

        $fileId = DB::connection('advisor')->table('project_files')->where('project_id', $otherId)->value('id');

        // Tuman boshqa tuman faylini stream qila OLMAYDI (404).
        $this->actingAs($tuman, 'sanctum')->get('/api/advisor/projects/files/'.$fileId)->assertNotFound();
        // Viloyat — OK.
        $this->actingAs($viloyat, 'sanctum')->get('/api/advisor/projects/files/'.$fileId)->assertOk();
    }
}
