<?php

declare(strict_types=1);

namespace Tests\Feature\Qurilish;

use App\Domains\Qurilish\Models\ObjectAuditLog;
use App\Domains\Qurilish\Models\ObjectDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Hujjat boshqaruvi: yuklash, versiyalash, RBAC, oq ro'yxat, yuklab olish.
 */
class QurilishDocumentTest extends QurilishObjectTestCase
{
    public function test_customer_uploads_document_and_it_is_listed(): void
    {
        [$object, $user] = $this->objectWithCustomer();

        $this->actingAs($user, 'sanctum')
            ->post($this->url($object), [
                'file' => UploadedFile::fake()->create('shartnoma.pdf', 120, 'application/pdf'),
                'category' => 'shartnoma',
            ])->assertStatus(201)
            ->assertJsonPath('data.category', 'shartnoma')
            ->assertJsonPath('data.version', 1);

        $res = $this->actingAs($user, 'sanctum')->getJson($this->url($object))->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame('shartnoma.pdf', $res->json('data.0.original_name'));
    }

    public function test_reupload_of_same_name_creates_new_version(): void
    {
        [$object, $user] = $this->objectWithCustomer();

        $this->actingAs($user, 'sanctum')->post($this->url($object), [
            'file' => UploadedFile::fake()->create('shartnoma.pdf', 10, 'application/pdf'),
            'category' => 'shartnoma',
        ])->assertStatus(201);

        // Eski versiya O'CHIRILMAYDI — nazorat uchun tarix saqlanadi.
        $this->actingAs($user, 'sanctum')->post($this->url($object), [
            'file' => UploadedFile::fake()->create('shartnoma.pdf', 20, 'application/pdf'),
            'category' => 'shartnoma',
        ])->assertStatus(201)->assertJsonPath('data.version', 2);

        $this->assertSame(2, ObjectDocument::query()->where('object_id', $object->id)->count());
    }

    public function test_disallowed_extension_is_rejected(): void
    {
        [$object, $user] = $this->objectWithCustomer();

        $this->actingAs($user, 'sanctum')
            ->post($this->url($object), [
                'file' => UploadedFile::fake()->create('zararli.exe', 10, 'application/octet-stream'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, ObjectDocument::query()->where('object_id', $object->id)->count());
    }

    public function test_oversized_file_is_rejected(): void
    {
        [$object, $user] = $this->objectWithCustomer();

        $this->actingAs($user, 'sanctum')
            ->post($this->url($object), [
                'file' => UploadedFile::fake()->create('katta.pdf', 30 * 1024, 'application/pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_invalid_category_is_rejected(): void
    {
        [$object, $user] = $this->objectWithCustomer();

        $this->actingAs($user, 'sanctum')
            ->post($this->url($object), [
                'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
                'category' => 'allaqanday',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category');
    }

    public function test_viewer_cannot_upload_but_can_download(): void
    {
        [$object, $owner] = $this->objectWithCustomer();

        $id = $this->actingAs($owner, 'sanctum')->post($this->url($object), [
            'file' => UploadedFile::fake()->create('hisobot.pdf', 10, 'application/pdf'),
        ])->assertStatus(201)->json('data.id');

        $viewer = $this->makeUser('qurilish_prokuratura');

        $this->actingAs($viewer, 'sanctum')->post($this->url($object), [
            'file' => UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'),
        ])->assertStatus(403);

        $this->actingAs($viewer, 'sanctum')
            ->get($this->url($object).'/'.$id)->assertOk();
    }

    public function test_foreign_organization_cannot_download(): void
    {
        [$object, $owner] = $this->objectWithCustomer();

        $id = $this->actingAs($owner, 'sanctum')->post($this->url($object), [
            'file' => UploadedFile::fake()->create('maxfiy.pdf', 10, 'application/pdf'),
        ])->assertStatus(201)->json('data.id');

        $otherOrg = $this->makeOrganization('Бегона', ['is_customer' => true]);
        $stranger = $this->makeUser('qurilish_buyurtmachi', $otherOrg);

        // Havolani bilsa ham 404 — obyekt uning doirasida emas.
        $this->actingAs($stranger, 'sanctum')
            ->get($this->url($object).'/'.$id)->assertStatus(404);
    }

    public function test_delete_removes_record_and_file(): void
    {
        [$object, $user] = $this->objectWithCustomer();

        $id = $this->actingAs($user, 'sanctum')->post($this->url($object), [
            'file' => UploadedFile::fake()->create('ochiriladi.pdf', 10, 'application/pdf'),
        ])->assertStatus(201)->json('data.id');

        $path = (string) ObjectDocument::query()->findOrFail($id)->stored_path;
        $this->assertTrue(Storage::disk('local')->exists($path));

        $this->actingAs($user, 'sanctum')
            ->deleteJson($this->url($object).'/'.$id)->assertOk();

        $this->assertFalse(Storage::disk('local')->exists($path));
        $this->assertNull(ObjectDocument::query()->find($id));
    }

    public function test_upload_and_delete_are_audited(): void
    {
        [$object, $user] = $this->objectWithCustomer();

        $id = $this->actingAs($user, 'sanctum')->post($this->url($object), [
            'file' => UploadedFile::fake()->create('audit.pdf', 10, 'application/pdf'),
            'category' => 'dalolatnoma',
        ])->assertStatus(201)->json('data.id');

        $this->actingAs($user, 'sanctum')->deleteJson($this->url($object).'/'.$id)->assertOk();

        $actions = ObjectAuditLog::query()->where('object_id', $object->id)->pluck('action')->all();

        $this->assertContains('document_upload', $actions);
        $this->assertContains('document_delete', $actions);
    }

    // ---------- yordamchilar ----------

    /** @return array{0: \App\Domains\Qurilish\Models\ConstructionObject, 1: \App\Models\User} */
    private function objectWithCustomer(): array
    {
        $org = $this->makeOrganization('Буюртмачи', ['is_customer' => true]);

        return [
            $this->makeObject(['name' => $this->tag('DOC'), 'customer_org_id' => $org]),
            $this->makeUser('qurilish_buyurtmachi', $org),
        ];
    }

    private function url(\App\Domains\Qurilish\Models\ConstructionObject $object): string
    {
        return "/api/qurilish/objects/{$object->id}/documents";
    }
}
