<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Services;

use App\Domains\Qurilish\Models\ConstructionObject;
use App\Domains\Qurilish\Models\ObjectMedia;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Bosqich dalillari — surat va video.
 *
 * NEGA HUJJATDAN ALOHIDA: hujjat huquqiy, media — dalil. Hujjat versiyalanadi
 * («shartnomaning 3-tahriri»), media esa yig'iladi («shu haftada 12 ta surat»).
 * Media uchun eng muhim maydon — `taken_at`: kechikib yuklangan surat ham
 * dalil bo'lib qolaveradi, agar qachon olingani to'g'ri bo'lsa.
 *
 * Video KO'CHIRILMAYDI va qayta kodlanmaydi: serverda transkoder yo'q va
 * uni qo'shish alohida infratuzilma masalasi. Shuning uchun hajm chegarasi
 * qattiq va faqat brauzer o'zi o'ynatadigan formatlar qabul qilinadi.
 */
class MediaService
{
    private const DISK = 'local';

    /** Surat: 15 MB. Zamonaviy telefon surati shunga bemalol sig'adi. */
    private const MAX_PHOTO = 15 * 1024 * 1024;

    /**
     * Video: 200 MB. Bu ~2 daqiqalik telefon videosi. Kattarog'i kerak
     * bo'lsa — bu transkoder masalasi, chegarani ko'tarish emas.
     */
    private const MAX_VIDEO = 200 * 1024 * 1024;

    private const PHOTO_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/heic'];

    /** Brauzer plaginsiz o'ynatadigan formatlar. */
    private const VIDEO_MIME = ['video/mp4', 'video/webm', 'video/quicktime'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $meta  stage_code, weekly_report_id, title, taken_at
     */
    public function upload(ConstructionObject $object, UploadedFile $file, array $meta, User $user): ObjectMedia
    {
        $kind = $this->classify($file);
        $this->assertSize($file, $kind);

        $stageCode = $meta['stage_code'] ?? null;
        if ($stageCode !== null && ! in_array($stageCode, ConstructionObject::STAGES, true)) {
            throw ValidationException::withMessages(['stage_code' => 'Бундай босқич йўқ.']);
        }

        $hash = (string) hash_file('sha256', $file->getRealPath());

        // Bir xil surat ikki marta yuklansa diskda ikki nusxa yotmasin.
        $twin = ObjectMedia::query()
            ->where('object_id', $object->id)->where('sha256', $hash)->first();

        $path = $twin?->stored_path;
        if ($path === null || ! Storage::disk(self::DISK)->exists($path)) {
            $path = $file->store("qurilish/media/{$object->id}", self::DISK);
        }

        $size = $this->imageSize($file, $kind);

        $media = ObjectMedia::query()->create([
            'object_id' => $object->id,
            'stage_code' => $stageCode,
            'weekly_report_id' => $meta['weekly_report_id'] ?? null,
            'kind' => $kind,
            'title' => $meta['title'] ?? null,
            'stored_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'sha256' => $hash,
            'width' => $size['width'],
            'height' => $size['height'],
            // Sana ko'rsatilmasa BUGUN emas, NULL qoldiriladi: taxmin qilingan
            // sana dalilni soxtalashtiradi, bo'sh sana esa halol.
            'taken_at' => $meta['taken_at'] ?? null,
            'uploaded_by' => $user->id,
        ]);

        $this->audit->log($object, $user, 'media_upload', $stageCode, null, $media->original_name);

        return $media;
    }

    public function delete(ObjectMedia $media, ConstructionObject $object, User $user): void
    {
        $this->audit->log($object, $user, 'media_delete', $media->stage_code, $media->original_name, null);

        $shared = ObjectMedia::query()
            ->where('stored_path', $media->stored_path)
            ->where('id', '!=', $media->id)
            ->exists();

        $media->delete();

        if (! $shared) {
            Storage::disk(self::DISK)->delete($media->stored_path);
        }
    }

    /**
     * Bosqich uchun asosiy surat — kartochkada shu ko'rinadi.
     * Bir bosqichda faqat bitta asosiy surat bo'ladi.
     */
    public function setCover(ObjectMedia $media): ObjectMedia
    {
        if ($media->kind !== 'photo') {
            throw ValidationException::withMessages([
                'kind' => 'Асосий тасвир сифатида фақат сурат белгиланади.',
            ]);
        }

        ObjectMedia::query()
            ->where('object_id', $media->object_id)
            ->where('stage_code', $media->stage_code)
            ->where('id', '!=', $media->id)
            ->update(['is_cover' => false]);

        $media->update(['is_cover' => true]);

        return $media->refresh();
    }

    public function disk(): string
    {
        return self::DISK;
    }

    private function classify(UploadedFile $file): string
    {
        $mime = strtolower($file->getClientMimeType());

        if (in_array($mime, self::PHOTO_MIME, true)) {
            return 'photo';
        }
        if (in_array($mime, self::VIDEO_MIME, true)) {
            return 'video';
        }

        throw ValidationException::withMessages([
            'file' => 'Фақат сурат (JPG, PNG, WEBP, HEIC) ёки видео (MP4, WEBM, MOV) юкланади.',
        ]);
    }

    private function assertSize(UploadedFile $file, string $kind): void
    {
        $max = $kind === 'video' ? self::MAX_VIDEO : self::MAX_PHOTO;

        if ($file->getSize() > $max) {
            $mb = (int) round($max / 1024 / 1024);
            throw ValidationException::withMessages([
                'file' => "Файл ҳажми {$mb} МБ дан ошмаслиги керак.",
            ]);
        }
    }

    /** @return array{width: ?int, height: ?int} */
    private function imageSize(UploadedFile $file, string $kind): array
    {
        if ($kind !== 'photo') {
            return ['width' => null, 'height' => null];
        }

        // HEIC ni GD o'qimaydi — bu xato emas, shunchaki o'lcham noma'lum qoladi.
        $info = @getimagesize($file->getRealPath());

        return [
            'width' => is_array($info) ? ($info[0] ?? null) : null,
            'height' => is_array($info) ? ($info[1] ?? null) : null,
        ];
    }
}
