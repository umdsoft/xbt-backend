<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Http\Controllers\Api;

use App\Domains\Ayollar\Models\Household;
use App\Domains\Ayollar\Models\Woman;
use App\Domains\Ayollar\Services\AnketaValidator;
use App\Domains\Ayollar\Services\CategoryResolver;
use App\Domains\Ayollar\Services\SensitiveAccessService;
use App\Domains\Ayollar\Support\AyollarAccess;
use App\Domains\Ayollar\Support\AyollarScope;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Ayollar reyestri.
 *
 * PII HECH QACHON javobga XOM tushmaydi — `Woman` modeli shifrlangan
 * ustunlarni `$hidden` da saqlaydi va accessor'lar maskalangan qiymat
 * qaytaradi. To'liq qiymat faqat `revealPii()` orqali, jurnal bilan.
 */
class WomanController extends Controller
{
    public function __construct(
        private readonly AyollarAccess $access,
        private readonly AyollarScope $scope,
        private readonly AnketaValidator $validator,
        private readonly CategoryResolver $resolver,
        private readonly SensitiveAccessService $sensitive,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Woman::query()->with('household:id,address,mahalla_id');
        $this->scope->apply($query, $request->user());

        if ($request->filled('household_id')) {
            $query->where('household_id', $request->string('household_id')->toString());
        }

        if ($request->filled('q')) {
            $term = mb_strtolower($request->string('q')->trim()->toString());
            $query->where('full_name_norm', 'ilike', "%{$term}%");
        }

        return response()->json(
            $query->orderBy('full_name')->paginate(min((int) $request->integer('per_page', 25), 100))
        );
    }

    public function store(Request $request): JsonResponse
    {
        if (! $this->access->can($request->user(), 'ayollar.anketa.create')) {
            abort(403, 'Yozuv qo‘shishga ruxsat yo‘q.');
        }

        $data = $request->validate([
            'household_id' => ['required', 'uuid'],
            'full_name' => ['required', 'string', 'max:300'],
            'birth_date' => ['required', 'date', 'before:today'],
            'pinfl' => ['nullable', 'string', 'size:14'],
            'passport' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:20'],
            'consent_signed_at' => ['nullable', 'date'],
            'consent_signature_path' => ['nullable', 'string', 'max:500'],
            'client_uuid' => ['nullable', 'uuid'],
        ]);

        // HAVOLA IKKALA USTUN BO'YICHA: planshet o'z UUID'sini yuboradi,
        // server esa yozuvni O'Z id'si bilan yaratib, klientnikini
        // `client_uuid` ga yozadi. Faqat `id` bo'yicha qidirish har bir
        // ayolni 404 bilan qaytarardi (`ResolvesClientRef` ga qarang).
        $household = Household::query()->byClientRef($data['household_id'])->firstOrFail();

        if (! $this->scope->canAccessMahalla($request->user(), (string) $household->mahalla_id, (string) $household->district_id)) {
            abort(403, 'Bu MFY sizning doirangizda emas.');
        }

        // Dublikat SAQLASHDAN OLDIN tekshiriladi va QAYERDA ekani
        // qaytariladi — «bu ayol 12-MFYda ro'yxatdan o'tgan».
        if (! empty($data['pinfl'])) {
            $duplicate = $this->validator->findDuplicate($data['pinfl']);

            if ($duplicate !== null) {
                $mahallaName = DB::connection('master')->table('mahallas')
                    ->where('id', $duplicate['mahalla_id'])->value('name_lat');

                return response()->json([
                    'message' => 'Bu JShShIR allaqachon ro‘yxatdan o‘tgan.',
                    'duplicate' => $duplicate + ['mahalla_name' => $mahallaName],
                ], 409);
            }
        }

        $age = $this->resolver->ageAt(Carbon::parse($data['birth_date']));

        $woman = new Woman;
        $woman->fill([
            'household_id' => $household->id,
            'mahalla_id' => $household->mahalla_id,
            'district_id' => $household->district_id,
            'full_name' => $data['full_name'],
            'full_name_norm' => mb_strtolower($data['full_name']),
            'birth_date' => $data['birth_date'],
            'age_group' => $this->resolver->ageGroup($age),
            'consent_signed_at' => $data['consent_signed_at'] ?? null,
            'consent_signature_path' => $data['consent_signature_path'] ?? null,
            'created_by' => $request->user()->id,
            'client_uuid' => $data['client_uuid'] ?? null,
        ]);

        foreach (['pinfl', 'passport', 'phone'] as $field) {
            if (! empty($data[$field])) {
                $woman->{$field} = $data[$field];
            }
        }

        $woman->save();

        return response()->json(['woman' => $woman], 201);
    }

    /**
     * JShShIR dublikatini tekshiradi — SAQLASHDAN OLDIN.
     *
     * Planshet anketa boshlanishida chaqiradi: faol 31 savolni
     * to'ldirib bo'lgandan keyin «dublikat» xabarini olishi eng yomon
     * holat bo'lardi.
     *
     * XOM JShShIR so'rov TANASIDA keladi, URL'da EMAS (promt §14):
     * URL parametrlari server jurnaliga, brauzer tarixiga va proksi
     * keshiga tushadi.
     */
    public function checkDuplicate(Request $request): JsonResponse
    {
        $data = $request->validate(['pinfl' => ['required', 'string', 'size:14']]);

        $duplicate = $this->validator->findDuplicate($data['pinfl']);

        if ($duplicate === null) {
            return response()->json(['duplicate' => false]);
        }

        $geo = DB::connection('master')->table('mahallas')
            ->where('id', $duplicate['mahalla_id'])->first(['name_lat', 'name_cyr']);

        return response()->json([
            'duplicate' => true,
            'mahalla_name' => $geo->name_lat ?? null,
            'mahalla_name_cyr' => $geo->name_cyr ?? null,
            // `woman_id` faqat DOIRA ichida bo'lsa qaytariladi: boshqa
            // tumandagi yozuvning ID'sini berish IDOR uchun eshik ochardi.
            'woman_id' => $this->scope->canAccessDistrict($request->user(), $duplicate['district_id'])
                ? $duplicate['woman_id']
                : null,
        ], 409);
    }

    /**
     * Maskalangan maydonni ochadi — HAR CHAQIRUV JURNALGA TUSHADI.
     *
     * `POST`, `GET` emas: `GET` brauzer tarixiga va server jurnaliga
     * tushadi, prefetch bilan tasodifan chaqirilishi ham mumkin — ya'ni
     * odam so'ramagan holda jurnalga «ochdi» deb yozilardi.
     */
    public function revealPii(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => ['string', 'in:pinfl,passport,phone'],
        ]);

        $query = Woman::query();
        $this->scope->apply($query, $request->user());
        $woman = $query->findOrFail($id);

        return response()->json([
            'values' => $this->sensitive->reveal($request->user(), $woman, $data['fields'], $request),
        ]);
    }
}
