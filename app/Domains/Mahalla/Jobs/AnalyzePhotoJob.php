<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Jobs;

use App\Domains\Mahalla\Models\HousePhoto;
use App\Domains\Mahalla\Models\HousePhotoAnalysis;
use App\Domains\Mahalla\Services\PhotoAnalyzer;
use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Zona rasmini AI orqali tahlil qiladi (asinxron). ROBUST QUEUE — ko'p bir vaqtli
 * so'rovga chidamli:
 *   - alohida 'ai' navbat (yuklashni bloklamaydi)
 *   - RateLimited (RPM) + Redis funnel (bir vaqtdagi max chaqiruv)
 *   - WithoutOverlapping (bitta rasm bir vaqtda faqat bir marta)
 *   - eksponensial backoff, retryUntil, timeout
 *   - 429/5xx -> qayta urinish; 4xx -> darhol flagged; barcha urinish tugasa -> failed()
 */
class AnalyzePhotoJob implements ShouldQueue
{
    use Queueable;

    /** @var array<int, int> retryable xato status kodlari */
    private const RETRYABLE = [429, 500, 502, 503, 504, 529];

    public int $timeout = 120;

    public int $maxExceptions = 3;

    public function __construct(public readonly string $housePhotoId)
    {
        $this->onQueue((string) config('mahalla.ai.queue', 'ai'));
    }

    public function tries(): int
    {
        return (int) config('mahalla.ai.max_attempts', 5);
    }

    /**
     * Eksponensial backoff (soniya) — 429/overload'da API'ni bosmaslik uchun.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120, 300];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->housePhotoId))->releaseAfter(30)->expireAfter(180),
            new RateLimited('mahalla-ai'),
        ];
    }

    public function handle(PhotoAnalyzer $analyzer): void
    {
        $photo = HousePhoto::find($this->housePhotoId);
        if ($photo === null || $photo->zone === null) {
            return;
        }

        // Idempotentlik: muvaffaqiyatli tahlil qilingan bo'lsa (pending emas) — qayta chaqirmaymiz.
        $existing = HousePhotoAnalysis::where('house_photo_id', $photo->id)->value('decision');
        if ($existing !== null && $existing !== 'pending') {
            return;
        }

        try {
            $this->throttleConcurrency(fn () => $analyzer->analyze($photo));
        } catch (RequestException $e) {
            $status = $e->response?->status();
            if (in_array($status, self::RETRYABLE, true)) {
                // Retry-After bo'lsa — o'shancha, aks holda backoff.
                $retryAfter = (int) ($e->response?->header('retry-after') ?: 0);
                $this->release($retryAfter > 0 ? $retryAfter : ($this->backoff()[$this->attempts() - 1] ?? 60));

                return;
            }

            // Qaytarib bo'lmaydigan (4xx) — behuda urinmaymiz.
            $this->fail($e);
        }
    }

    /**
     * Bir vaqtdagi AI chaqiruvlarini cheklash (Redis funnel). Redis yo'q (local)
     * bo'lsa — to'g'ridan-to'g'ri ishlaydi (kam yuklama).
     */
    private function throttleConcurrency(Closure $fn): void
    {
        $limit = (int) config('mahalla.ai.concurrency', 8);

        if ($limit > 0 && $this->usesRedis()) {
            Redis::funnel('mahalla:ai')
                ->limit($limit)
                ->then($fn, fn () => $this->release(5)); // to'la -> 5s dan keyin

            return;
        }

        $fn();
    }

    private function usesRedis(): bool
    {
        return in_array('redis', [config('queue.default'), config('cache.default')], true);
    }

    public function failed(Throwable $e): void
    {
        // Barcha urinishlar tugadi -> yo'qotmaymiz, masul hodim tekshiruviga.
        $photo = HousePhoto::find($this->housePhotoId);
        if ($photo === null) {
            return;
        }

        HousePhotoAnalysis::updateOrCreate(
            ['house_photo_id' => $photo->id],
            [
                'zone' => $photo->zone,
                'is_change' => false,
                'decision' => 'flagged',
                'decision_reason' => 'AI тахлил хатоси (қайта уринишлар тугади): '.mb_substr($e->getMessage(), 0, 200),
            ],
        );
    }
}
