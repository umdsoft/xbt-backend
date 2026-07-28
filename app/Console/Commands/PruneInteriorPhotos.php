<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Mahalla\Models\HousePhoto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * MAXFIYLIK — uy-ICHI (oshxona/hojatxona) zonasidagi eskirgan rasm FAYLLARINI
 * o'chiradi. Kuzatuv yozuvi va AI matni QOLADI (auditlik uchun) — faqat shaxsiy
 * tasvir fayli o'chadi va `pruned_at` belgilanadi (qayta o'chirmaslik uchun).
 *
 * Muddat: config('mahalla.privacy.interior_retention_days').
 * Zonalar: config('mahalla.privacy.interior_zones').
 */
class PruneInteriorPhotos extends Command
{
    protected $signature = 'mahalla:prune-interior-photos {--dry-run : Faqat hisoblab ko\'rsat, o\'chirma}';

    protected $description = 'Uy-ichi zonasidagi eskirgan rasm fayllarini maxfiylik bo\'yicha o\'chiradi (yozuv + AI matni qoladi)';

    public function handle(): int
    {
        $zones = array_values(array_filter((array) config('mahalla.privacy.interior_zones', [])));
        $days = (int) config('mahalla.privacy.interior_retention_days', 30);
        $disk = (string) config('mahalla.photos_disk', 'local');
        $dryRun = (bool) $this->option('dry-run');

        if ($zones === []) {
            $this->info('interior_zones bo\'sh — hech narsa o\'chirilmadi.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $processed = 0;
        $pruned = 0;
        $failed = 0;

        HousePhoto::query()
            ->whereIn('zone', $zones)
            ->whereNull('pruned_at')
            ->where('captured_at', '<', $cutoff)
            ->chunkById(200, function ($photos) use ($disk, $dryRun, &$processed, &$pruned, &$failed) {
                foreach ($photos as $photo) {
                    $processed++;
                    try {
                        if ($dryRun) {
                            $pruned++;

                            continue;
                        }

                        if ($photo->image_path !== null && Storage::disk($disk)->exists($photo->image_path)) {
                            Storage::disk($disk)->delete($photo->image_path);
                        }

                        // Faqat fayl o'chadi — yozuv (metadata + AI natijasi) qoladi.
                        $photo->update(['pruned_at' => now()]);
                        $pruned++;
                    } catch (\Throwable $e) {
                        $failed++;
                        $this->warn("Xato ({$photo->id}): {$e->getMessage()}");
                    }
                }
            });

        $this->info(sprintf(
            'Tekshirildi: %d, %s: %d, xato: %d (muddat: %d kun, zonalar: %s).',
            $processed,
            $dryRun ? 'o\'chirilardi (dry-run)' : 'tozalandi',
            $pruned,
            $failed,
            $days,
            implode(', ', $zones),
        ));

        return self::SUCCESS;
    }
}
