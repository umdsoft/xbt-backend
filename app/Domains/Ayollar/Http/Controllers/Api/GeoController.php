<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Http\Controllers\Api;

use App\Domains\Ayollar\Services\CadastreDirectory;
use App\Domains\Ayollar\Support\AyollarAccess;
use App\Domains\Ayollar\Support\AyollarScope;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GEOGRAFIYA MA'LUMOTNOMASI — tuman, MFY, ko'cha, uy.
 *
 * Bitta kontroller ikki xil ehtiyojga xizmat qiladi:
 *
 *   1. PLANSHET — faol anketa to'ldirayotganda ko'cha va uyni
 *      RO'YXATDAN tanlaydi, qo'lda yozmaydi.
 *   2. ADMINISTRATOR — hisob ochayotganda tuman tanlaydi, so'ng
 *      shu tumanning MFY'lari chiqadi.
 *
 * Ikkalasi ham AYNAN BIR manbadan o'qiydi (`master` sxemasi). Ikki
 * alohida ro'yxat bo'lganda ular vaqt o'tib bir-biridan uzoqlashardi
 * va administrator biriktirgan MFY faol ekranidagi MFY bilan mos
 * kelmay qolishi mumkin edi.
 *
 * DOIRA HAR YERDA TEKSHIRILADI. Faol boshqa MFY ko'chalarini
 * so'rasa 403 oladi — aks holda bu IDOR bo'lardi: manzil ro'yxati
 * ham ma'lumot, u qaysi mahallada qancha uy borligini ochadi.
 */
class GeoController extends Controller
{
    public function __construct(
        private readonly AyollarAccess $access,
        private readonly AyollarScope $scope,
        private readonly CadastreDirectory $cadastre,
    ) {}

    /**
     * Tumanlar ro'yxati.
     *
     * Administrator hisob ochishda ishlatadi. Faol uchun ham ochiq —
     * unga o'z tumani nomi kerak bo'ladi, lekin ro'yxatda maxfiy
     * narsa yo'q: tuman nomlari ochiq ma'lumot.
     *
     * @return JsonResponse
     */
    public function districts(Request $request): JsonResponse
    {
        $this->authorize($request, 'ayollar.view');

        $rows = DB::connection('master')->table('districts')
            ->orderBy('sort_order')
            ->get(['id', 'name_lat', 'sort_order']);

        return response()->json([
            'districts' => $rows->map(fn ($r) => [
                'id' => (string) $r->id,
                'name' => (string) $r->name_lat,
                'code' => str_pad((string) $r->sort_order, 2, '0', STR_PAD_LEFT),
            ])->all(),
        ]);
    }

    /**
     * Tumandagi MFY'lar — xonadon soni bilan.
     *
     * Xonadon soni KADASTRDAN keladi va administratorga aniq
     * ma'lumot beradi: 1 400 xonadonli MFY'ga bitta faol biriktirish
     * real emasligi shu yerda ko'rinadi.
     */
    public function mahallas(Request $request, string $district): JsonResponse
    {
        $this->authorize($request, 'ayollar.view');

        if (! $this->scope->canAccessDistrict($request->user(), $district)) {
            abort(403, 'Bu tuman sizning doirangizda emas.');
        }

        $rows = DB::connection('master')->table('mahallas')
            ->where('district_id', $district)
            ->orderBy('sort_order')
            ->get(['id', 'name_lat', 'soato_code']);

        // Xonadon sonini BITTA so'rovda olamiz. Har MFY uchun alohida
        // `count(*)` 40+ so'rov bo'lardi va tanlagich sekin ochilardi.
        $households = DB::connection('master')->table('buildings')
            ->where('district_id', $district)
            ->where('type', 'residential')
            ->selectRaw('mahalla_id, count(*) as c')
            ->groupBy('mahalla_id')
            ->pluck('c', 'mahalla_id');

        return response()->json([
            'mahallas' => $rows->map(fn ($r) => [
                'id' => (string) $r->id,
                'name' => (string) $r->name_lat,
                'soato' => $r->soato_code === null ? null : (string) $r->soato_code,
                'households' => (int) ($households[$r->id] ?? 0),
            ])->all(),
        ]);
    }

    /**
     * MFY ko'chalari.
     *
     * `mahalla` berilmasa — foydalanuvchining o'z MFY'si. Faol uchun
     * aynan shu yo'l ishlaydi: u parametrsiz so'raydi va o'zinikini
     * oladi.
     */
    public function streets(Request $request, ?string $mahalla = null): JsonResponse
    {
        $mahallaId = $this->resolveMahalla($request, $mahalla);

        return response()->json([
            'mahalla_id' => $mahallaId,
            'households' => $this->cadastre->householdCount($mahallaId),
            'streets' => $this->cadastre->streets($mahallaId),
        ]);
    }

    /** Ko'chadagi uylar — uy raqami, kadastr, koordinata, bandligi. */
    public function houses(Request $request, string $street): JsonResponse
    {
        $mahallaId = $this->resolveMahalla($request, $request->query('mahalla_id'));

        return response()->json([
            'street_id' => $street,
            'houses' => $this->cadastre->houses($mahallaId, $street),
        ]);
    }

    /**
     * So'ralgan MFY'ni aniqlaydi va huquqni tekshiradi.
     *
     * Parametrsiz chaqiruvda foydalanuvchining o'z MFY'si olinadi.
     * Doirasi MFY darajasidan keng bo'lgan rol (tuman, viloyat)
     * parametrsiz chaqirsa 422 oladi: ularda «o'z MFY'si» tushunchasi
     * yo'q va jimgina birinchisini tanlash noto'g'ri javob berardi.
     */
    private function resolveMahalla(Request $request, ?string $requested): string
    {
        $this->authorize($request, 'ayollar.view');

        if ($requested === null || $requested === '') {
            $staff = $this->access->staffFor($request->user());
            $own = $staff?->mahalla_id;

            if ($own === null) {
                abort(422, 'MFY ko‘rsatilmagan va sizga MFY biriktirilmagan.');
            }

            return (string) $own;
        }

        $districtId = DB::connection('master')->table('mahallas')
            ->where('id', $requested)->value('district_id');

        if ($districtId === null) {
            abort(404, 'MFY topilmadi.');
        }

        if (! $this->scope->canAccessMahalla($request->user(), $requested, (string) $districtId)) {
            abort(403, 'Bu MFY sizning doirangizda emas.');
        }

        return $requested;
    }

    private function authorize(Request $request, string $permission): void
    {
        if (! $this->access->can($request->user(), $permission)) {
            abort(403, 'Ruxsat yo‘q.');
        }
    }
}
