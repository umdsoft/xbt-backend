<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Hisobot + dalil oqimi: yuborish -> QA -> tasdiq / qaytarish (spec §5).
 */
class TasksReportTest extends AdvisorTestCase
{
    /** Bitta target uchun task yaratadi va [taskId, targetId] qaytaradi. */
    private function taskForDistrict(string $district): array
    {
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');
        $taskId = $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/tasks', [
            'title' => 'Ҳисобот учун топшириқ',
            'expected_result' => 'Натижа',
            'district_ids' => [$district],
        ])->json('id');

        $targetId = DB::connection('advisor')->table('task_targets')
            ->where('task_id', $taskId)->where('district_id', $district)->value('id');

        return [$taskId, (string) $targetId];
    }

    public function test_tuman_submits_report_with_evidence(): void
    {
        Storage::fake('local');
        $district = $this->someDistrictId();
        [$taskId, $targetId] = $this->taskForDistrict($district);
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);

        $reportId = $this->actingAs($tuman, 'sanctum')
            ->postJson('/api/advisor/tasks/'.$taskId.'/report', [
                'target_id' => $targetId,
                'body' => 'Иш бажарилди',
                'files' => [UploadedFile::fake()->image('dalil.jpg')],
                'links' => ['https://example.uz/hujjat'],
                'gps' => ['lat' => 41.55, 'lng' => 60.63, 'accuracy' => 12.5],
            ])
            ->assertCreated()
            ->assertJson(['status' => 'pending'])
            ->json('id');

        // Hisobot pending, nishon reported, topshiriq in_progress.
        $this->assertSame('pending', DB::connection('advisor')->table('task_reports')->where('id', $reportId)->value('status'));
        $this->assertSame('reported', DB::connection('advisor')->table('task_targets')->where('id', $targetId)->value('status'));
        $this->assertSame('in_progress', DB::connection('advisor')->table('tasks')->where('id', $taskId)->value('status'));

        // 3 dalil (photo + link + gps).
        $kinds = DB::connection('advisor')->table('report_files')->where('report_id', $reportId)->pluck('kind')->all();
        $this->assertContains('photo', $kinds);
        $this->assertContains('link', $kinds);
        $this->assertContains('gps', $kinds);

        // show hisobotni dalil bilan qaytaradi.
        $show = $this->actingAs($tuman, 'sanctum')->getJson('/api/advisor/tasks/'.$taskId)
            ->assertOk()
            ->assertJsonStructure([
                'task' => ['id', 'title', 'category', 'tags'],
                'targets' => [['id', 'district', 'status', 'due_at', 'report']],
                'reports' => [['id', 'body', 'status', 'submitted_at', 'advisor' => ['name'], 'files' => [['id', 'kind', 'original_name']]]],
            ])
            ->json();
        $this->assertSame($reportId, $show['reports'][0]['id']);

        // Dalil shartnomasi (frontend ReportFiles): havola -> ochиладиган url;
        // GPS -> koordината (original_name) + xarita url. Aks holда UI'да ochilmaydi.
        $byKind = collect($show['reports'][0]['files'])->keyBy('kind');
        $this->assertStringStartsWith('http', (string) $byKind['link']['url']);
        $this->assertNotEmpty($byKind['gps']['url']);
        $this->assertStringContainsString('41.55', (string) $byKind['gps']['original_name']);

        // targets[].report — to'liq shakl (frontend ReportSubmitModal o'qийди).
        $this->assertSame($reportId, $show['targets'][0]['report']['id']);
        $this->assertSame('Иш бажарилди', $show['targets'][0]['report']['body']);
        $this->assertNotEmpty($show['targets'][0]['report']['files']);

        // Dalil faylini stream qilib olish (photo).
        $photoId = DB::connection('advisor')->table('report_files')
            ->where('report_id', $reportId)->where('kind', 'photo')->value('id');
        $this->actingAs($tuman, 'sanctum')->get('/api/advisor/reports/files/'.$photoId)->assertOk();
    }

    public function test_qa_then_approve_closes_target_and_task(): void
    {
        Storage::fake('local');
        $district = $this->someDistrictId();
        [$taskId, $targetId] = $this->taskForDistrict($district);
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);
        $bolinma = $this->makeAdvisor('advisor_bolinma', 'bolinma');
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');

        $reportId = $this->actingAs($tuman, 'sanctum')->postJson('/api/advisor/tasks/'.$taskId.'/report', [
            'target_id' => $targetId, 'body' => 'Бажарилди',
        ])->json('id');

        // Bo'linma QA.
        $this->actingAs($bolinma, 'sanctum')->postJson('/api/advisor/reports/'.$reportId.'/qa', ['note' => 'Тўлиқ'])
            ->assertOk()->assertJson(['status' => 'qa_checked']);
        $this->assertSame('qa_checked', DB::connection('advisor')->table('task_targets')->where('id', $targetId)->value('status'));

        // Viloyat tasdiq -> nishon closed, yagona nishon bo'lgani uchun topshiriq closed.
        $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/reports/'.$reportId.'/approve')
            ->assertOk()->assertJson(['status' => 'approved']);
        $this->assertSame('closed', DB::connection('advisor')->table('task_targets')->where('id', $targetId)->value('status'));
        $this->assertSame('closed', DB::connection('advisor')->table('tasks')->where('id', $taskId)->value('status'));

        // Audit izi: qa_check + approve.
        $actions = DB::connection('advisor')->table('task_reviews')->where('report_id', $reportId)->pluck('action')->all();
        $this->assertContains('qa_check', $actions);
        $this->assertContains('approve', $actions);
    }

    public function test_viloyat_returns_report_with_comment(): void
    {
        $district = $this->someDistrictId();
        [$taskId, $targetId] = $this->taskForDistrict($district);
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');

        $reportId = $this->actingAs($tuman, 'sanctum')->postJson('/api/advisor/tasks/'.$taskId.'/report', [
            'target_id' => $targetId, 'body' => 'Чала',
        ])->json('id');

        // Qaytarish izohsiz -> 422.
        $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/reports/'.$reportId.'/return', [])
            ->assertStatus(422);

        // Izoh bilan qaytarish -> returned.
        $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/reports/'.$reportId.'/return', ['comment' => 'Далил етарли эмас'])
            ->assertOk()->assertJson(['status' => 'returned']);

        $this->assertSame('returned', DB::connection('advisor')->table('task_reports')->where('id', $reportId)->value('status'));
        $this->assertSame('returned', DB::connection('advisor')->table('task_targets')->where('id', $targetId)->value('status'));

        // Qaytarish sababi tumanga KO'RINADI: showTask hisobot ustidagi reviews
        // (action=return, comment) ni qaytaradi (frontend ReportSubmitModal shuni ko'rsatadi).
        $show = $this->actingAs($tuman, 'sanctum')->getJson('/api/advisor/tasks/'.$taskId)
            ->assertOk()
            ->assertJsonStructure([
                'reports' => [['id', 'status', 'reviews' => [['action', 'comment', 'at']]]],
            ])
            ->json();

        $returned = collect($show['reports'])->firstWhere('id', $reportId);
        $this->assertNotNull($returned);
        $review = collect($returned['reviews'])->firstWhere('action', 'return');
        $this->assertNotNull($review, 'showTask qaytarish izini qaytarishi kerak');
        $this->assertSame('Далил етарли эмас', $review['comment']);

        // targets[].report (oxirgi hisobot) ham qaytarish sababini olib keladi.
        $this->assertSame(
            'Далил етарли эмас',
            collect($show['targets'][0]['report']['reviews'])->firstWhere('action', 'return')['comment'],
        );
    }
}
