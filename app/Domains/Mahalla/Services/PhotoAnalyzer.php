<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Services;

use App\Domains\Mahalla\Models\House;
use App\Domains\Mahalla\Models\HousePhoto;
use App\Domains\Mahalla\Models\HousePhotoAnalysis;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Kunlik rasmni baseline bilan solishtiradi (Claude Vision):
 *   - bir uy ekanini tasdiqlaydi (anti-cheating),
 *   - kunlik bajarilgan ishni va umumiy progressni baholaydi.
 * Natijaga qarab avtomatik tasdiq/flag.
 */
class PhotoAnalyzer
{
    public function analyze(HousePhoto $daily): HousePhotoAnalysis
    {
        $house = $daily->house()->first();
        $baseline = HousePhoto::where('house_id', $daily->house_id)
            ->where('type', 'baseline')
            ->orderBy('taken_date')
            ->first();

        // 1) Baseline yo'q -> solishtirib bo'lmaydi
        if ($baseline === null) {
            return $this->store($daily, null, [
                'same_house' => null,
                'confidence' => null,
                'cheating_suspected' => false,
                'changes' => [],
                'daily_work' => null,
                'progress_percent' => null,
                'decision' => 'flagged',
                'decision_reason' => 'Бошланғич (baseline) расм топилмади — солиштириб бўлмайди.',
                'raw_response' => null,
            ]);
        }

        // 2) API kaliti yo'q -> pending (yuklаш ишлайверади, кейин қайта ишланади)
        $apiKey = config('mahalla.ai.api_key');
        if (empty($apiKey)) {
            return $this->store($daily, $baseline, [
                'same_house' => null,
                'confidence' => null,
                'cheating_suspected' => false,
                'changes' => [],
                'daily_work' => null,
                'progress_percent' => null,
                'decision' => 'pending',
                'decision_reason' => 'AI калити созланмаган — тахлил кутилмоқда.',
                'raw_response' => null,
            ]);
        }

        try {
            $result = $this->callClaude($baseline, $daily);
        } catch (Throwable $e) {
            Log::warning('PhotoAnalyzer Claude xato', ['photo' => $daily->id, 'err' => $e->getMessage()]);

            return $this->store($daily, $baseline, [
                'same_house' => null,
                'confidence' => null,
                'cheating_suspected' => false,
                'changes' => [],
                'daily_work' => null,
                'progress_percent' => null,
                'decision' => 'pending',
                'decision_reason' => 'AI сўрови хатоси: '.mb_substr($e->getMessage(), 0, 180),
                'raw_response' => null,
            ]);
        }

        // 3) Qaror mantiqi
        [$decision, $reason] = $this->decide($result, $daily);

        $analysis = $this->store($daily, $baseline, [
            'same_house' => $result['same_house'] ?? null,
            'confidence' => $result['confidence'] ?? null,
            'cheating_suspected' => (bool) ($result['cheating_suspected'] ?? false),
            'changes' => $result['changes'] ?? [],
            'daily_work' => $result['daily_work'] ?? null,
            'progress_percent' => $result['progress_percent'] ?? null,
            'decision' => $decision,
            'decision_reason' => $reason,
            'raw_response' => $result['_raw'] ?? null,
        ]);

        // Auto-tasdiq bo'lsa honadon progress/statusni yangilash
        if ($decision === 'auto_confirmed' && isset($result['progress_percent'])) {
            $this->applyProgress($house, (int) $result['progress_percent']);
        }

        return $analysis;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function decide(array $r, HousePhoto $daily): array
    {
        $minConf = (float) config('mahalla.ai.auto_confirm_min_confidence', 0.85);
        $conf = (float) ($r['confidence'] ?? 0);
        $sameHouse = (bool) ($r['same_house'] ?? false);
        $cheating = (bool) ($r['cheating_suspected'] ?? false);

        if (! $sameHouse) {
            return ['flagged', 'AI: бошқа уй суратга олинган (same_house=false).'];
        }
        if ($cheating) {
            return ['flagged', 'AI: алдаш шубҳаси (cheating_suspected=true).'];
        }
        if ($daily->geofence_ok === false) {
            return ['flagged', 'GPS уйдан геозонадан ташқарида.'];
        }
        if ($conf < $minConf) {
            return ['flagged', "AI ишончи паст (confidence={$conf} < {$minConf})."];
        }

        return ['auto_confirmed', "AI автоматик тасдиқлади (confidence={$conf})."];
    }

    private function applyProgress(House $house, int $percent): void
    {
        $percent = max(0, min(100, $percent));
        $status = $percent >= 100 ? 'completed' : ($percent > 0 ? 'in_progress' : 'not_started');

        $house->update([
            'progress_percent' => $percent,
            'status' => $status,
        ]);
    }

    /**
     * Claude Vision API chaqiruvi. Ikki rasm + structured JSON so'rovi.
     *
     * @return array<string, mixed>
     */
    private function callClaude(HousePhoto $baseline, HousePhoto $daily): array
    {
        $model = (string) config('mahalla.ai.model');
        $baseUrl = rtrim((string) config('mahalla.ai.base_url'), '/');

        $prompt = <<<'TXT'
Сен уй-жой таъмири мониторинги учун расмларни таҳлил қиласан.
Биринчи расм — БОШЛАНҒИЧ (baseline) ҳолат. Иккинчи расм — БУГУНГИ ҳолат.
Иккиси ҳам БИР УЙ бўлиши керак.

Қуйидаги JSON форматида ФАҚАТ JSON қайтар (изоҳсиз):
{
  "same_house": true|false,        // иккала расм бир уйми?
  "confidence": 0.0-1.0,           // бир уй эканига ишонч
  "cheating_suspected": true|false,// бошқа уй/алдаш шубҳаси
  "changes": ["..."],              // кўринган ўзгаришлар рўйхати (қисқа)
  "daily_work": "...",             // бугун бажарилган иш тавсифи
  "progress_percent": 0-100        // умумий таъмир прогресси
}
TXT;

        $response = Http::withHeaders([
            'x-api-key' => (string) config('mahalla.ai.api_key'),
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->timeout(120)->post("{$baseUrl}/v1/messages", [
            'model' => $model,
            'max_tokens' => 1024,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'БОШЛАНҒИЧ (baseline):'],
                    $this->imageBlock($baseline),
                    ['type' => 'text', 'text' => 'БУГУНГИ:'],
                    $this->imageBlock($daily),
                    ['type' => 'text', 'text' => $prompt],
                ],
            ]],
        ]);

        $response->throw();
        $text = $response->json('content.0.text', '');
        $parsed = $this->extractJson($text);
        $parsed['_raw'] = ['text' => $text, 'usage' => $response->json('usage')];

        return $parsed;
    }

    /**
     * @return array<string, mixed>
     */
    private function imageBlock(HousePhoto $photo): array
    {
        $disk = (string) config('mahalla.photos_disk', 'local');
        $bytes = Storage::disk($disk)->get($photo->image_path);
        $ext = strtolower(pathinfo($photo->image_path, PATHINFO_EXTENSION));
        $media = match ($ext) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };

        return [
            'type' => 'image',
            'source' => [
                'type' => 'base64',
                'media_type' => $media,
                'data' => base64_encode($bytes),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractJson(string $text): array
    {
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function store(HousePhoto $daily, ?HousePhoto $baseline, array $attrs): HousePhotoAnalysis
    {
        return HousePhotoAnalysis::updateOrCreate(
            ['house_photo_id' => $daily->id],
            [...$attrs, 'baseline_photo_id' => $baseline?->id],
        );
    }
}
