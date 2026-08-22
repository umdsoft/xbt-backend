<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Services;

use App\Domains\Yoshlar\Models\Document;
use App\Domains\Yoshlar\Models\EmploymentCase;
use App\Domains\Yoshlar\Models\Patronage;
use App\Domains\Yoshlar\Models\Task;
use App\Domains\Yoshlar\Models\Youth;
use App\Domains\Yoshlar\Models\YouthCase;
use App\Domains\Yoshlar\Support\YoshlarScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Hujjat/media (TZ 5.7) — barcha modullar uchun yagona servis.
 *
 * XAVFSIZLIK: fayllar `storage/app/yoshlar/...` da yotadi — PUBLIC EMAS.
 * Yuklab olish faqat API orqali, DOIRA tekshirilgandan keyin: aks holda
 * havolani bilgan har kim boshqa tumanning hujjatini ochib olardi.
 *
 * VERSIYALASH: bir xil nom qayta yuklansa eskisi o'chmaydi, `version + 1`
 * bo'ladi. Bir xil `sha256` uchun disk nusxasi qayta ishlatiladi.
 */
class DocumentService
{
    private const DISK = 'local';

    public function __construct(
        private readonly YoshlarScope $scope,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Obyektni topadi va foydalanuvchi unga tega olishini tekshiradi.
     *
     * Bitta joyda: har kontroller o'zicha tekshirsa, bittasida unutilishi
     * mumkin va o'sha teshik butun hujjat tizimini ochib yuborardi.
     *
     * @return array{district_id: ?string}
     */
    public function assertAccess(User $user, string $entityType, string $entityId): array
    {
        $districtId = match ($entityType) {
            'task' => Task::query()->whereKey($entityId)->value('district_id'),
            'case' => YouthCase::query()->whereKey($entityId)->value('district_id'),
            'employment' => EmploymentCase::query()->whereKey($entityId)->value('district_id'),
            'patronage' => Patronage::query()->whereKey($entityId)->value('district_id'),
            'youth' => Youth::query()->whereKey($entityId)->value('district_id'),
            // Protokol viloyat darajasidagi hujjat — tumanga bogʻlanmagan.
            'protocol' => null,
            default => throw ValidationException::withMessages(['entity_type' => 'Notoʻgʻri obyekt turi.']),
        };

        if ($entityType !== 'protocol') {
            abort_if($districtId === null, 404, 'Obyekt topilmadi.');
            abort_unless($this->scope->canTouchDistrict($user, $districtId), 403, 'Bu obyekt sizning doirangizda emas.');
        }

        return ['district_id' => $districtId];
    }

    /** @param array<string, mixed> $meta */
    public function upload(User $user, string $entityType, string $entityId, UploadedFile $file, array $meta): Document
    {
        ['district_id' => $districtId] = $this->assertAccess($user, $entityType, $entityId);

        $this->validate($file, $meta);

        $hash = (string) hash_file('sha256', $file->getRealPath());
        $original = $file->getClientOriginalName();

        // Bir xil mazmun shu obyektda bor bo'lsa — diskka qayta yozmaymiz.
        $twin = Document::query()
            ->where('entity_type', $entityType)->where('entity_id', $entityId)
            ->where('sha256', $hash)->first();

        $path = $twin?->stored_path;

        if ($path === null || ! Storage::disk(self::DISK)->exists($path)) {
            $path = $file->store("yoshlar/{$entityType}/{$entityId}", self::DISK);
        }

        $version = 1 + (int) Document::query()
            ->where('entity_type', $entityType)->where('entity_id', $entityId)
            ->where('original_name', $original)
            ->max('version');

        $document = Document::query()->create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'district_id' => $districtId,
            'category' => $meta['category'] ?? 'boshqa',
            'original_name' => $original,
            'stored_path' => $path,
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'sha256' => $hash,
            'version' => $version,
            'uploaded_by' => $user->id,
            'uploaded_at' => now(),
        ]);

        $this->audit->log($user, 'document.upload', $entityType, $entityId, [
            'name' => $original,
            'category' => $document->category,
        ]);

        return $document;
    }

    /** @return Collection<int, Document> */
    public function list(User $user, string $entityType, string $entityId): Collection
    {
        $this->assertAccess($user, $entityType, $entityId);

        return Document::query()
            ->where('entity_type', $entityType)->where('entity_id', $entityId)
            ->orderByDesc('uploaded_at')
            ->get();
    }

    /** @return array{path: string, name: string} */
    public function download(User $user, Document $document): array
    {
        $this->assertAccess($user, $document->entity_type, $document->entity_id);

        abort_unless(
            Storage::disk(self::DISK)->exists($document->stored_path),
            404,
            'Fayl serverda topilmadi.',
        );

        $this->audit->log($user, 'document.download', $document->entity_type, $document->entity_id, [
            'name' => $document->original_name,
        ]);

        return [
            'path' => Storage::disk(self::DISK)->path($document->stored_path),
            'name' => $document->original_name,
        ];
    }

    public function delete(User $user, Document $document): void
    {
        $this->assertAccess($user, $document->entity_type, $document->entity_id);

        $this->audit->log($user, 'document.delete', $document->entity_type, $document->entity_id, [
            'name' => $document->original_name,
        ]);

        // Disk nusxasi boshqa versiyalar bilan baham ko'rilishi mumkin —
        // faqat oxirgi ishlatuvchi o'chirilganda faylni tashlaymiz.
        $shared = Document::query()
            ->where('stored_path', $document->stored_path)
            ->where('id', '!=', $document->id)
            ->exists();

        $path = $document->stored_path;
        $document->delete();

        if (! $shared) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /** @param array<string, mixed> $meta */
    private function validate(UploadedFile $file, array $meta): void
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, Document::ALLOWED_EXTENSIONS, true)) {
            throw ValidationException::withMessages([
                'file' => 'Bu turdagi faylga ruxsat yoʻq. Ruxsat etilgan: '
                    .implode(', ', Document::ALLOWED_EXTENSIONS),
            ]);
        }

        if ($file->getSize() > Document::MAX_SIZE) {
            throw ValidationException::withMessages([
                'file' => 'Fayl hajmi 25 MB dan oshmasligi kerak.',
            ]);
        }

        $category = $meta['category'] ?? 'boshqa';

        if (! in_array($category, Document::CATEGORIES, true)) {
            throw ValidationException::withMessages(['category' => 'Notoʻgʻri hujjat toifasi.']);
        }
    }
}
