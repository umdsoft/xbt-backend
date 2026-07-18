<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Jobs;

use App\Domains\Mahalla\Models\ZoneObservation;
use App\Domains\Mahalla\Services\ObservationAnalyzer;
use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * KUZATUVni (N rakurs) AI orqali tahlil qiladi. ROBUST QUEUE — ko'p bir vaqtli
 * so'rovga chidamli: alohida 'ai' navbat + RateLimited(RPM) + Redis funnel
 * (concurrency) + WithoutOverlapping + backoff + failed->masul hodim.
 */
class AnalyzeObservationJob implements ShouldQueue
{
    use Queueable;

    /** @var array<int, int> retryable status kodlari */
    private const RETRYABLE = [429, 500, 502, 503, 504, 529];

    public int $timeout = 180;

    public int $maxExceptions = 3;

    public function __construct(public readonly string $observationId)
    {
        $this->onQueue((string) config('mahalla.ai.queue', 'ai'));
    }

    public function tries(): int
    {
        return (int) config('mahalla.ai.max_attempts', 5);
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 30, 60, 120, 300];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->observationId))->releaseAfter(30)->expireAfter(240),
            new RateLimited('mahalla-ai'),
        ];
    }

    public function handle(ObservationAnalyzer $analyzer): void
    {
        $obs = ZoneObservation::find($this->observationId);
        if ($obs === null) {
            return;
        }
        // Idempotentlik: allaqachon hal qilingan (pending emas) -> qayta chaqirmaymiz.
        if ($obs->decision !== 'pending') {
            return;
        }

        try {
            $this->throttleConcurrency(fn () => $analyzer->analyze($obs));
        } catch (RequestException $e) {
            $status = $e->response?->status();
            if (in_array($status, self::RETRYABLE, true)) {
                $retryAfter = (int) ($e->response?->header('retry-after') ?: 0);
                $this->release($retryAfter > 0 ? $retryAfter : ($this->backoff()[$this->attempts() - 1] ?? 60));

                return;
            }
            $this->fail($e);
        }
    }

    private function throttleConcurrency(Closure $fn): void
    {
        $limit = (int) config('mahalla.ai.concurrency', 8);
        if ($limit > 0 && $this->usesRedis()) {
            Redis::funnel('mahalla:ai')->limit($limit)->then($fn, fn () => $this->release(5));

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
        $obs = ZoneObservation::find($this->observationId);
        if ($obs === null) {
            return;
        }
        $obs->update([
            'decision' => 'flagged',
            'is_change' => false,
            'decision_reason' => 'AI тахлил хатоси (қайта уринишлар тугади): '.mb_substr($e->getMessage(), 0, 200),
        ]);
    }
}
