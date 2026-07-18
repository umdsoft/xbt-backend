<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Jobs;

use App\Domains\Mahalla\Models\HousePhoto;
use App\Domains\Mahalla\Services\PhotoAnalyzer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Kunlik rasmni AI (Claude Vision) orqali tahlil qiladi. Asinxron (queue).
 */
class AnalyzePhotoJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public readonly string $housePhotoId)
    {
    }

    public function handle(PhotoAnalyzer $analyzer): void
    {
        $photo = HousePhoto::find($this->housePhotoId);
        if ($photo === null || $photo->type !== 'daily') {
            return;
        }

        $analyzer->analyze($photo);
    }
}
