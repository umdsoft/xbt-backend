<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Http\Controllers\Api;

use App\Domains\Ayollar\Models\Household;
use App\Domains\Ayollar\Services\CadastreDirectory;
use App\Domains\Ayollar\Support\AyollarAccess;
use App\Domains\Ayollar\Support\AyollarScope;
use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Xonadonlar — oila darajasidagi ma'lumot.
 *
 * Ayol yozuvidan ALOHIDA: manzil va uy-joy holati bir xonadondagi barcha
 * ayollar uchun BITTA. Har ayolda takrorlansa, ular vaqt o'tib bir-biridan
 * farq qilib qolardi va «qaysi manzil to'g'ri?» degan savol tug'ilardi.
 */
class HouseholdController extends Controller
{
    public function __construct(
        private readonly AyollarAccess $access,
        private readonly AyollarScope $scope,
        private readonly CadastreDirectory $cadastre,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Household::query()->withCount('women');
        $this->scope->apply($query, $request->user());

        if ($request->filled('mahalla_id')) {
            $query->where('mahalla_id', $request->string('mahalla_id')->toString());
        }

        return response()->json(
            $query->orderBy('address')->paginate(min((int) $request->integer('per_page', 50), 200))
        );
    }

    public function store(Request $request): JsonResponse
    {
        if (! $this->access->can($request->user(), 'ayollar.household.manage')) {
            abort(403, 'Xonadon qo‘shishga ruxsat yo‘q.');
        }

        $data = $request->validate([
            'mahalla_id' => ['required', 'uuid'],
            'district_id' => ['required', 'uuid'],
            // Manzil endi KADASTRDAN tanlanadi, lekin `address` majburiy
            // bo'lib qoladi: kadastrda yo'q xonadon (yangi qurilgan uy,
            // hovli ichidagi alohida kirish) uchun yagona yo'l shu.
            'address' => ['required', 'string', 'max:500'],
            'street_id' => ['nullable', 'uuid'],
            'building_id' => ['nullable', 'uuid'],
            'house_number' => ['nullable', 'string', 'max:32'],
            'family_label' => ['nullable', 'string', 'max:120'],
            'residence_type' => ['nullable', 'string', 'max:40'],
            'housing_type' => ['nullable', 'string', 'max:40'],
            'repair_need' => ['nullable', 'string', 'max:40'],
            'in_social_registry' => ['nullable', 'boolean'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'client_uuid' => ['nullable', 'uuid'],
        ]);

        if (! $this->scope->canAccessMahalla($request->user(), $data['mahalla_id'], $data['district_id'])) {
            abort(403, 'Bu MFY sizning doirangizda emas.');
        }

        // Bino BERILGAN bo'lsa, u shu MFY'niki ekani TEKSHIRILADI va
        // koordinata bilan kadastr raqami SERVERDA to'ldiriladi.
        //
        // Nega mijozdan olinmaydi: planshet ularni yubormasligi ham,
        // xato yuborishi ham mumkin. Kadastr raqami esa hujjatda
        // chiqadi — u ishonchli manbadan kelishi shart.
        if (! empty($data['building_id'])) {
            $building = $this->cadastre->building($data['building_id'], $data['mahalla_id']);

            if ($building === null) {
                abort(422, 'Bino bu MFYda topilmadi.');
            }

            $data['street_id'] = $building['street_id'];
            $data['house_number'] = $building['house_number'];
            $data['cadastre'] = $building['cadastre'];
            $data['lat'] = $data['lat'] ?? $building['lat'];
            $data['lng'] = $data['lng'] ?? $building['lng'];
        }

        // `client_uuid` bo'yicha idempotent: offline navbat bir xonadonni
        // bir necha marta yuborsa ham bitta yozuv hosil bo'ladi.
        $existing = ! empty($data['client_uuid'])
            ? Household::query()->where('client_uuid', $data['client_uuid'])->first()
            : null;

        /*
            BIR BINODA BITTA XONADON.

            Baza `households_building_unique` cheklovini tutadi. Bir
            uydan IKKINCHI ayolni ro'yxatga olish esa mutlaqo oddiy
            holat — ona va qizi, kelin va qaynona. Klient bunda yangi
            xonadon yaratardi, `insert` cheklovga urilardi va faolga
            «500 Internal Server Error» ko'rinardi. Ro'yxatga olish
            shu joyda butunlay to'xtab qolardi.

            Endi mavjud xonadon QAYTARILADI: ikkinchi ayol o'sha
            xonadonga biriktiriladi. Klient javobdagi `id` ni olib,
            ayolni shunga bog'laydi.
        */
        if ($existing === null && ! empty($data['building_id'])) {
            $existing = Household::query()->where('building_id', $data['building_id'])->first();

            if ($existing !== null) {
                return response()->json(['household' => $existing, 'reused' => true], 200);
            }
        }

        /*
            POYGA HOLATI — YUQORIDAGI TEKSHIRUV YETARLI EMAS.

            Ikki faol (yoki bitta faolning ikki urinishi) bir vaqtda
            bitta binoga yozsa, ikkalasi ham yuqorida «xonadon yo'q»
            javobini oladi va ikkalasi ham `insert` qiladi. Biri
            cheklovga uriladi.

            2026-09-17 da shu sabab BIR KUNDA 989 ta «500» chiqdi va
            u yolg'iz qolmadi: xonadon yaratilmagani uchun klient
            uning `id` sini ololmadi, natijada 836 ta anketa va 36 ta
            ayol so'rovi ham 404 bergan. Ya'ni bitta poyga butun
            ro'yxatga olish zanjirini uzgan.

            Shuning uchun cheklov XATO EMAS, JAVOB deb qaraladi:
            kimdir bizdan oldin ulgurgan bo'lsa, o'sha yozuv
            qaytariladi. Natija foydalanuvchi uchun bir xil —
            ikkinchi ayol o'sha xonadonga biriktiriladi.
        */
        try {
            $household = Household::query()->updateOrCreate(
                $existing !== null
                    ? ['id' => $existing->id]
                    : ($data['client_uuid'] ?? null
                        ? ['client_uuid' => $data['client_uuid']]
                        : ['id' => (string) Str::uuid()]),
                $data + ['created_by' => $request->user()->id],
            );
        } catch (QueryException $e) {
            // 23505 — PostgreSQL `unique_violation`. Boshqa xatolar
            // (ulanish, tur, cheklov) YUTILMAYDI: ular haqiqiy nosozlik.
            if ($e->getCode() !== '23505') {
                throw $e;
            }

            $household = $this->findConflicting($data);

            if ($household === null) {
                throw $e;
            }

            return response()->json(['household' => $household, 'reused' => true], 200);
        }

        return response()->json(['household' => $household], 201);
    }

    /**
     * Cheklovga urilgan so'rov qaysi yozuv bilan to'qnashdi.
     *
     * Ikki noyob cheklov bor — `client_uuid` va `building_id` —
     * shuning uchun ikkalasi ham qaraladi. Topilmasa `null`: u holda
     * to'qnashuv boshqa sababdan va xato yashirilmaydi.
     *
     * @param  array<string, mixed>  $data
     */
    private function findConflicting(array $data): ?Household
    {
        if (! empty($data['client_uuid'])) {
            $byUuid = Household::query()->where('client_uuid', $data['client_uuid'])->first();

            if ($byUuid !== null) {
                return $byUuid;
            }
        }

        if (! empty($data['building_id'])) {
            return Household::query()->where('building_id', $data['building_id'])->first();
        }

        return null;
    }
}
