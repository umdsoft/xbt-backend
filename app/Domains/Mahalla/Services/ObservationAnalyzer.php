<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Services;

use App\Domains\Mahalla\Models\HousePhoto;
use App\Domains\Mahalla\Models\HouseZoneState;
use App\Domains\Mahalla\Models\ZoneObservation;
use App\Domains\Mahalla\Support\MahallaZones;
use App\Domains\Mahalla\Support\ZonePrompts;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * KUZATUV-darajasida AI tahlil: joriy kuzatuvning barcha RAKURSlarini OLDINGI
 * kuzatuvning rakurslari bilan solishtirib REAL o'zgarishni aniqlaydi.
 *
 * Tamoyil: rasm(lar) yuklash o'zgarish EMAS. Ishonchli o'zgarish->avto-tasdiq;
 * ikkilangan/o'zgarishsiz->masul hodim (flagged). API xatosi tutilmaydi (job retry).
 */
class ObservationAnalyzer
{
    public function __construct(private readonly HouseProvisioner $provisioner)
    {
    }

    public function analyze(ZoneObservation $obs): ZoneObservation
    {
        $zone = $obs->zone;

        // Oldingi kuzatuv (shu honadon+zona, undan oldingi) — solishtirish bazasi.
        $previous = ZoneObservation::query()
            ->where('house_id', $obs->house_id)
            ->where('zone', $zone)
            ->where('id', '!=', $obs->id)
            ->where('observed_at', '<=', $obs->observed_at)
            ->orderByDesc('observed_at')
            ->first();

        $state = $this->zoneState($obs->house_id, $zone);
        $prevStatus = $state->status;
        $hasPrevious = $previous !== null;

        $obs->prev_observation_id = $previous?->id;
        $obs->prev_status = $prevStatus;

        // API kaliti yo'q -> masul hodim tekshiruvi
        if (empty(config('mahalla.ai.api_key'))) {
            return $this->finalize($obs, $state, [
                'decision' => 'flagged',
                'decision_reason' => 'AI калити созланмаган — масул ходим текшируви.',
                'is_change' => false,
            ]);
        }

        $current = $obs->photos()->orderBy('angle')->orderBy('created_at')->get();
        if ($current->isEmpty()) {
            return $this->finalize($obs, $state, [
                'decision' => 'flagged',
                'decision_reason' => 'Расм топилмади.',
                'is_change' => false,
            ]);
        }
        $prevPhotos = $hasPrevious
            ? $previous->photos()->orderBy('angle')->orderBy('created_at')->get()
            : collect();

        // AI (xato -> job retry qiladi)
        $result = $this->callClaude($current, $prevPhotos, $zone, $prevStatus, $hasPrevious);

        [$decision, $reason, $isChange, $newStatus] = $this->decide($result, $obs, $hasPrevious);

        return $this->finalize($obs, $state, [
            'confidence' => $result['confidence'] ?? null,
            'suggested_status' => $result['suggested_status'] ?? null,
            'is_change' => $isChange,
            'decision' => $decision,
            'decision_reason' => $reason,
            'apply_status' => $newStatus,
            'ai_result' => [
                'same_location' => $result['same_location'] ?? null,
                'quality_ok' => $result['quality_ok'] ?? null,
                'cheating_suspected' => $result['cheating_suspected'] ?? null,
                'before' => $result['before_description'] ?? null,
                'after' => $result['after_description'] ?? null,
                'changed' => $result['changed'] ?? null,
                'change_description' => $result['change_description'] ?? null,
                'reasoning' => $result['reasoning'] ?? null,
                'raw' => $result['_raw'] ?? null,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array{0:string,1:string,2:bool,3:?string}
     */
    private function decide(array $r, ZoneObservation $obs, bool $hasPrevious): array
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

        if ($obs->is_on_site === false) {
            return ['flagged', 'GPS уйдан геозонадан ташқарида (75м).', false, null];
        }
        if ($cheat || ! $same) {
            return ['flagged', 'AI: бошқа жой/алдаш шубҳаси.', false, null];
        }
        if (! $quality) {
            return ['flagged', 'AI: расм сифати паст/тушунарсиз.', false, null];
        }

        if (! $hasPrevious) {
            if ($suggested !== null && $conf >= $min) {
                return ['auto_confirmed', "AI бошланғич ҳолатни аниқлади (confidence={$conf}).", false, $suggested];
            }

            return ['flagged', "AI ишончи паст (confidence={$conf}) — масул ходим текшируви.", false, null];
        }

        if ($changed && $suggested !== null && $conf >= $min) {
            return ['auto_confirmed', "AI аниқ ўзгаришни тасдиқлади (confidence={$conf}).", true, $suggested];
        }

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
     * Kuzatuvni saqlash + zona holatini yangilash (status FAQAT auto-tasdiqda).
     *
     * @param  array<string, mixed>  $attrs
     */
    private function finalize(ZoneObservation $obs, HouseZoneState $state, array $attrs): ZoneObservation
    {
        $applyStatus = $attrs['apply_status'] ?? null;
        $isChange = (bool) ($attrs['is_change'] ?? false);
        unset($attrs['apply_status']);

        $obs->fill($attrs);
        $obs->status = $applyStatus ?? $state->status;
        $obs->save();

        $stateUpdate = [
            'last_observation_id' => $obs->id,
            'last_observed_at' => $obs->observed_at ?? Carbon::now(),
        ];
        if ($applyStatus !== null) {
            $stateUpdate['status'] = $applyStatus;
            if ($isChange) {
                $stateUpdate['last_changed_at'] = Carbon::now();
            }
        }
        $state->update($stateUpdate);

        $this->provisioner->recomputeHouse($obs->house_id);

        return $obs;
    }

    /**
     * Claude Vision — barcha oldingi rakurslar + barcha bugungi rakurslar (cheklangan).
     *
     * @param  \Illuminate\Support\Collection<int, HousePhoto>  $current
     * @param  \Illuminate\Support\Collection<int, HousePhoto>  $prev
     * @return array<string, mixed>
     */
    private function callClaude($current, $prev, string $zone, ?string $prevStatus, bool $hasPrevious): array
    {
        $baseUrl = rtrim((string) config('mahalla.ai.base_url'), '/');
        $max = (int) config('mahalla.ai.max_angles', 4);
        $prompt = ZonePrompts::build($zone, $prevStatus, $hasPrevious);

        $content = [];
        if ($hasPrevious && $prev->isNotEmpty()) {
            $content[] = ['type' => 'text', 'text' => 'ОЛДИНГИ ҳолат (ракурслар):'];
            foreach ($prev->take($max) as $p) {
                $content[] = $this->imageBlock($p);
            }
            $content[] = ['type' => 'text', 'text' => 'БУГУНГИ ҳолат (ракурслар):'];
        } else {
            $content[] = ['type' => 'text', 'text' => 'ҲОЗИРГИ ҳолат (ракурслар):'];
        }
        foreach ($current->take($max) as $p) {
            $content[] = $this->imageBlock($p);
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

        $response->throw();

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
