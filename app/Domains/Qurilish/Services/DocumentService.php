<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Services;

use App\Domains\Qurilish\Models\ConstructionObject;
use App\Domains\Qurilish\Models\ObjectDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Obyekt hujjatlari — versiyalangan saqlash.
 *
 * Bir xil `(obyekt, kategoriya, fayl nomi)` uchun yangi yuklash eskisini
 * O'CHIRMAYDI, balki `version + 1` bilan yangi qator yaratadi: nazorat
 * organiga «shartnomaning qaysi tahriri qachon yuklangan» ko'rinishi kerak.
 *
 * Bir xil `sha256` uchun disk nusxasi qayta ishlatiladi — bir faylni ikki
 * kategoriyaga biriktirish diskda joy egallamaydi.
 */
class DocumentService
{
    private const DISK = 'local';

    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $meta category, stage_code */
    public function upload(ConstructionObject $object, UploadedFile $file, array $meta, User $user): ObjectDocument
    {
        $this->validate($file, $meta);

        $hash = (string) hash_file('sha256', $file->getRealPath());
        $extension = strtolower($file->getClientOriginalExtension());
        $original = $file->getClientOriginalName();

        // Bir xil mazmun allaqachon shu obyektda bo'lsa — diskka qayta yozmaymiz.
        $twin = ObjectDocument::query()
            ->where('object_id', $object->id)->where('sha256', $hash)->first();

        $path = $twin?->stored_path;
        if ($path === null || ! Storage::disk(self::DISK)->exists($path)) {
            $path = $file->store("qurilish/objects/{$object->id}", self::DISK);
        }

        $version = 1 + (int) ObjectDocument::query()
            ->where('object_id', $object->id)
            ->where('original_name', $original)
            ->max('version');

        $document = ObjectDocument::query()->create([
            'object_id' => $object->id,
            'stage_code' => $meta['stage_code'] ?? null,
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

        $this->audit->log($object, $user, 'document_upload', $document->category, null, $original);

        return $document;
    }

    public function delete(ObjectDocument $document, ConstructionObject $object, User $user): void
    {
        $this->audit->log($object, $user, 'document_delete', $document->category, $document->original_name, null);

        // Disk nusxasi boshqa versiyalar bilan baham ko'rilgan bo'lishi mumkin —
        // faqat oxirgi ishlatuvchi o'chirilganda faylni tashlaymiz.
        $shared = ObjectDocument::query()
            ->where('stored_path', $document->stored_path)
            ->where('id', '!=', $document->id)
            ->exists();

        $document->delete();

        if (! $shared) {
            Storage::disk(self::DISK)->delete($document->stored_path);
        }
    }

    public function disk(): string
    {
        return self::DISK;
    }

    /** @param array<string, mixed> $meta */
    private function validate(UploadedFile $file, array $meta): void
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, ObjectDocument::ALLOWED_EXTENSIONS, true)) {
            throw ValidationException::withMessages([
                'file' => 'Бу турдаги файлга рухсат йўқ. Рухсат этилган: '
                    .implode(', ', ObjectDocument::ALLOWED_EXTENSIONS),
            ]);
        }

        if ($file->getSize() > ObjectDocument::MAX_SIZE) {
            throw ValidationException::withMessages([
                'file' => 'Файл ҳажми 25 МБ дан ошмаслиги керак.',
            ]);
        }

        $category = $meta['category'] ?? 'boshqa';
        if (! in_array($category, ObjectDocument::CATEGORIES, true)) {
            throw ValidationException::withMessages(['category' => 'Нотўғри ҳужжат тоифаси.']);
        }
    }
}
