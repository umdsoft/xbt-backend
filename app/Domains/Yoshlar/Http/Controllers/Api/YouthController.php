<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Http\Controllers\Api;

use App\Domains\Yoshlar\Http\Requests\YouthStoreRequest;
use App\Domains\Yoshlar\Http\Requests\YouthUpdateRequest;
use App\Domains\Yoshlar\Models\EmploymentCase;
use App\Domains\Yoshlar\Models\Patronage;
use App\Domains\Yoshlar\Models\Youth;
use App\Domains\Yoshlar\Models\YouthCase;
use App\Domains\Yoshlar\Services\PiiGuard;
use App\Domains\Yoshlar\Services\YouthService;
use App\Domains\Yoshlar\Support\YoshlarAccess;
use App\Domains\Yoshlar\Support\YoshlarScope;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class YouthController extends Controller
{
    public function __construct(
        private readonly YouthService $service,
        private readonly YoshlarAccess $access,
        private readonly YoshlarScope $scope,
        private readonly PiiGuard $pii,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAction($request, 'yoshlar.view');

        $page = $this->service->paginate($request->user(), $request->query());

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** Reyestr statistikasi — dashboard uchun (marshruti `{youth}` dan OLDIN). */
    public function stats(Request $request): JsonResponse
    {
        $this->authorizeAction($request, 'yoshlar.view');

        $user = $request->user();
        $base = fn () => $this->scope->applyYouth(Youth::query(), $user)
            ->where('registry_status', 'active');

        // Yosh oraliqlari `age()` ORQALI EMAS, sana chegaralari bilan: `age()`
        // PostgreSQL'da STABLE (IMMUTABLE emas), shuning uchun u indeksdan
        // foydalana olmaydi. Chegaralarni oldindan hisoblab, `birth_date`
        // ustunidagi indeks ishlaydigan solishtirishga aylantiramiz.
        $bound = static fn (int $years): string => now()->subYears($years)->toDateString();

        $ageBands = $base()->visibleInRegistry()
            ->selectRaw(
                'count(*) filter (where birth_date > ?) as b14,'
                .' count(*) filter (where birth_date <= ? and birth_date > ?) as b18,'
                .' count(*) filter (where birth_date <= ? and birth_date > ?) as b23,'
                .' count(*) filter (where birth_date <= ?) as b27',
                [$bound(18), $bound(18), $bound(23), $bound(23), $bound(27), $bound(27)],
            )
            ->first();

        // OʻSISH — HAQIQIY, oʻylab topilgan emas.
        //
        // Panelda «↗ 2.4%» kabi belgi koʻrsatiladi. Uni chiroy uchun
        // toʻqib chiqarish mumkin emas: rahbar shu raqamga qarab qaror
        // qabul qiladi. Shuning uchun oʻlchov aniq: oxirgi 7 kunda
        // qoʻshilgan yozuvlarning undan oldingi bazaga nisbati.
        $total = $base()->visibleInRegistry()->count();

        $addedLast7 = $base()->visibleInRegistry()
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        $before = $total - $addedLast7;
        $growth = $before > 0 ? round(($addedLast7 / $before) * 100, 1) : null;

        return response()->json([
            'total' => $total,
            'added_7d' => $addedLast7,

            // `null` — solishtirish uchun asos yoʻq (reyestr boʻsh edi).
            // Nol EMAS: «oʻsish boʻlmadi» va «oʻlchab boʻlmaydi» boshqa gap.
            'growth_7d' => $growth,
            'neet' => $base()->visibleInRegistry()->where('is_neet', true)->count(),
            'pending' => $base()->where('verification_status', 'pending')->count(),
            'by_district' => $base()->visibleInRegistry()
                ->selectRaw('district_id, count(*) as total')
                ->groupBy('district_id')->pluck('total', 'district_id'),

            // Uchta kesim BITTA so'rovdan emas, uchta guruhlashdan keladi —
            // ammo har biri bitta so'rov, sahifadagi har kartochka uchun
            // alohida so'rov EMAS (N+1 dashboard'da ham xatarli).
            'by_age' => [
                '14-17' => (int) ($ageBands->b14 ?? 0),
                '18-22' => (int) ($ageBands->b18 ?? 0),
                '23-26' => (int) ($ageBands->b23 ?? 0),
                '27-30' => (int) ($ageBands->b27 ?? 0),
            ],
            'by_education' => $base()->visibleInRegistry()
                ->selectRaw('education_status, count(*) as total')
                ->groupBy('education_status')->pluck('total', 'education_status'),
            'by_employment' => $base()->visibleInRegistry()
                ->selectRaw('employment_status, count(*) as total')
                ->groupBy('employment_status')->pluck('total', 'employment_status'),
        ]);
    }

    public function show(Request $request, string $youth): JsonResponse
    {
        $this->authorizeAction($request, 'yoshlar.view');

        $model = $this->service->find($request->user(), $youth);

        // Doiradan tashqaridagi yozuv uchun 404 — 403 emas: 403 yozuv MAVJUDLIGINI
        // oshkor qilardi (mavjudlik ham ma'lumot).
        abort_if($model === null, 404, 'Yozuv topilmadi.');

        // Yoshning BUTUN tarixi bitta so'rovda: muammolari, otaligʻi,
        // bandlik arizalari. Aks holda xodim to'rt sahifani ochib, o'zi
        // bog'lashi kerak bo'lardi va aloqa ko'rinmay qolardi.
        return response()->json([
            'data' => $model,
            'related' => [
                'cases' => YouthCase::query()->where('youth_id', $model->id)
                    ->orderByDesc('created_at')->get(),
                'patronage' => Patronage::query()->with('mentor:id,position,org_id')
                    ->where('youth_id', $model->id)->orderByDesc('started_at')->get(),
                'employment' => EmploymentCase::query()->where('youth_id', $model->id)
                    ->orderByDesc('submitted_at')->get(),
            ],
        ]);
    }

    public function store(YouthStoreRequest $request): JsonResponse
    {
        $this->authorizeAction($request, 'yoshlar.youth.create');

        $data = $request->validated();

        abort_unless(
            $this->scope->canTouchDistrict($request->user(), $data['district_id']),
            403,
            'Bu tuman sizning doirangizda emas.',
        );

        $youth = $this->service->create($request->user(), $data);

        return response()->json([
            'data' => $youth,
            'duplicate_warning' => empty($data['pinfl'])
                ? max(0, $this->service->possibleDuplicates($data) - 1)
                : 0,
        ], 201);
    }

    public function update(YouthUpdateRequest $request, string $youth): JsonResponse
    {
        $user = $request->user();
        $model = $this->service->find($user, $youth);
        abort_if($model === null, 404, 'Yozuv topilmadi.');

        $this->authorizeUpdate($request, $model);

        $data = $request->validated();

        // YANGI tuman ham doirada boʻlishi shart. Aks holda xodim oʻz
        // tumanidagi yozuvni boshqa tumanga koʻchirib yuborishi mumkin edi —
        // yozuv uning uchun ham, yangi tuman uchun ham «yoʻqolgan» boʻlardi.
        if (isset($data['district_id']) && $data['district_id'] !== $model->district_id) {
            abort_unless(
                $this->scope->canTouchDistrict($user, $data['district_id']),
                403,
                'Yozuvni sizning doirangizdan tashqariga koʻchirib boʻlmaydi.',
            );
        }

        return response()->json(['data' => $this->service->update($user, $model, $data)]);
    }

    public function destroy(Request $request, string $youth): JsonResponse
    {
        $this->authorizeAction($request, 'yoshlar.youth.delete');

        $model = $this->service->find($request->user(), $youth);
        abort_if($model === null, 404, 'Yozuv topilmadi.');

        $model->delete();

        return response()->json(['status' => 'ok']);
    }

    public function verify(Request $request, string $youth): JsonResponse
    {
        $model = $this->findVerifiable($request, $youth);

        return response()->json(['data' => $this->service->verify($request->user(), $model)]);
    }

    public function reject(Request $request, string $youth): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $model = $this->findVerifiable($request, $youth);

        return response()->json([
            'data' => $this->service->reject($request->user(), $model, $validated['reason']),
        ]);
    }

    public function revealPii(Request $request, string $youth): JsonResponse
    {
        $model = $this->service->find($request->user(), $youth);
        abort_if($model === null, 404, 'Yozuv topilmadi.');

        return response()->json($this->pii->reveal($request->user(), $model));
    }

    private function findVerifiable(Request $request, string $youth): Youth
    {
        $this->authorizeAction($request, 'yoshlar.youth.verify');

        $model = $this->service->find($request->user(), $youth);
        abort_if($model === null, 404, 'Yozuv topilmadi.');

        return $model;
    }

    /**
     * Yozish huquqi. `sektor_bolim` istisno: u FAQAT o'zi kiritgan va hali
     * tasdiqlanmagan (`pending`/`rejected`) yozuvni tuzatishi mumkin — rad
     * etilgan taklifni qayta yuborish uchun.
     */
    private function authorizeUpdate(Request $request, Youth $youth): void
    {
        $user = $request->user();

        if ($this->access->can($user, 'yoshlar.youth.update')) {
            abort_unless(
                $this->scope->canTouchDistrict($user, $youth->district_id),
                403,
                'Bu tuman sizning doirangizda emas.',
            );

            return;
        }

        $isOwnPending = $this->access->roleFor($user) === 'sektor_bolim'
            && $youth->created_by === $user->id
            && in_array($youth->verification_status, ['pending', 'rejected'], true);

        abort_unless($isOwnPending, 403, 'Bu yozuvni tahrirlash huquqingiz yo‘q.');
    }

    private function authorizeAction(Request $request, string $permission): void
    {
        abort_unless($this->access->can($request->user(), $permission), 403, 'Ruxsat yo‘q.');
    }
}
