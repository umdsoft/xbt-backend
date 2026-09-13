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
            : $scope->districtId; // null => points() bo'sh ro'yxat qaytaradi

        $rows = $this->finder->points($lat, $lng, $radiusM, $kinds, $limit, $districtId);

        return response()->json([
            'center' => ['lat' => $lat, 'lng' => $lng],
            'radius_m' => $radiusM,
            'points' => array_map(fn (array $r) => $this->presentPoint($r), $rows),
        ]);
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

    /** @param array<string, mixed> $r */
    private function presentPoint(array $r): array
    {
        return [
            'id' => (string) $r['id'],
            'lat' => (float) $r['lat'],
            'lng' => (float) $r['lng'],
            'distance_m' => (int) $r['distance_m'],
            'kind' => $this->kindOf($r),
            'type' => (string) $r['type'],
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
