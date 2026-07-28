<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Services;

use App\Domains\Mahalla\Models\HousePhoto;

/**
 * ALDASH himoyasi — HONADONLAR-ARO surat takrorini (cross-house reuse) aniqlash.
 *
 * dHash (difference hash): rasm 9x8 gray'ga keltiriladi, har qatorda qo'shni
 * piksellar farqi 1 bitga aylanadi -> 64 bit -> 16 belgili hex satr. O'lchamga,
 * yorug'likka va siqilishga bardoshli; ozgina o'zgartirilgan (crop/qayta saqlangan)
 * rasm ham yaqin (kichik Hamming masofa) bo'lib qoladi.
 *
 * BARCHA usullar xatoga chidamli — dHash yoki qidiruv muvaffaqiyatsiz bo'lsa
 * `null` qaytaradi va HECH QACHON rasm yuklashni bloklamaydi.
 */
class PhotoDedupService
{
    /** Nibble (0..15) uchun oldindan hisoblangan bit soni (popcount). */
    private const NIBBLE_BITS = [0, 1, 1, 2, 1, 2, 2, 3, 1, 2, 2, 3, 2, 3, 3, 4];

    /**
     * Rasm baytlaridan 16 belgili hex dHash. Xato bo'lsa null.
     */
    public function dHash(string $bytes): ?string
    {
        if ($bytes === '' || ! \function_exists('imagecreatefromstring')) {
            return null;
        }

        $src = null;
        $small = null;
        try {
            $src = @imagecreatefromstring($bytes);
            if ($src === false) {
                return null;
            }

            $w = 9;
            $h = 8;
            $small = imagecreatetruecolor($w, $h);
            imagecopyresampled($small, $src, 0, 0, 0, 0, $w, $h, imagesx($src), imagesy($src));

            $bits = '';
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w - 1; $x++) {
                    $bits .= $this->gray($small, $x, $y) > $this->gray($small, $x + 1, $y) ? '1' : '0';
                }
            }

            if (\strlen($bits) !== 64) {
                return null;
            }

            $hex = '';
            for ($i = 0; $i < 64; $i += 4) {
                $hex .= dechex((int) bindec(substr($bits, $i, 4)));
            }

            return $hex; // 16 belgi
        } catch (\Throwable) {
            return null;
        } finally {
            if ($src instanceof \GdImage) {
                imagedestroy($src);
            }
            if ($small instanceof \GdImage) {
                imagedestroy($small);
            }
        }
    }

    /**
     * Ikki hex dHash orasidagi Hamming masofasi (0..64). Uzunliklar mos kelmasa
     * PHP_INT_MAX (solishtirib bo'lmaydi -> hech qachon "moslik" emas).
     */
    public function hamming(string $hexA, string $hexB): int
    {
        $len = \strlen($hexA);
        if ($len === 0 || $len !== \strlen($hexB)) {
            return PHP_INT_MAX;
        }

        $dist = 0;
        for ($i = 0; $i < $len; $i++) {
            $a = $this->hexNibble($hexA[$i]);
            $b = $this->hexNibble($hexB[$i]);
            if ($a < 0 || $b < 0) {
                return PHP_INT_MAX; // hex bo'lmagan belgi
            }
            $dist += self::NIBBLE_BITS[$a ^ $b];
        }

        return $dist;
    }

    /**
     * Berilgan phash'ga BOSHQA honadonlarning so'nggi $sinceDays kundagi
     * rasmlaridan Hamming <= $maxDistance bo'lganini topadi (eng yaqinini).
     *
     * Miqyos uchun nomzodlar $limit bilan cheklanadi (so'nggi rasmlar);
     * Hamming PHP'da hisoblanadi. Xato bo'lsa null (yuklashni bloklamaydi).
     */
    public function findCrossHouseMatch(
        string $phash,
        string $excludeHouseId,
        int $sinceDays = 30,
        int $maxDistance = 6,
        int $limit = 1000,
    ): ?HousePhoto {
        if ($phash === '') {
            return null;
        }

        try {
            $candidates = HousePhoto::query()
                ->whereNotNull('phash')
                ->where('house_id', '!=', $excludeHouseId)
                ->whereNull('pruned_at')
                ->where('created_at', '>=', now()->subDays($sinceDays))
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get(['id', 'house_id', 'observation_id', 'zone', 'phash', 'image_path', 'created_at']);

            $best = null;
            $bestDist = $maxDistance + 1;

            foreach ($candidates as $cand) {
                $candHash = $cand->phash;
                if (! \is_string($candHash) || $candHash === '') {
                    continue;
                }
                $d = $this->hamming($phash, $candHash);
                if ($d <= $maxDistance && $d < $bestDist) {
                    $best = $cand;
                    $bestDist = $d;
                    if ($d === 0) {
                        break; // aynan bir xil — bundan yaxshirog'i yo'q
                    }
                }
            }

            return $best;
        } catch (\Throwable) {
            return null;
        }
    }

    private function gray(\GdImage $img, int $x, int $y): int
    {
        $rgb = imagecolorat($img, $x, $y);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;

        return (int) round(0.299 * $r + 0.587 * $g + 0.114 * $b);
    }

    /** Bitta hex belgini 0..15 ga; hex bo'lmasa -1. */
    private function hexNibble(string $ch): int
    {
        if ($ch >= '0' && $ch <= '9') {
            return \ord($ch) - \ord('0');
        }
        $lower = strtolower($ch);
        if ($lower >= 'a' && $lower <= 'f') {
            return \ord($lower) - \ord('a') + 10;
        }

        return -1;
    }
}
