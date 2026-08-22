<?php

declare(strict_types=1);

namespace Tests\Feature\Yoshlar;

use App\Domains\Yoshlar\Models\Document;
use App\Domains\Yoshlar\Models\Organization;
use App\Domains\Yoshlar\Models\Task;
use App\Domains\Yoshlar\Models\Youth;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * TZ 5.7 — hujjat/media.
 *
 * Eng muhim tekshiruvlar: fayl DOIRADAN o'tadi (havolani bilish yetarli
 * emas), xavfli kengaytmalar rad etiladi, versiya oshadi va disk yo'li
 * javobda ko'rinmaydi.
 */
class YoshlarDocumentTest extends YoshlarTestCase
{
    public function test_upload_and_list(): void
    {
        $task = $this->makeTask();
        $user = $this->makeUser('yoshlar_admin');

        $id = $this->actingAs($user, 'sanctum')
            ->post("/api/yoshlar/documents/task/{$task->id}", [
                'file' => UploadedFile::fake()->create('shartnoma.pdf', 100, 'application/pdf'),
                'category' => 'shartnoma',
            ])
            ->assertCreated()
            ->assertJsonPath('data.original_name', 'shartnoma.pdf')
            ->assertJsonPath('data.version', 1)
            ->json('data.id');

        $list = $this->actingAs($user, 'sanctum')
            ->getJson("/api/yoshlar/documents/task/{$task->id}")
            ->assertOk()->json('data.*.id');

        $this->assertContains($id, $list);

        $this->cleanup($id);
    }

    public function test_stored_path_is_never_exposed(): void
    {
        $task = $this->makeTask();

        $body = $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->post("/api/yoshlar/documents/task/{$task->id}", [
                'file' => UploadedFile::fake()->create('hisobot.pdf', 10, 'application/pdf'),
            ])
            ->assertCreated()->getContent();

        // Serverdagi ichki joylashuv mijozga chiqmasligi kerak.
        $this->assertStringNotContainsString('stored_path', (string) $body);
        $this->assertStringNotContainsString('yoshlar/task/', (string) $body);

        $this->cleanupByTask($task->id);
    }

    public function test_dangerous_extension_is_rejected(): void
    {
        $task = $this->makeTask();

        foreach (['virus.exe', 'script.svg', 'shell.php'] as $name) {
            $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
                ->post("/api/yoshlar/documents/task/{$task->id}", [
                    'file' => UploadedFile::fake()->create($name, 10),
                ])
                ->assertStatus(422);
        }
    }

    public function test_same_name_creates_new_version(): void
    {
        $task = $this->makeTask();
        $user = $this->makeUser('yoshlar_admin');

        $this->actingAs($user, 'sanctum')->post("/api/yoshlar/documents/task/{$task->id}", [
            'file' => UploadedFile::fake()->create('akt.pdf', 10, 'application/pdf'),
        ])->assertCreated()->assertJsonPath('data.version', 1);

        // Ikkinchi yuklash eskisini O'CHIRMAYDI — versiya oshadi.
        $this->actingAs($user, 'sanctum')->post("/api/yoshlar/documents/task/{$task->id}", [
            'file' => UploadedFile::fake()->create('akt.pdf', 20, 'application/pdf'),
        ])->assertCreated()->assertJsonPath('data.version', 2);

        $this->assertSame(2, Document::query()->where('entity_id', $task->id)->count());

        $this->cleanupByTask($task->id);
    }

    public function test_document_from_other_district_is_blocked(): void
    {
        $own = $this->someDistrictId();
        $other = $this->otherDistrictId($own);

        $foreignTask = $this->makeTask(['district_id' => $other]);

        $org = $this->makeOrganization(Organization::TYPE_TUMAN_YOSHLAR, ['district_id' => $own]);
        $user = $this->makeUser('yoshlar_bolim', $org->id);

        // Ro'yxat ham, yuklash ham — doiradan tashqarida 403.
        $this->actingAs($user, 'sanctum')
            ->getJson("/api/yoshlar/documents/task/{$foreignTask->id}")
            ->assertStatus(403);

        $this->actingAs($user, 'sanctum')
            ->post("/api/yoshlar/documents/task/{$foreignTask->id}", [
                'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
            ])
            ->assertStatus(403);
    }

    public function test_download_checks_scope_not_just_link(): void
    {
        $own = $this->someDistrictId();
        $other = $this->otherDistrictId($own);

        $foreignTask = $this->makeTask(['district_id' => $other]);

        $docId = $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->post("/api/yoshlar/documents/task/{$foreignTask->id}", [
                'file' => UploadedFile::fake()->create('maxfiy.pdf', 10, 'application/pdf'),
            ])->assertCreated()->json('data.id');

        $org = $this->makeOrganization(Organization::TYPE_TUMAN_YOSHLAR, ['district_id' => $own]);

        // Havolani bilish yetarli emas — doira tekshiriladi.
        $this->actingAs($this->makeUser('yoshlar_bolim', $org->id), 'sanctum')
            ->get("/api/yoshlar/documents/{$docId}/download")
            ->assertStatus(403);

        $this->cleanup($docId);
    }

    public function test_viewer_role_cannot_upload(): void
    {
        $task = $this->makeTask();

        // Hokim o'rinbosari — kuzatuvchi, hujjat yuklamaydi.
        $this->actingAs($this->makeUser('yoshlar_hokim_orinbosari'), 'sanctum')
            ->post("/api/yoshlar/documents/task/{$task->id}", [
                'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
            ])
            ->assertStatus(403);
    }

    public function test_youth_document_is_attachable(): void
    {
        $districtId = $this->someDistrictId();
        $youth = Youth::query()->create([
            'last_name' => 'Hujjatli', 'first_name' => 'Yosh',
            'birth_date' => now()->subYears(20)->toDateString(), 'gender' => 'erkak',
            'district_id' => $districtId, 'mahalla_id' => $this->someMahallaId($districtId),
        ]);

        $id = $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->post("/api/yoshlar/documents/youth/{$youth->id}", [
                'file' => UploadedFile::fake()->image('foto.jpg'),
                'category' => 'foto',
            ])
            ->assertCreated()->json('data.id');

        $this->cleanup($id);
    }

    /** @param array<string, mixed> $attrs */
    private function makeTask(array $attrs = []): Task
    {
        $districtId = $attrs['district_id'] ?? $this->someDistrictId();
        $org = $this->makeOrganization(Organization::TYPE_TUMAN_SEKTOR, ['district_id' => $districtId]);

        return Task::query()->create(array_merge([
            'title' => 'TEST-'.Str::random(6),
            'assigned_org_id' => $org->id,
            'district_id' => $districtId,
            'deadline' => now()->addDays(10)->toDateString(),
            'priority' => 'orta',
            'status' => 'belgilandi',
        ], $attrs));
    }

    /** Diskdagi sinov fayllarini tozalaydi (DatabaseTransactions faylni qaytarmaydi). */
    private function cleanup(?string $documentId): void
    {
        if ($documentId === null) {
            return;
        }

        $doc = Document::query()->find($documentId);

        if ($doc !== null) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($doc->stored_path);
        }
    }

    private function cleanupByTask(string $taskId): void
    {
        foreach (Document::query()->where('entity_id', $taskId)->get() as $doc) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($doc->stored_path);
        }
    }
}
