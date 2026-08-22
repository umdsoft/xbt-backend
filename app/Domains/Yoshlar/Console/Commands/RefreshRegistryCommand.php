<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Console\Commands;

use App\Domains\Yoshlar\Models\Youth;
use Illuminate\Console\Command;

/**
 * Yosh chegarasidan chiqqanlarni arxivga o'tkazadi.
 *
 * NEGA O'CHIRMAYMIZ: yozuv KPI va tarixda qatnashadi (o'tgan yil bandligi,
 * hal etilgan muammolar). Arxiv holati ularni reyestrdan chiqaradi, lekin
 * hisobotda saqlaydi.
 */
class RefreshRegistryCommand extends Command
{
    protected $signature = 'yoshlar:refresh-registry {--dry-run : Faqat sonini ko‘rsatadi}';

    protected $description = 'Yosh chegarasidan chiqqan yozuvlarni arxivga o‘tkazadi';

    public function handle(): int
    {
        $query = Youth::query()
            ->where('registry_status', 'active')
            ->where('birth_date', '<=', now()->subYears(Youth::MAX_AGE + 1)->toDateString());

        $count = $query->count();

        if ((bool) $this->option('dry-run')) {
            $this->info("Arxivga o‘tkaziladi: {$count} ta yozuv (dry-run).");

            return self::SUCCESS;
        }

        $query->update(['registry_status' => 'archived_age']);
        $this->info("Arxivga o‘tkazildi: {$count} ta yozuv.");

        return self::SUCCESS;
    }
}
