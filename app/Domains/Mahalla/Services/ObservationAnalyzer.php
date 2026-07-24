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

        // ASOS (baseline) — shu honadon+zona uchun ENG BIRINCHI kuzatuv.
        // Har yangi kuzatuv AYNAN shu asos bilan solishtiriladi: maqsad —
        // dastlabki holatдан beri UMUMIY o'zgarish/taraqqiyotni o'lchash
        // (ta'mir "olдин→hozir"). Sekvensial (N vs N-1) EMAS. Asos yo'q bo'lsa —
        // bu kuzatuvning O'ZI asos (birinchi), boshlang'ich holatni belgilaydi.
        $baseline = ZoneObservation::query()
            ->where('house_id', $obs->house_id)
            ->where('zone', $zone)
            ->where('id', '!=', $obs->id)
            ->where('observed_at', '<=', $obs->observed_at)
            ->orderBy('observed_at')
            ->orderBy('id')
            ->first();

        $state = $this->zoneState($obs->house_id, $zone);
        $prevStatus = $state->status;
        $hasBaseline = $baseline !== null;

        // Solishtirish uchun ishlatilган asos yozuvi (ustun nomi tarixiy).
        $obs->prev_observation_id = $baseline?->id;
        $obs->prev_status = $prevStatus;

        // Bulut drayveri uchun kalit shart; lokal AI-node uchun emas.
        $driver = (string) config('mahalla.ai.driver', 'claude');
        if ($driver === 'claude' && empty(config('mahalla.ai.api_key'))) {
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
        $baselinePhotos = $hasBaseline
            ? $baseline->photos()->orderBy('angle')->orderBy('created_at')->get()
            : collect();

        // AI (xato -> job retry qiladi). Drayver: lokal AI-node yoki Anthropic.
        // Solishtirish bazasi = ASOS (birinchi kuzatuv) rakurslari.
        $result = $driver === 'local'
            ? $this->callLocalNode($current, $baselinePhotos, $zone, $prevStatus)
            : $this->callClaude($current, $baselinePhotos, $zone, $prevStatus, $hasBaseline);

        [$decision, $reason, $isChange, $newStatus] = $this->decide($result, $obs, $hasBaseline);

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
    private function decide(array $r, ZoneObservation $obs, bool $hasBaseline): array
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

        // LOKAL CV DARVOZASI: sifat/joy tekshirilgan va o'zgarish topilmagan ->
        // AVTO-QABUL (masul hodimga yubormaymiz). Masshtabda kunlik ~90% kuzatuv
        // o'zgarishsiz bo'ladi; ularni qo'lda ko'rish imkonsiz. Review'ga faqat
        // anomaliya (dublikat/boshqa joy/yomon sifat) va AI ikkilanishi boradi.
        if (($r['_meta']['gate'] ?? null) === 'no_change') {
            $why = $r['reasoning'] ?: 'ўзгариш аниқланмади';

            return ['auto_confirmed', 'CV: '.$why, false, null];
        }

        if ($cheat || ! $same) {
            return ['flagged', 'AI: бошқа жой/алдаш шубҳаси.', false, null];
        }

        // SIFAT ikki qatlamli. Lokal node OBYEKTIV sifatni (blur/yorug'lik) o'zi
        // tekshiradi va o'tmasa VLM'ga umuman yubormaydi — ya'ni bu yergacha
        // `gate='vlm'` bo'lib kelgan rasm obyektiv sinovdan o'tgan. VLM'ning
        // `quality_ok` bahosi esa SEMANTIK ("sahnani tushundimmi").
        //
        // Semantik shubhani yolg'iz o'zini flag sababi qilsak, real fotolarda
        // (soya, keng rakurs, g'ayrioddiy burchak) masul hodimga yolg'on oqim
        // ketadi. Shuning uchun: obyektiv darvoza o'tgan bo'lsa shubha
        // avtomatik flag emas, ishonch chegarasini KO'TARADI — VLM chindan
        // ikkilanayotgan bo'lsa confidence baribir past chiqadi va flag bo'ladi.
        if (! $quality) {
            if (($r['_meta']['gate'] ?? null) !== 'vlm') {
                return ['flagged', 'AI: расм сифати паст/тушунарсиз.', false, null];
            }
            $min = min(0.95, $min + (float) config('mahalla.ai.quality_doubt_penalty', 0.15));
        }

        if (! $hasBaseline) {
            // Birinchi kuzatuv — ASOSNI (baseline) belgilaydi.
            if ($suggested !== null && $conf >= $min) {
                return ['auto_confirmed', "AI асос (бошланғич) ҳолатни аниқлади (confidence={$conf}).", false, $suggested];
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
     * LOKAL AI-NODE (LAN'dagi GPU ish stansiyasi) chaqiruvi.
     *
     * Node o'zi OpenCV darvozasini bajaradi (sifat/anti-cheat/o'zgarish) va faqat
     * zarur bo'lsa VLM'ni ishlatadi. Javob Claude drayveri bilan AYNAN bir shaklda,
     * ustiga `_meta.gate` — qaysi bosqichda hal bo'lgani (qaror mantig'i uchun).
     *
     * @param  \Illuminate\Support\Collection<int, HousePhoto>  $current
     * @param  \Illuminate\Support\Collection<int, HousePhoto>  $baseline  ASOS (birinchi kuzatuv) rakurslari
     * @return array<string, mixed>
     */
    private function callLocalNode($current, $baseline, string $zone, ?string $prevStatus): array
    {
        $url = rtrim((string) config('mahalla.ai.node_url'), '/').'/analyze';
        $disk = (string) config('mahalla.photos_disk', 'local');
        $max = (int) config('mahalla.ai.max_angles', 4);

        $req = Http::connectTimeout(10)->timeout((int) config('mahalla.ai.timeout', 180));
        if ($token = (string) config('mahalla.ai.node_token')) {
            $req = $req->withHeaders(['X-AI-Token' => $token]);
        }

        $i = 0;
        foreach ($current->take($max) as $p) {
            $req = $req->attach('current', (string) Storage::disk($disk)->get($p->image_path), 'cur'.($i++).'.jpg');
        }
        // Node API'да maydon nomi 'previous' (tarixiy) — endi ASOS rakurslari yuboriladi.
        $i = 0;
        foreach ($baseline->take($max) as $p) {
            $req = $req->attach('previous', (string) Storage::disk($disk)->get($p->image_path), 'prev'.($i++).'.jpg');
        }

        $response = $req->post($url, [
            'zone' => $zone,
            'prev_status' => $prevStatus ?? '',
        ]);
        $response->throw(); // 4xx/5xx -> job qayta uradi

        $parsed = $response->json();
        if (! is_array($parsed)) {
            throw new \RuntimeException('AI-node javobi noto\'g\'ri shaklda.');
        }
        $parsed['_raw'] = ['node' => $parsed['_meta'] ?? null];

        return $parsed;
    }

    /**
     * Claude Vision — barcha oldingi rakurslar + barcha bugungi rakurslar (cheklangan).
     *
     * @param  \Illuminate\Support\Collection<int, HousePhoto>  $current
     * @param  \Illuminate\Support\Collection<int, HousePhoto>  $baseline  ASOS (birinchi kuzatuv) rakurslari
     * @return array<string, mixed>
     */
    private function callClaude($current, $baseline, string $zone, ?string $prevStatus, bool $hasBaseline): array
    {
        $baseUrl = rtrim((string) config('mahalla.ai.base_url'), '/');
        $max = (int) config('mahalla.ai.max_angles', 4);
        $prompt = ZonePrompts::build($zone, $prevStatus, $hasBaseline);

        $content = [];
        if ($hasBaseline && $baseline->isNotEmpty()) {
            $content[] = ['type' => 'text', 'text' => 'АСОСИЙ (дастлабки) ҳолат (ракурслар):'];
            foreach ($baseline->take($max) as $p) {
                $content[] = $this->imageBlock($p);
            }
            $content[] = ['type' => 'text', 'text' => 'ҲОЗИРГИ ҳолат (ракурслар):'];
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
