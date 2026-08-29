<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Http\Controllers\Api;

use App\Domains\Ayollar\Models\Anketa;
use App\Domains\Ayollar\Models\SyncConflict;
use App\Domains\Ayollar\Models\Woman;
use App\Domains\Ayollar\Services\AnketaService;
use App\Domains\Ayollar\Services\AnketaValidator;
use App\Domains\Ayollar\Services\CategoryResolver;
use App\Domains\Ayollar\Services\QrService;
use App\Domains\Ayollar\Support\AyollarAccess;
use App\Domains\Ayollar\Support\AyollarScope;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Anketa API — reyestr, kartochka, saqlash va OFFLINE PAKET.
 */
class AnketaController extends Controller
{
    public function __construct(
        private readonly AyollarAccess $access,
        private readonly AyollarScope $scope,
        private readonly AnketaService $service,
        private readonly AnketaValidator $validator,
        private readonly CategoryResolver $resolver,
        private readonly QrService $qr,
    ) {}

    /**
     * Reyestr — SERVER TOMONIDA sahifalanadi.
     *
     * ~1 mln yozuv bo'ladi (486 MFY × 2000 ayol). Mijoz tomonida
     * sahifalash imkonsiz. JShShIR javobda MASKALANGAN — `Woman` modeli
     * shifrlangan ustunlarni serializatsiyaga umuman qo'ymaydi.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Anketa::query()
            ->with(['woman:id,full_name,birth_date,mahalla_id', 'redFlags:id,anketa_id,flag_code']);

        $this->scope->apply($query, $request->user());

        foreach (['category', 'status', 'age_group', 'balance_row', 'district_id', 'mahalla_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->string($filter)->toString());
            }
        }

        // Qidiruv: F.I.Sh., ro'yxat raqami yoki QR token.
        //
        // JShShIR bo'yicha qidiruv ALOHIDA endpoint'da (`check-duplicate`):
        // uni bu yerga qo'shish har qidiruvda hash hisoblashni va
        // maxfiy maydonni so'rov satriga tushirishni anglatardi.
        if ($request->filled('q')) {
            $term = $request->string('q')->trim()->toString();

            $query->where(function ($q) use ($term): void {
                $q->where('reg_number', 'ilike', "%{$term}%")
                    ->orWhere('qr_token', strtoupper($term))
                    ->orWhereHas('woman', fn ($w) => $w->where('full_name_norm', 'ilike', '%'.mb_strtolower($term).'%'));
            });
        }

        $perPage = min((int) $request->integer('per_page', 25), 100);

        return response()->json(
            $query->orderByDesc('filled_at')->paginate($perPage)
        );
    }

    /**
     * Kartochka — javoblar, toifa izi, qizil belgilar va QR.
     *
     * V bo'lim (ijtimoiy nazorat) javoblari CHEKLANGAN: ularni faqat
     * `pii.reveal` huquqi bor rol ko'radi. Boshqalarda javob o'rniga
     * `null` qaytadi — bu «ma'lumot yo'q» emas, «ko'rishga ruxsat yo'q»
     * degani va SPA shuni ko'rsatadi.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $anketa = $this->findScoped($request, $id);
        $user = $request->user();

        $answers = $anketa->answers ?? [];

        if (! $this->access->can($user, 'ayollar.pii.reveal')) {
            foreach (\App\Domains\Ayollar\Support\Rules::sensitiveQuestions() as $q) {
                unset($answers["q{$q}"]);
            }
        }

        return response()->json([
            'anketa' => $anketa->only([
                'id', 'reg_number', 'form_version', 'age_group', 'category', 'balance_row',
                'status', 'filled_at', 'synced_at', 'qr_token', 'mahalla_id', 'district_id',
            ]),
            'answers' => $answers,
            'sensitive_hidden' => ! $this->access->can($user, 'ayollar.pii.reveal'),
            // «Toifa qanday aniqlandi» bloki (promt §10.4) — qaysi shart
            // ishladi, qaysilari o'tkazib yuborildi.
            'resolution_trace' => $anketa->resolution_trace,
            'red_flags' => $anketa->redFlags->pluck('flag_code'),
            'woman' => [
                'id' => $anketa->woman?->id,
                'full_name' => $anketa->woman?->full_name,
                'birth_date' => $anketa->woman?->birth_date,
                // Maskalangan — accessor shunday qaytaradi.
                'pinfl' => $anketa->woman?->pinfl,
                'consent_signed_at' => $anketa->woman?->consent_signed_at,
            ],
            'qr' => [
                'url' => $this->qr->url($anketa),
                'token' => $anketa->qr_token,
            ],
        ]);
    }

    /** Toifa izi bilan birga QR SVG. */
    public function qrSvg(Request $request, string $id): \Illuminate\Http\Response
    {
        $anketa = $this->findScoped($request, $id);

        return response($this->qr->svg($anketa), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /** Bitta anketa saqlash. */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeWrite($request);

        $data = $request->validate([
            'woman_id' => ['required', 'uuid'],
            'answers' => ['required', 'array'],
            'status' => ['nullable', 'string', 'in:draft,completed'],
            'device_id' => ['nullable', 'string', 'max:100'],
            'client_uuid' => ['nullable', 'uuid'],
            'gps_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'gps_lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $woman = Woman::query()->findOrFail($data['woman_id']);

        if (! $this->scope->canAccessMahalla($request->user(), (string) $woman->mahalla_id, (string) $woman->district_id)) {
            abort(403, 'Bu MFY sizning doirangizda emas.');
        }

        $errors = $this->validator->validate(
            $data['answers'],
            $woman->birth_date,
            $woman->consent_signed_at,
            null,
            (string) $woman->id,
        );

        // Qoralama tekshiruvdan O'TMAY saqlanadi: faol anketani yarim
        // yo'lda qoldirib ketishi mumkin va uni yo'qotmaslik kerak.
        // «completed» esa to'liq tekshiruvdan o'tadi.
        if (($data['status'] ?? 'completed') !== 'draft' && $errors !== []) {
            return response()->json(['message' => 'Anketa tekshiruvdan o‘tmadi.', 'errors' => $errors], 422);
        }

        $codes = $this->geoCodes($woman);

        $anketa = $this->service->save(
            $woman,
            $data['answers'],
            $data,
            $codes['district'],
            $codes['mahalla'],
            (string) $request->user()->id,
        );

        return response()->json(['anketa' => $anketa, 'validation' => $errors], 201);
    }

    /**
     * OFFLINE NAVBAT — bir martada 100 tagacha.
     *
     * IDEMPOTENT (promt §11): `client_uuid` bo'yicha dublikat RAD ETILADI,
     * lekin XATO QAYTARMAYDI. Sabab: navbat tarmoq uzilganda qayta
     * yuboradi va takroriy yozuv xato bo'lsa, planshet cheksiz qayta
     * urinish siklida qolardi.
     */
    public function batch(Request $request): JsonResponse
    {
        $this->authorizeWrite($request);

        $limit = (int) config('ayollar.batch_limit', 100);

        $data = $request->validate([
            'items' => ['required', 'array', 'max:'.$limit],
            'items.*.client_uuid' => ['required', 'uuid'],
            'items.*.woman_id' => ['required', 'uuid'],
            'items.*.answers' => ['required', 'array'],
            'items.*.updated_at' => ['nullable', 'date'],
            'device_id' => ['nullable', 'string', 'max:100'],
        ]);

        $results = [];

        foreach ($data['items'] as $item) {
            try {
                $results[] = $this->syncOne($request, $item, $data['device_id'] ?? null);
            } catch (\Throwable $e) {
                // Bitta yozuv yiqilsa, QOLGANI YUBORILAVERADI. To'liq
                // rad etish faolning bir kunlik ishini qurilmada
                // qamab qo'yardi.
                $results[] = [
                    'client_uuid' => $item['client_uuid'],
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return response()->json(['results' => $results]);
    }

    /**
     * Konfliktni faol tanlovi bilan hal qiladi.
     *
     * SERVER O'ZI HAL QILMAYDI (promt §14): ikkala versiya ham haqiqiy
     * tashrifdan kelgan bo'lishi mumkin va qaysi biri to'g'ri ekanini
     * faqat odam biladi.
     */
    public function resolveConflict(Request $request, string $id): JsonResponse
    {
        $this->authorizeWrite($request);

        $data = $request->validate([
            'choice' => ['required', 'in:server,client'],
        ]);

        $conflict = SyncConflict::query()->where('status', SyncConflict::PENDING)->findOrFail($id);
        $anketa = Anketa::query()->findOrFail($conflict->entity_id);

        if ($data['choice'] === 'client') {
            $woman = Woman::query()->findOrFail($anketa->woman_id);
            $codes = $this->geoCodes($woman);

            $this->service->save(
                $woman,
                $conflict->client_version['answers'] ?? [],
                ['client_uuid' => $conflict->client_uuid, 'device_id' => $conflict->device_id],
                $codes['district'],
                $codes['mahalla'],
                (string) $request->user()->id,
            );
        }

        $conflict->update([
            'status' => 'resolved_'.$data['choice'],
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
        ]);

        return response()->json(['conflict' => $conflict->fresh()]);
    }

    /** Hal qilinmagan konfliktlar — `Planshet 7 · Sinxronizatsiya` ekrani. */
    public function conflicts(Request $request): JsonResponse
    {
        $ids = Anketa::query()->select('id');
        $this->scope->apply($ids, $request->user());

        return response()->json([
            'conflicts' => SyncConflict::query()
                ->where('status', SyncConflict::PENDING)
                ->whereIn('entity_id', $ids)
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }

    // ---------------------------------------------------------------

    /**
     * Bitta offline yozuvni qabul qiladi.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function syncOne(Request $request, array $item, ?string $deviceId): array
    {
        $existing = Anketa::query()->where('client_uuid', $item['client_uuid'])->first();

        // Idempotentlik: shu `client_uuid` allaqachon qabul qilingan va
        // qurilmadagi nusxa yangiroq EMAS -> jimgina «duplicate».
        if ($existing !== null && ! $this->clientIsNewer($existing, $item)) {
            return [
                'client_uuid' => $item['client_uuid'],
                'status' => 'duplicate',
                'anketa_id' => $existing->id,
            ];
        }

        // Server yozuvi qurilmadagidan KEYIN o'zgargan -> KONFLIKT.
        if ($existing !== null && $this->serverChangedIndependently($existing, $item, $deviceId)) {
            $conflict = SyncConflict::query()->create([
                'entity' => 'anketa',
                'entity_id' => $existing->id,
                'client_uuid' => $item['client_uuid'],
                'device_id' => $deviceId,
                'server_version' => ['answers' => $existing->answers, 'updated_at' => $existing->updated_at],
                'client_version' => ['answers' => $item['answers'], 'updated_at' => $item['updated_at'] ?? null],
                'status' => SyncConflict::PENDING,
            ]);

            return [
                'client_uuid' => $item['client_uuid'],
                'status' => 'conflict',
                'conflict_id' => $conflict->id,
            ];
        }

        $woman = Woman::query()->findOrFail($item['woman_id']);

        if (! $this->scope->canAccessMahalla($request->user(), (string) $woman->mahalla_id, (string) $woman->district_id)) {
            return ['client_uuid' => $item['client_uuid'], 'status' => 'forbidden'];
        }

        $codes = $this->geoCodes($woman);

        $anketa = $this->service->save(
            $woman,
            $item['answers'],
            [
                'client_uuid' => $item['client_uuid'],
                'device_id' => $deviceId,
                'status' => Anketa::STATUS_SYNCED,
            ],
            $codes['district'],
            $codes['mahalla'],
            (string) $request->user()->id,
        );

        $anketa->update(['synced_at' => now()]);

        return [
            'client_uuid' => $item['client_uuid'],
            'status' => 'ok',
            'anketa_id' => $anketa->id,
            'reg_number' => $anketa->reg_number,
            'category' => $anketa->category,
            'balance_row' => $anketa->balance_row,
        ];
    }

    /** @param array<string, mixed> $item */
    private function clientIsNewer(Anketa $anketa, array $item): bool
    {
        $clientTime = $item['updated_at'] ?? null;

        return $clientTime !== null && strtotime((string) $clientTime) > $anketa->updated_at->timestamp;
    }

    /**
     * Server nusxasi BOSHQA qurilmadan o'zgarganmi.
     *
     * Bir qurilmaning o'z yozuvini yangilashi konflikt EMAS — bu oddiy
     * tahrir. Konflikt faqat ikki manba bir yozuvga tegganda.
     *
     * @param  array<string, mixed>  $item
     */
    private function serverChangedIndependently(Anketa $anketa, array $item, ?string $deviceId): bool
    {
        if ($anketa->device_id === null || $anketa->device_id === $deviceId) {
            return false;
        }

        $clientTime = $item['updated_at'] ?? null;

        return $clientTime !== null && $anketa->updated_at->timestamp > strtotime((string) $clientTime);
    }

    /** @return array{district: string, mahalla: string} */
    private function geoCodes(Woman $woman): array
    {
        $district = DB::connection('master')->table('districts')
            ->where('id', $woman->district_id)->first(['code', 'soato_code']);

        $mahalla = DB::connection('master')->table('mahallas')
            ->where('id', $woman->mahalla_id)->value('soato_code');

        return [
            'district' => (string) ($district->code ?? $district->soato_code ?? '0'),
            'mahalla' => (string) ($mahalla ?? '0'),
        ];
    }

    private function findScoped(Request $request, string $id): Anketa
    {
        $query = Anketa::query()->with(['woman', 'redFlags']);
        $this->scope->apply($query, $request->user());

        return $query->findOrFail($id);
    }

    private function authorizeWrite(Request $request): void
    {
        if (! $this->access->can($request->user(), 'ayollar.anketa.create')
            && ! $this->access->can($request->user(), 'ayollar.anketa.update')) {
            abort(403, 'Anketa yozishga ruxsat yo‘q.');
        }
    }
}
