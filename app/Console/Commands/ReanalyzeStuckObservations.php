<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Mahalla\Jobs\AnalyzeObservationJob;
use App\Domains\Mahalla\Models\ZoneObservation;
use Illuminate\Console\Command;

/**
 * NODE-DOWN TIKLANISHI — AI-node o'chiq/ulanmadi bo'lgani uchun 'pending'da
 * qotib qolgan kuzatuvlarni qayta tahlilga yuboradi. SPOF'dan keyin ishni
 * avtomatik tiklaydi (aks holda kuzatuv abadiy 'pending'da qolardi).
 *
 * Muddat: config('mahalla.ai.stuck_reanalyze_after_minutes').
 */
class ReanalyzeStuckObservations extends Command
{
    protected $signature = 'mahalla:reanalyze-stuck';

    protected $description = 'Infra nosozligi tufayli \'pending\'da qolgan kuzatuvlarni qayta AI tahliliga yuboradi';

    public function handle(): int
    {
        $minutes = (int) config('mahalla.ai.stuck_reanalyze_after_minutes', 45);
        $cutoff = now()->subMinutes($minutes);

        $dispatched = 0;

        ZoneObservation::query()
            ->where('decision', 'pending')
            ->where('updated_at', '<', $cutoff)
            ->chunkById(200, function ($observations) use (&$dispatched) {
                foreach ($observations as $obs) {
                    AnalyzeObservationJob::dispatch($obs->id);
                    $dispatched++;
                }
            });

        $this->info("Qayta tahlilga yuborildi: {$dispatched} kuzatuv (muddat: {$minutes} daqiqa).");

        return self::SUCCESS;
    }
}
