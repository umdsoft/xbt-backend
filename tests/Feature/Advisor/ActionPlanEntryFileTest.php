<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor;

use App\Domains\Advisor\Models\ActionPlan;
use App\Domains\Advisor\Models\ActionPlanItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * CHORA-TADBIR jurnal yozuvining TASDIQLOVCHI FAYLLARI: yozuv qo'shishda fayl
 * MAJBURIY; arxiv fayllarni qaytaradi; fayl maxfiy — tuman FAQAT o'z tumaniниki;
 * faylни FAQAT egаси qo'shadi/o'chiради; yozuv o'chirilса — fayllar ham (disk + DB).
 */
class ActionPlanEntryFileTest extends AdvisorTestCase
{
    private function makeItem(string $scope = 'all_districts'): ActionPlanItem
    {
        $plan = ActionPlan::firstOrCreate(['year' => 2099], ['title' => 'Синов режа', 'status' => 'active']);

        return ActionPlanItem::create([
            'plan_id' => $plan->id,
            'section_title' => 'I. Синов бўлими',
            'item_number' => (string) random_int(1, 9999),
            'title' => 'Синов банди',
            'scope' => $scope,
            'sort_order' => 10,
        ]);
    }

    public function test_entry_requires_at_least_one_file(): void
    {
        $district = $this->someDistrictId();
        $item = $this->makeItem();
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);

        $this->actingAs($tuman, 'sanctum')
            ->postJson("/api/advisor/action-plan/items/{$item->id}/entries", ['report' => 'Файлсиз'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['files']);
    }

    public function test_entry_stores_files_and_archive_lists_them(): void
    {
        $district = $this->someDistrictId();
        $item = $this->makeItem();
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);

        $this->actingAs($tuman, 'sanctum')
            ->postJson("/api/advisor/action-plan/items/{$item->id}/entries", [
                'report' => 'Бажарилди',
                'files' => [
                    $this->fakeEntryFile('hisobot.pdf', 'application/pdf'),
                    $this->fakeEntryFile('foto.png', 'image/png'),
                ],
            ])->assertCreated();

        $entry = $this->actingAs($tuman, 'sanctum')
            ->getJson("/api/advisor/action-plan/items/{$item->id}/archive")
            ->assertOk()
            ->assertJsonCount(2, 'entries.0.files')
            ->json('entries.0.files');

        $byName = collect($entry)->keyBy('name');
        $this->assertTrue($byName['foto.png']['is_image']);
        $this->assertFalse($byName['hisobot.pdf']['is_image']);
    }

    public function test_only_owner_adds_or_deletes_entry_file(): void
    {
        $district = $this->someDistrictId();
        $item = $this->makeItem();
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');

        $entryId = $this->actingAs($tuman, 'sanctum')
            ->postJson("/api/advisor/action-plan/items/{$item->id}/entries", ['report' => 'Дастлабки', 'files' => [$this->fakeEntryFile()]])
            ->assertCreated()->json('id');

        // Egаси qo'shimcha fayl qo'shadi.
        $this->actingAs($tuman, 'sanctum')
            ->postJson("/api/advisor/action-plan/entries/{$entryId}/files", ['files' => [$this->fakeEntryFile('qoshimcha.pdf', 'application/pdf')]])
            ->assertCreated();

        // Endi 2 ta fayl.
        $fileId = DB::connection('advisor')->table('action_plan_entry_files')
            ->where('entry_id', $entryId)->orderBy('created_at')->value('id');
        $this->assertSame(2, DB::connection('advisor')->table('action_plan_entry_files')->where('entry_id', $entryId)->count());

        // Viloyat (egаси emas) fayl qo'sha/o'chira OLMAYDI.
        $this->actingAs($viloyat, 'sanctum')
            ->postJson("/api/advisor/action-plan/entries/{$entryId}/files", ['files' => [$this->fakeEntryFile()]])
            ->assertStatus(403);
        $this->actingAs($viloyat, 'sanctum')
            ->deleteJson("/api/advisor/action-plan/entry-files/{$fileId}")
            ->assertStatus(403);

        // Egаси o'chiradi.
        $this->actingAs($tuman, 'sanctum')
            ->deleteJson("/api/advisor/action-plan/entry-files/{$fileId}")
            ->assertOk();
        $this->assertDatabaseMissing('action_plan_entry_files', ['id' => $fileId], 'advisor');
    }

    public function test_tuman_cannot_download_other_district_file(): void
    {
        $district = $this->someDistrictId();
        $other = $this->anotherDistrictId($district);
        $item = $this->makeItem();
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);
        $otherTuman = $this->makeAdvisor('advisor_tuman', 'tuman', $other);
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');

        $entryId = $this->actingAs($tuman, 'sanctum')
            ->postJson("/api/advisor/action-plan/items/{$item->id}/entries", ['report' => 'Далил', 'files' => [$this->fakeEntryFile()]])
            ->assertCreated()->json('id');

        $fileId = DB::connection('advisor')->table('action_plan_entry_files')->where('entry_id', $entryId)->value('id');

        // Egаси tuman — ochadi.
        $this->actingAs($tuman, 'sanctum')->get("/api/advisor/action-plan/entry-files/{$fileId}")->assertOk();
        // Viloyat — hammasini ochadi.
        $this->actingAs($viloyat, 'sanctum')->get("/api/advisor/action-plan/entry-files/{$fileId}")->assertOk();
        // Boshqa tuman — YO'Q (IDOR himoyasi, 404).
        $this->actingAs($otherTuman, 'sanctum')->get("/api/advisor/action-plan/entry-files/{$fileId}")->assertNotFound();
    }

    public function test_deleting_entry_removes_its_files(): void
    {
        $district = $this->someDistrictId();
        $item = $this->makeItem();
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);

        $entryId = $this->actingAs($tuman, 'sanctum')
            ->postJson("/api/advisor/action-plan/items/{$item->id}/entries", ['report' => 'O\'chiriladi', 'files' => [$this->fakeEntryFile()]])
            ->assertCreated()->json('id');

        $path = DB::connection('advisor')->table('action_plan_entry_files')->where('entry_id', $entryId)->value('path');
        Storage::disk('local')->assertExists($path);

        $this->actingAs($tuman, 'sanctum')->deleteJson("/api/advisor/action-plan/entries/{$entryId}")->assertOk();

        $this->assertDatabaseMissing('action_plan_entry_files', ['entry_id' => $entryId], 'advisor');
        Storage::disk('local')->assertMissing($path);
    }
}
