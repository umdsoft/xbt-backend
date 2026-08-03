<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Http\Controllers\Api;

use App\Domains\Advisor\Models\KpiEntry;
use App\Domains\Advisor\Services\KpiService;
use App\Domains\Advisor\Support\AdvisorAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * KPI — katalog / kiritish (ijro %) / tasdiq / xulosa / avto-derivatsiya (spec §7).
 *
 * Qamrov (AdvisorAccess): tuman FAQAT o'z tumani entrisini kiritadi/ko'radi;
 * viloyat/bo'linma hammasini; tasdiq/derivatsiya — FAQAT viloyat.
 * Ruxsatlar: kpi.view / kpi.enter / kpi.approve.
 */
class KpiController extends Controller
{
    public function __construct(
        private readonly AdvisorAccess $access,
        private readonly KpiService $kpi,
    ) {}

    /** KPI katalogi (scope bo'yicha filtr) — [{id,code,name,unit,scope}]. */
    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'scope' => ['nullable', 'string', 'in:viloyat,tuman'],
        ]);

        return response()->json($this->kpi->catalog($v['scope'] ?? null));
    }

    /** Kiritilgan qiymatlar (ijro% bilan) — qamrov + period/district filtri. */
    public function entries(Request $request): JsonResponse
    {
        $v = $request->validate([
            'district_id' => ['nullable', 'uuid'],
            'period' => ['nullable', 'string', 'max:12'],
        ]);

        $scope = $this->access->scopeFor($request->user());

        return response()->json($this->kpi->entries($scope, $v));
    }

    /**
     * Qiymat kiritish/yangilash (tuman|bo'linma) — bir (kpi, district, period)
     * uchun bitta entry. Tuman FAQAT o'z tumani; viloyat-darajali KPI (district
     * null) faqat viloyat/bo'linma.
     */
    public function storeEntry(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'kpi.enter'), 403, 'KPI киритишга рухсат йўқ.');

        $v = $request->validate([
            'kpi_id' => ['required', 'uuid'],
            'district_id' => ['nullable', 'uuid'],
            'period' => ['required', 'string', 'max:12'],
            'value' => ['required', 'numeric'],
            'target' => ['nullable', 'numeric'], // Reja (viloyat/bo'linma natija+reja kiritganда)
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $scope = $this->access->scopeFor($user);

        $kpi = DB::connection('advisor')->table('kpis')->where('id', $v['kpi_id'])->first(['scope']);
        abort_if($kpi === null, 404, 'KPI топилмади.');

        $districtId = $v['district_id'] ?? null;

        // Scope izchilligi: viloyat-KPI -> district null; tuman-KPI -> district shart.
        if ($kpi->scope === 'viloyat') {
            $districtId = null;
        } elseif ($districtId === null) {
            abort(422, 'Туман KPI учун туман кўрсатилиши шарт.');
        }

        // Tuman FAQAT o'z tumani; viloyat-darajali KPIni kirита olmaydi (IDOR himoyasi).
        if ($scope->isTuman()) {
            if ($kpi->scope === 'viloyat') {
                abort(403, 'Вилоят KPIсини туман маслаҳатчиси кирита олмайди.');
            }
            if ($districtId !== $scope->districtId) {
                abort(403, 'Бошқа туман учун KPI кирита олмайди.');
            }
        }

        // Reja (target) — viloyat/bo'linma natija bilan birga rejani ham kiritishi mumkin.
        if (array_key_exists('target', $v) && $v['target'] !== null) {
            $this->kpi->setTarget($v['kpi_id'], $districtId, $v['period'], (float) $v['target']);
        }

        $id = $this->kpi->upsertEntry(
            $v['kpi_id'],
            $districtId,
            $v['period'],
            (float) $v['value'],
            $v['note'] ?? null,
            (string) $user->id,
        );

        return response()->json(['ok' => true, 'id' => $id, 'status' => 'submitted'], 201);
    }

    /**
     * YILLIK kesim (ko'rsatkich × 4 chorak) — tuman o'z tumanini butun yil bo'yicha
     * ko'radi (chorak tanlashsiz). Viloyat/bo'linma istalgan tuman (district_id) yoki
     * viloyat darajasi (district_id yo'q).
     */
    public function year(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'kpi.view'), 403, 'KPIни кўришга рухсат йўқ.');

        $v = $request->validate([
            'year' => ['nullable', 'integer', 'min:2020', 'max:2100'],
            'district_id' => ['nullable', 'uuid'],
        ]);

        $scope = $this->access->scopeFor($user);
        $year = (int) ($v['year'] ?? 2026);

        // Tuman FAQAT o'z tumani; viloyat/bo'linma filtr bergan tumani (yoki viloyat darajasi).
        $districtId = $scope->isTuman() ? $scope->districtId : ($v['district_id'] ?? null);

        if ($scope->isTuman() && $districtId === null) {
            return response()->json(['year' => $year, 'quarters' => [], 'district_id' => null, 'rows' => []]);
        }

        return response()->json($this->kpi->yearMatrix($districtId, $year));
    }

    /** Tasdiq (viloyat) — entry status 'approved'. */
    public function approveEntry(Request $request, string $entry): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'kpi.approve'), 403, 'KPI тасдиқлашга рухсат йўқ.');

        $model = KpiEntry::find($entry);
        if ($model === null) {
            throw new NotFoundHttpException('KPI ёзуви топилмади');
        }

        $this->kpi->approveEntry($model, (string) $request->user()->id);

        return response()->json(['ok' => true, 'status' => 'approved']);
    }

    /** Tuman kesimida KPI xulosasi (viloyat/bo'linma). */
    public function summary(Request $request): JsonResponse
    {
        $scope = $this->access->scopeFor($request->user());
        abort_unless($scope->seesAllDistricts(), 403, 'KPI хулосаси фақат вилоят/бўлинма учун.');

        $v = $request->validate([
            'period' => ['required', 'string', 'max:12'],
        ]);

        return response()->json($this->kpi->summary($v['period']));
    }

    /** TO'LIQ matritsa (tuman KPIlari × 13 tuman: Reja+Bajarilish+ijro%) — viloyat/bo'linma. */
    public function matrix(Request $request): JsonResponse
    {
        $scope = $this->access->scopeFor($request->user());
        abort_unless($scope->seesAllDistricts(), 403, 'KPI матрицаси фақат вилоят/бўлинма учун.');

        $v = $request->validate([
            'period' => ['required', 'string', 'max:12'],
        ]);

        return response()->json($this->kpi->matrix($v['period']));
    }
}
