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
 * Rolga bog'lanmagan (WorklistController kabi): qamrov MahallaAccess scope
 * orqali TUMAN darajasida cheklanadi. Radiusdagi BARCHA bino ko'rinadi
 * (biriktirilgan/biriktirilmagan) — monitoring HARAKATI esa alohida
 * endpointlarda (worklist/observations) baribir scope bilan himoyalangan.
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
        $districtId = $scope->districtId ?? $this->finder->districtIdForPoint($lat, $lng);

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
        $allowed = [NearbyFinder::KIND_MONITORING, NearbyFinder::KIND_HOME, NearbyFinder::KIND_ORG];
        if ($raw === null || trim($raw) === '') {
            return [NearbyFinder::KIND_MONITORING, NearbyFinder::KIND_ORG];
        }

        $req = array_map(
            static fn (string $s) => rtrim(trim($s), 's'), // 'homes' → 'home', 'orgs' → 'org'
            explode(',', $raw),
        );

        return array_values(array_intersect($allowed, $req));
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
