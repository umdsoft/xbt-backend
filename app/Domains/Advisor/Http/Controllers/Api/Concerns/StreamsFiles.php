<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Http\Controllers\Api\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Maxfiy diskdan fayl uzatish + rasm uchun ixtiyoriy thumbnail (`?w=<px>`).
 *
 * Frontend ro'yxat/grid'да kichik rasm so'raydi (`?w=200`) — to'liq rasm o'rniga
 * kichraytirilган nusxa uzatiladi (bandwidth tejaladi). Kichraytirilган nusxa
 * o'sha maxfiy diskда keshlanadi (`advisor/thumbs/{w}/`). GD yo'q / rasm emas /
 * xato bo'lsa — GRACEFUL: asl fayl uzatiladi (unumdorlik auditi F4).
 */
trait StreamsFiles
{
    /** Faylni uzatadi; `?w=` berilса va rasm bo'lса — thumbnail. */
    protected function streamFile(string $disk, string $path, ?string $name, Request $request): StreamedResponse
    {
        $w = (int) $request->query('w', '0');

        if ($w >= 40 && $w <= 1000 && function_exists('imagescale')) {
            $thumb = $this->resizedThumb($disk, $path, $w);
            if ($thumb !== null) {
                return Storage::disk($disk)->response($thumb, $name);
            }
        }

        return Storage::disk($disk)->response($path, $name);
    }

    /**
     * Kichraytirilган nusxa yo'lini qaytaradi (keshdan yoki yangi yaratib) — yoki
     * null (rasm emas / GD qo'llab-quvvatlamaydi / asl kичik / xato).
     */
    private function resizedThumb(string $disk, string $path, int $w): ?string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return null;
        }

        $fs = Storage::disk($disk);
        $cache = 'advisor/thumbs/'.$w.'/'.md5($path).'.'.$ext;
        if ($fs->exists($cache)) {
            return $cache;
        }

        try {
            $src = @imagecreatefromstring($fs->get($path));
            if ($src === false) {
                return null;
            }
            // Asl rasm allaqachon kichik bo'lsa — kichraytirmaymiz (upscale yo'q).
            if (imagesx($src) <= $w) {
                imagedestroy($src);

                return null;
            }

            $scaled = imagescale($src, $w); // balandlik nisbatni saqlab avto
            imagedestroy($src);
            if ($scaled === false) {
                return null;
            }

            ob_start();
            match ($ext) {
                'png' => imagepng($scaled),
                'webp' => imagewebp($scaled, 82),
                default => imagejpeg($scaled, null, 82),
            };
            $bin = (string) ob_get_clean();
            imagedestroy($scaled);

            if ($bin === '') {
                return null;
            }
            $fs->put($cache, $bin);

            return $cache;
        } catch (Throwable) {
            return null; // graceful — asl fayl uzatiladi
        }
    }
}
