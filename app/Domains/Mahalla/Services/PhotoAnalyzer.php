<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Services;

use App\Domains\Mahalla\Models\House;
use App\Domains\Mahalla\Models\HousePhoto;
use App\Domains\Mahalla\Models\HousePhotoAnalysis;
use App\Domains\Mahalla\Models\HouseZoneState;
use App\Domains\Mahalla\Support\MahallaZones;
use App\Domains\Mahalla\Support\ZonePrompts;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Zona-aware AI tahlil: bir honadonning bitta zonasini (fasad/oshxona/hojatxona/
 * tomorqa) OLDINGI kuzatuv bilan solishtirib REAL o'zgarishni aniqlaydi.
 *
 * Asosiy tamoyil: rasm yuklash o'zgarish EMAS. Ishonchli o'zgarish -> avto-tasdiq
 * (holat yangilanadi). Ikkilangan yoki o'zgarishsiz -> masul hodim (flagged).
 *
 * API xatolari (429/5xx) BU YERDA tutilmaydi — job ularni qayta uradi (robust queue).
 */
class PhotoAnalyzer
{
    public function __construct(private readonly HouseProvisioner $provisioner)
    {
    }

    public function analyze(HousePhoto $photo): HousePhotoAnalysis
    {
        $zone = (string) $photo->zone;

        // Solishtirish uchun oldingi kuzatuv (shu honadon + shu zona, undan oldingi).
        $previous = HousePhoto::query()
            ->where('house_id', $photo->house_id)
            ->where('zone', $zone)
            ->where('id', '!=', $photo->id)
            ->where('captured_at', '<=', $photo->captured_at)
            ->orderByDesc('captured_at')
            ->first();

        $state = $this->zoneState($photo->house_id, $zone);
        $prevStatus = $state->status;
        $hasPrevious = $previous !== null;

        if (! MahallaZones::isZone($zone)) {
            return $this->finalize($photo, $previous, $state, [
                'decision' => 'flagged',
                'decision_reason' => 'Zona aniqlanmagan.',
                'is_change' => false,
            ]);
        }

        // API kaliti yo'q -> masul hodim tekshiruvi (pipeline yakunlanadi)
        if (empty(config('mahalla.ai.api_key'))) {
            return $this->finalize($photo, $previous, $state, [
                'decision' => 'flagged',
                'decision_reason' => 'AI калити созланмаган — масул ходим текшируви.',
                'is_change' => false,
                'suggested_status' => $prevStatus,
            ]);
        }

        // AI chaqiruvi (xato bo'lsa -> job qayta uradi)
        $result = $this->callClaude($photo, $previous, $zone, $prevStatus, $hasPrevious);

        [$decision, $reason, $isChange, $newStatus] = $this->decide($result, $photo, $hasPrevious);

        return $this->finalize($photo, $previous, $state, [
            'same_house' => $result['same_location'] ?? null,
            'confidence' => $result['confidence'] ?? null,
            'cheating_suspected' => (bool) ($result['cheating_suspected'] ?? false),
            'changes' => [
                'before' => $result['before_description'] ?? null,
                'after' => $result['after_description'] ?? null,
            ],
            'daily_work' => ($result['change_description'] ?? null) ?: ($result['after_description'] ?? null),
            'progress_percent' => $result['progress_percent'] ?? null,
            'suggested_status' => $result['suggested_status'] ?? null,
            'is_change' => $isChange,
            'decision' => $decision,
            'decision_reason' => $reason,
            'apply_status' => $newStatus, // null -> holat o'zgarmaydi
            'raw_response' => $result['_raw'] ?? null,
        ]);
    }

    /**
     * Qaror mantiqi: [decision, reason, is_change, apply_status].
     *
     * @param  array<string, mixed>  $r
     * @return array{0:string,1:string,2:bool,3:?string}
     */
    private function decide(array $r, HousePhoto $photo, bool $hasPrevious): array
    {
        $min = (float) config('mahalla.ai.auto_confirm_min_confidence', 0.75);
        $conf = (float) ($r['confidence'] ?? 0);
        $same = (bool) ($r['same_location'] ?? false);
        $quality = (bool) ($r['quality_ok'] ?? false);
        $cheat = (bool) ($r['cheating_suspected'] ?? false);
        $changed = (bool) ($r['changed'] ?? false);
        $suggested = $r['suggested_status'] ?? null;
        if (! is_string($suggested) || ! MahallaZones::isStatus($suggested)) {
            $suggested = null;
        }

        if ($photo->geofence_ok === false) {
            return ['flagged', 'GPS уйдан геозонадан ташқарида (75м).', false, null];
        }
        if ($cheat || ! $same) {
            return ['flagged', 'AI: бошқа жой/алдаш шубҳаси.', false, null];
        }
        if (! $quality) {
            return ['flagged', 'AI: расм сифати паст/тушунарсиз.', false, null];
        }

        // Birinchi kuzatuv (baseline): holatni o'rnatadi, lekin o'zgarish EMAS.
        if (! $hasPrevious) {
            if ($suggested !== null && $conf >= $min) {
                return ['auto_confirmed', "AI бошланғич ҳолатни аниқлади (confidence={$conf}).", false, $suggested];
            }

            return ['flagged', "AI ишончи паст (confidence={$conf}) — масул ходим текшируви.", false, null];
        }

        // Keyingi kuzatuvlar: aniq o'zgarish + yuqori ishonch -> avto-tasdiq.
        if ($changed && $suggested !== null && $conf >= $min) {
            return ['auto_confirmed', "AI аниқ ўзгаришни тасдиқлади (confidence={$conf}).", true, $suggested];
        }

        // Ikkilangan yoki o'zgarishsiz -> masul hodim.
        $why = $changed ? "ишонч паст (confidence={$conf})" : 'ўзгариш аниқланмади';

        return ['flagged', "AI: {$why} — масул ходим текшируви.", false, null];
    }

    private function zoneState(string $houseId, string $zone): HouseZoneState
    {
        return HouseZoneState::firstOrCreate(
            ['house_id' => $houseId, 'zone' => $zone],
            ['status' => MahallaZones::DEFAULT_STATUS],
        );
    }

    /**
     * Tahlilni saqlash + zona holatini yangilash (faqat auto-tasdiqda status o'zgaradi).
     *
     * @param  array<string, mixed>  $attrs
     */
    private function finalize(HousePhoto $photo, ?HousePhoto $previous, HouseZoneState $state, array $attrs): HousePhotoAnalysis
    {
        $applyStatus = $attrs['apply_status'] ?? null;
        $isChange = (bool) ($attrs['is_change'] ?? false);
        unset($attrs['apply_status']);

        $analysis = HousePhotoAnalysis::updateOrCreate(
            ['house_photo_id' => $photo->id],
            [
                'baseline_photo_id' => $previous?->id,
                'zone' => $photo->zone,
                'prev_status' => $state->status,
                ...$attrs,
            ],
        );

        // Kuzatuv har doim qayd etiladi; status FAQAT auto-tasdiqda o'zgaradi.
        $stateUpdate = [
            'last_photo_id' => $photo->id,
            'last_observed_at' => $photo->captured_at ?? Carbon::now(),
        ];
        if ($applyStatus !== null) {
            $stateUpdate['status'] = $applyStatus;
            if (isset($attrs['progress_percent']) && is_numeric($attrs['progress_percent'])) {
                $stateUpdate['progress_percent'] = max(0, min(100, (int) $attrs['progress_percent']));
            }
            if ($isChange) {
                $stateUpdate['last_changed_at'] = Carbon::now();
            }
        }
        $state->update($stateUpdate);

        $this->provisioner->recomputeHouse($photo->house_id);

        return $analysis;
    }

    /**
     * Claude Vision chaqiruvi. Oldingi (bo'lsa) + hozirgi rasm + zona promti.
     * HTTP xatoda ->throw() (job qayta uradi).
     *
     * @return array<string, mixed>
     */
    private function callClaude(HousePhoto $photo, ?HousePhoto $previous, string $zone, ?string $prevStatus, bool $hasPrevious): array
    {
        $baseUrl = rtrim((string) config('mahalla.ai.base_url'), '/');
        $prompt = ZonePrompts::build($zone, $prevStatus, $hasPrevious);

        $content = [];
        if ($previous !== null) {
            $content[] = ['type' => 'text', 'text' => 'ОЛДИНГИ ҳолат:'];
            $content[] = $this->imageBlock($previous);
            $content[] = ['type' => 'text', 'text' => 'БУГУНГИ ҳолат:'];
            $content[] = $this->imageBlock($photo);
        } else {
            $content[] = ['type' => 'text', 'text' => 'ҲОЗИРГИ ҳолат:'];
            $content[] = $this->imageBlock($photo);
        }
        $content[] = ['type' => 'text', 'text' => $prompt];

        $response = Http::withHeaders([
            'x-api-key' => (string) config('mahalla.ai.api_key'),
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->timeout((int) config('mahalla.ai.timeout', 60))
            ->post("{$baseUrl}/v1/messages", [
                'model' => (string) config('mahalla.ai.model'),
                'max_tokens' => (int) config('mahalla.ai.max_tokens', 1500),
                'messages' => [['role' => 'user', 'content' => $content]],
            ]);

        $response->throw(); // 4xx/5xx -> RequestException (job hal qiladi)

        $text = (string) $response->json('content.0.text', '');
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
            'source' => ['type' => 'base64', 'media_type' => $media, 'data' => base64_encode((string) $bytes)],
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
}
