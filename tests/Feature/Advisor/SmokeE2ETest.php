<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * UCHDAN-UCHGACHA SMOKE (cross-module): butun advisor oqimini bitta testda
 * qamrab, modullar orasidagi integratsiyani tasdiqlaydi:
 *
 *   viloyat topshiriq beradi -> tuman ro'yxatda ko'radi -> hisobot+dalil yuboradi
 *   -> bo'linma QA -> viloyat tasdiq -> topshiriq yopiladi (arxiv) -> arxiv qidiruv
 *   + xlsx eksport -> tuman KPI kiritadi (ijro%) -> viloyat tasdiqlaydi -> derive
 *   (avto KPI) -> reyting hisoblash -> reyting jadvali -> dashboard (viloyat+tuman)
 *   -> svod xlsx eksport.
 *
 * Bitta ham modul boshqasidan uzilib qolmasligini (route/shartnoma/rol) isbotlaydi.
 */
class SmokeE2ETest extends AdvisorTestCase
{
    public function test_full_lifecycle_across_all_modules(): void
    {
        Storage::fake('local');

        $period = '2099-Q2';
        $district = $this->someDistrictId();
        $categoryId = $this->aCategoryId();

        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');
        $bolinma = $this->makeAdvisor('advisor_bolinma', 'bolinma');
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);

        // 1) me — kontekst/rol/ruxsatlar (SPA nav uchun).
        $me = $this->actingAs($tuman, 'sanctum')->getJson('/api/advisor/me')
            ->assertOk()
            ->assertJsonStructure(['advisor' => ['id', 'name', 'level', 'district' => ['id', 'name']], 'role', 'permissions'])
            ->json();
        $this->assertSame('tuman', $me['advisor']['level']);
        $this->assertSame($district, $me['advisor']['district']['id']);

        // 2) VILOYAT topshiriq beradi (kategoriya + muddat + shu tumanga nishon).
        $taskId = $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/tasks', [
            'source' => 'Президент девони',
            'title' => 'Смоук топшириқ',
            'description' => 'Тавсиф',
            'expected_result' => 'Кутилаётган натижа',
            'category_id' => $categoryId,
            'priority' => 'high',
            'deadline' => now()->addDays(10)->toDateString(),
            'district_ids' => [$district],
            'tags' => ['смоук', 'интеграция'],
        ])->assertCreated()->json('id');
        $this->assertNotNull($taskId);

        // 3) TUMAN o'z ro'yxatida topshiriqni ko'radi (qamrov integratsiyasi).
        $list = $this->actingAs($tuman, 'sanctum')->getJson('/api/advisor/tasks')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'title', 'status', 'category', 'targets']], 'meta'])
            ->json();
        $this->assertSame($taskId, collect($list['data'])->firstWhere('id', $taskId)['id'] ?? null);

        $targetId = (string) DB::connection('advisor')->table('task_targets')
            ->where('task_id', $taskId)->where('district_id', $district)->value('id');

        // 4) TUMAN hisobot + 3 xil dalil (fayl/havola/GPS) yuboradi.
        $reportId = $this->actingAs($tuman, 'sanctum')->postJson('/api/advisor/tasks/'.$taskId.'/report', [
            'target_id' => $targetId,
            'body' => 'Иш бажарилди',
            'files' => [UploadedFile::fake()->image('dalil.jpg')],
            'links' => ['https://example.uz/hujjat'],
            'gps' => ['lat' => 41.55, 'lng' => 60.63, 'accuracy' => 10.0],
        ])->assertCreated()->assertJson(['status' => 'pending'])->json('id');

        // 5) BO'LINMA QA -> nishon qa_checked.
        $this->actingAs($bolinma, 'sanctum')->postJson('/api/advisor/reports/'.$reportId.'/qa', ['note' => 'Тўлиқ'])
            ->assertOk()->assertJson(['status' => 'qa_checked']);

        // 6) VILOYAT tasdiq -> yagona nishon yopilгани uchun topshiriq closed.
        $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/reports/'.$reportId.'/approve')
            ->assertOk()->assertJson(['status' => 'approved']);
        $this->assertSame('closed', DB::connection('advisor')->table('tasks')->where('id', $taskId)->value('status'));

        // 7) ARXIV qidiruv (bilim bazasi) — yopilган topshiriq q bo'yicha topiladi.
        $archive = $this->actingAs($viloyat, 'sanctum')->getJson('/api/advisor/archive?q=Смоук')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'title', 'districts', 'tags']], 'meta'])
            ->json();
        $this->assertNotNull(collect($archive['data'])->firstWhere('id', $taskId));

        // 8) ARXIV eksport (xlsx — PK ZIP imzosi).
        $arxivXlsx = $this->actingAs($viloyat, 'sanctum')->get('/api/advisor/archive/export?q=Смоук');
        $arxivXlsx->assertOk();
        $arxivXlsx->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringStartsWith('PK', (string) $arxivXlsx->getContent());

        // 9) TUMAN manual KPI kiritadi (target bilan -> ijro% = 80).
        $kpiId = $this->kpiIdByCode('t_x9');
        $this->setKpiTarget($kpiId, $district, $period, 100.0);
        $entryId = $this->actingAs($tuman, 'sanctum')->postJson('/api/advisor/kpi/entries', [
            'kpi_id' => $kpiId, 'district_id' => $district, 'period' => $period, 'value' => 80, 'note' => 'Сўров',
        ])->assertCreated()->assertJson(['status' => 'submitted'])->json('id');

        $entryRow = collect($this->actingAs($tuman, 'sanctum')->getJson('/api/advisor/kpi/entries?period='.$period)->json())
            ->firstWhere('id', $entryId);
        $this->assertSame(80.0, (float) $entryRow['fulfillment']);

        // 10) VILOYAT KPI yozuvini tasdiqlaydi.
        $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/kpi/entries/'.$entryId.'/approve')
            ->assertOk()->assertJson(['status' => 'approved']);

        // 11) VILOYAT reytingni hisoblaydi.
        $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/rankings/compute', ['period' => $period])
            ->assertOk();

        // 13) Reyting jadvali — bizning tuman ballга ega.
        $rankings = $this->actingAs($viloyat, 'sanctum')->getJson('/api/advisor/rankings?period='.$period)
            ->assertOk()
            ->assertJsonStructure([['district' => ['id', 'name'], 'score', 'rank']])
            ->json();
        $this->assertNotNull(collect($rankings)->firstWhere('district.id', $district));

        // 14) KPI xulosa (viloyat/bo'linma) — tuman kesimi.
        $this->actingAs($viloyat, 'sanctum')->getJson('/api/advisor/kpi/summary?period='.$period)
            ->assertOk()
            ->assertJsonStructure([['district' => ['id', 'name'], 'avg_fulfillment', 'entered', 'approved']]);

        // 15) DASHBOARD (viloyat) — kartalar + reyting + so'nggi faoliyat.
        $dash = $this->actingAs($viloyat, 'sanctum')->getJson('/api/advisor/dashboard')
            ->assertOk()
            ->assertJsonStructure(['cards' => [['key', 'label', 'value']], 'alerts', 'ranking', 'recent_activity'])
            ->json();
        $this->assertNotEmpty($dash['cards']);

        // 16) DASHBOARD (tuman) — o'z kesimi.
        $this->actingAs($tuman, 'sanctum')->getJson('/api/advisor/dashboard')
            ->assertOk()
            ->assertJsonStructure(['cards' => [['key', 'label', 'value']], 'alerts']);

        // 17) OVERSIGHT (faoliyat nazorati) — viloyat barcha maslahatchi kesimini ko'radi.
        $this->actingAs($viloyat, 'sanctum')->getJson('/api/advisor/oversight')
            ->assertOk()
            ->assertJsonStructure([['advisor' => ['id', 'name'], 'level', 'open_tasks', 'overdue', 'reports_pending']]);

        // 18) ACTIVITY lentasi.
        $this->actingAs($viloyat, 'sanctum')->getJson('/api/advisor/activity')
            ->assertOk();

        // 19) SVOD eksport (yuqori idora) — xlsx (PK).
        $svod = $this->actingAs($viloyat, 'sanctum')->get('/api/advisor/export/svod?period='.$period);
        $svod->assertOk();
        $svod->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringStartsWith('PK', (string) $svod->getContent());
    }

    public function test_tuman_cannot_reach_other_district_task(): void
    {
        // Cross-module IDOR: boshqa tuman topshirig'ига kira olmasin (404/qamrov).
        $mine = $this->someDistrictId();
        $other = $this->anotherDistrictId($mine);

        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');
        $tumanMine = $this->makeAdvisor('advisor_tuman', 'tuman', $mine);

        $taskId = $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/tasks', [
            'title' => 'Бошқа туман топшириғи',
            'district_ids' => [$other],
        ])->assertCreated()->json('id');

        // Mening tumanимда nishon yo'q -> ko'rinmaydi (404).
        $this->actingAs($tumanMine, 'sanctum')->getJson('/api/advisor/tasks/'.$taskId)
            ->assertNotFound();
    }
}
