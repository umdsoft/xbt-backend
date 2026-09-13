<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Controllers\Api;

use App\Domains\Mahalla\Services\NearbyFinder;
use App\Domains\Mahalla\Support\MahallaAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «Атроф» — deputat turgan nuqta atrofidagi binolar/tashkilotlar.
 *
 * QAMROV ATAYLAB KENG: WorklistController ko'cha-scope (`restrictToStreets`)
 * bilan cheklanadi, bu endpoint esa ATAYLAB butun TUMAN bo'yicha o'qish
 * beradi — dala xodimi atrofidagi HAR QANDAY binoni (biriktirilgan yoki yo'q)
 * ko'rishi mahsulot talabi. Ma'lumot kadastr darajasida (manzil/kadastr/tur),
 * rezident PII yo'q. Monitoring HARAKATLARI (surat yuklash) baribir o'z
 * endpointlarida ko'cha-scope bilan himoyalangan.
 *
 * ROLLAR BO'YICHA QAMROV (FOYDALANUVCHI QARORI, review paytida tasdiqlangan):
 * `deputat`, `rais` va `hokim-yordamchisi` — UCHALASI HAM bu yerda ATAYLAB
 * butun TUMAN kengligida natija oladi — bu `WorklistController`dan FARQLI,
 * u yerda `rais` o'zining bitta `mahallaId`siga toraytiriladi. Sabab: bu
 * xaritaning vazifasi "atrofimda nima bor" bo'lib, ma'muriy chegarani
 * hurmat qilmaydi (rais mahallasi chetiga yetganda xarita bo'sh
 * qolmasligi kerak), ma'lumot PII'siz kadastr darajasida, va raisning
 * deputatdan KAMROQ ko'rishi mantiqsiz bo'lardi.
 *
 * Tumandan tashqariga chiqish faqat `canSeeAll` (admin/viloyat) uchun.
 */
class NearbyController extends Controller
{
    public function __construct(
        private readonly MahallaAccess $access,
        private readonly NearbyFinder $finder,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'radius_m' => ['nullable', 'integer', 'between:200,5000'],
            'limit' => ['nullable', 'integer', 'between:1,1500'],
            'layers' => ['nullable', 'string', 'max:60'],
        ]);

        $lat = (float) $data['lat'];
        $lng = (float) $data['lng'];
        $radiusM = (int) ($data['radius_m'] ?? 3000);
        $limit = (int) ($data['limit'] ?? 600);
        $kinds = $this->parseLayers($data['layers'] ?? null);

        $scope = $this->access->scopeFor($request->user());
        // Qamrovni KENGAYTIRISH faqat RUXSAT bo'yicha bo'lishi kerak, `null`
        // bo'yicha EMAS: MahallaAccess `districtId = null` ni ikki xil holatda
        // qaytaradi — (a) admin/viloyat (canSeeAll=true) va (b) profili to'liq
        // bo'lmagan operatsion user. `??` ularni ajratmaydi va (b) ga istalgan
        // koordinata orqali BOSHQA tumanni ochib qo'yardi.
        $districtId = $scope->canSeeAll
            ? $this->finder->districtIdForPoint($lat, $lng)
            : $scope->districtId; // null => pointsWithOverflow() bo'sh ro'yxat qaytaradi

        $result = $this->finder->pointsWithOverflow($lat, $lng, $radiusM, $kinds, $limit, $districtId);
        $myStreets = array_flip($scope->streetIds);

        $points = array_map(fn (array $r) => $this->presentPoint($r, $myStreets), $result['points']);

        $counts = [
            NearbyFinder::KIND_MONITORING => 0,
            NearbyFinder::KIND_HOME => 0,
            NearbyFinder::KIND_ORG => 0,
        ];
        foreach ($points as $p) {
            // Himoya: kelajakda yangi `kind` qo'shilsa ham ogohlantirishsiz
            // ishlashi uchun — pre-seeded uchta kalitga tayanmaydi.
            $counts[$p['kind']] = ($counts[$p['kind']] ?? 0) + 1;
        }
        $counts['returned'] = count($points);
        // Haqiqiy "kesilganmi" belgisi — `pointsWithOverflow()` SQL'dan
        // $limit+1 qator so'rab, chegaradan tashqarida yana bormi-yo'qmi
        // isbotlaydi. `count($points) >= $limit` (eskisi) aynan $limit-ta
        // mos qator bo'lib hech narsa kesilmagan holatda ham `true` deb
        // yolg'on xabar berardi.
        $counts['truncated'] = $result['has_more'];

        return response()->json([
            'center' => ['lat' => $lat, 'lng' => $lng],
            'radius_m' => $radiusM,
            // districtId = null holatini mahallaForPoint() o'zi RAD qiladi
            // (pointsWithOverflow() bilan bir xil invariant) — bu yerda
            // takror tekshiruv shart emas.
            'current_mahalla' => $this->finder->mahallaForPoint($lat, $lng, $districtId),
            'counts' => $counts,
            'points' => $points,
        ]);
    }

    /**
     * Joriy mahalla chegarasi (xaritada «siz shu yerdasiz» konturi).
     *
     * TUMAN BO'YICHA CHEKLANADI — `index()`dagi bilan bir xil deny-by-default
     * invariant: `canSeeAll` (admin/viloyat) istalgan mahallani ko'ra oladi,
     * boshqa hamma faqat o'z tumani doirasida. Qamrovi aniqlanmagan
     * (`districtId === null`, `canSeeAll === false`) userga esa so'rov
     * BAZAGA UMUMAN yuborilmaydi — `pointsWithOverflow()`/`mahallaForPoint()`
     * bilan bir xil ko'rinishdagi 404 qaytadi, mavjud bo'lmagan id bilan
     * farqlanmaydigan qilib.
     */
    public function boundary(Request $request, string $mahalla): JsonResponse
    {
        $scope = $this->access->scopeFor($request->user());

        if (! $scope->canSeeAll && $scope->districtId === null) {
            abort(404, 'Маҳалла чегараси топилмади.');
        }

        $districtId = $scope->canSeeAll ? null : $scope->districtId;

        $feature = $this->finder->boundaryGeoJson($mahalla, $districtId);

        abort_if($feature === null, 404, 'Маҳалла чегараси топилмади.');

        return response()->json($feature);
    }

    /**
     * `layers` csv → kind ro'yxati. Noma'lum qiymatlar tashlab yuboriladi;
     * berilmasa default: monitoring + org (uy-joylar zichlik sababli OFF).
     *
     * @return array<int, string>
     */
    private function parseLayers(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [NearbyFinder::KIND_MONITORING, NearbyFinder::KIND_ORG];
        }

        $map = [
            'monitoring' => NearbyFinder::KIND_MONITORING,
            'home' => NearbyFinder::KIND_HOME,
            'homes' => NearbyFinder::KIND_HOME,
            'org' => NearbyFinder::KIND_ORG,
            'orgs' => NearbyFinder::KIND_ORG,
        ];

        $kinds = [];
        foreach (explode(',', $raw) as $s) {
            $kind = $map[trim($s)] ?? null;
            if ($kind !== null) {
                $kinds[] = $kind;
            }
        }

        return array_values(array_unique($kinds));
    }

    /**
     * @param  array<string, mixed>  $r
     * @param  array<string, int>  $myStreets  deputat ko'chalari (street_id => idx)
     */
    private function presentPoint(array $r, array $myStreets): array
    {
        $streetId = $r['street_id'] !== null ? (string) $r['street_id'] : null;

        return [
            'id' => (string) $r['id'],
            'lat' => (float) $r['lat'],
            'lng' => (float) $r['lng'],
            'distance_m' => (int) $r['distance_m'],
            'kind' => $this->kindOf($r),
            'type' => (string) $r['type'],
            'is_social' => (bool) $r['is_social'],
            'category' => $r['category'] !== null ? (string) $r['category'] : null,
            'category_label' => $r['category_label'] !== null ? (string) $r['category_label'] : null,
            'address' => $r['address'] !== null ? (string) $r['address'] : null,
            'kadastr' => $r['kadastr'] !== null ? (string) $r['kadastr'] : null,
            'house_number' => $r['house_number'] !== null ? (string) $r['house_number'] : null,
            'street' => $r['street'] !== null ? (string) $r['street'] : null,
            'mahalla' => $r['mahalla_name'] !== null ? (string) $r['mahalla_name'] : null,
            'monitored' => $r['house_id'] !== null,
            'overall_status' => $r['overall_status'] !== null ? (string) $r['overall_status'] : null,
            'mine' => $streetId !== null && isset($myStreets[$streetId]),
        ];
    }

    /** @param array<string, mixed> $r */
    private function kindOf(array $r): string
    {
        if ($r['type'] === 'non_residential') {
            return NearbyFinder::KIND_ORG;
        }

        return $r['house_id'] !== null ? NearbyFinder::KIND_MONITORING : NearbyFinder::KIND_HOME;
    }
}
