<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Http\Controllers\Api;

use App\Domains\Ayollar\Models\Balance;
use App\Domains\Ayollar\Models\DistrictBalance;
use App\Domains\Ayollar\Models\MahallaBalance;
use App\Domains\Ayollar\Models\Metric;
use App\Domains\Ayollar\Models\RegionBalance;
use App\Domains\Ayollar\Services\BalanceCalculator;
use App\Domains\Ayollar\Services\BalanceRefresher;
use App\Domains\Ayollar\Services\BalanceWorkflow;
use App\Domains\Ayollar\Support\AyollarAccess;
use App\Domains\Ayollar\Support\AyollarScope;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Balans API — uch daraja, yopish va 8 imzoli tasdiqlash.
 *
 * O'QISH ENDPOINT'LARI HISOBLAMAYDI. Ular `BalanceRefresher` orqali
 * saqlangan qatorni oladi; yangilash anketa saqlanganda (inkremental,
 * bitta MFY) va kechasi (to'liq, 509 MFY) bo'ladi — promt §4 dagi
 * «kechasi qayta hisoblanadi + real vaqtda inkremental» aynan shu.
 *
 * BU AVVAL BOSHQACHA EDI va u xato edi: `region()` har so'rovda 509 MFY
 * va 13 tumanni qayta hisoblardi (~2500 so'rov) va boshqaruv paneli
 * umuman ochilmasdi. «Real vaqt» — ma'lumot o'zgarganda yangilash
 * degani, har qaraganda qayta hisoblash emas.
 */
class BalanceController extends Controller
{
    public function __construct(
        private readonly AyollarAccess $access,
        private readonly AyollarScope $scope,
        private readonly BalanceCalculator $calculator,
        private readonly BalanceRefresher $refresher,
        private readonly BalanceWorkflow $workflow,
    ) {}

    public function mahalla(Request $request, string $mahallaId): JsonResponse
    {
        $districtId = (string) DB::connection('master')->table('mahallas')
            ->where('id', $mahallaId)->value('district_id');

        if (! $this->scope->canAccessMahalla($request->user(), $mahallaId, $districtId)) {
            abort(403, 'Bu MFY sizning doirangizda emas.');
        }

        [$year, $month] = $this->period($request);

        // O'QISH HISOBLAMAYDI — saqlangan qatorni oladi. Yangilash anketa
        // saqlanganda (inkremental) va kechasi (to'liq) bo'ladi.
        return $this->respond($this->refresher->readMahalla($mahallaId, $year, $month));
    }

    public function district(Request $request, string $districtId): JsonResponse
    {
        if (! $this->scope->canAccessDistrict($request->user(), $districtId)) {
            abort(403, 'Bu tuman sizning doirangizda emas.');
        }

        [$year, $month] = $this->period($request);

        $mahallaIds = $this->refresher->mahallaIdsOf($districtId);
        $balance = $this->refresher->readDistrict($districtId, $year, $month);

        return $this->respond($balance, [
            // To'liqlik: nechta MFY balansi yopilgan. `mahalla_union`
            // idorasi aynan shuni tasdiqlaydi (promt §5.1).
            'completeness' => $this->completeness($mahallaIds, $year, $month),
        ]);
    }

    public function region(Request $request): JsonResponse
    {
        // DOIRA TEKSHIRUVI — `ayollar.view` YETARLI EMAS.
        //
        // `ayollar.view` har bir rolda bor, ya'ni u «tizimni ko'ra
        // oladi» degani, «viloyatni ko'ra oladi» degani emas. Shu
        // sababdan MFY faoli viloyat balansini — 13 tumanning
        // yig'indisini — ocha olardi.
        if (! $this->scope->canAccessRegion($request->user())) {
            abort(403, 'Viloyat balansi sizning doirangizda emas.');
        }

        [$year, $month] = $this->period($request);

        $districtIds = $this->refresher->districtIds();
        $balance = $this->refresher->readRegion($year, $month);

        return $this->respond($balance, [
            'districts' => DistrictBalance::query()
                ->whereIn('district_id', $districtIds)
                ->where('period_year', $year)->where('period_month', $month)
                ->get(['district_id', 'total', 'green', 'yellow', 'red', 'status']),
        ]);
    }

    /** MFY'lar kesimi — `Veb 2 · Hududlar kesimi` ekrani. */
    public function mahallasOfDistrict(Request $request, string $districtId): JsonResponse
    {
        if (! $this->scope->canAccessDistrict($request->user(), $districtId)) {
            abort(403, 'Bu tuman sizning doirangizda emas.');
        }

        [$year, $month] = $this->period($request);
        $mahallaIds = $this->refresher->mahallaIdsOf($districtId);

        $names = DB::connection('master')->table('mahallas')
            ->whereIn('id', $mahallaIds)->pluck('name_lat', 'id');

        $balances = MahallaBalance::query()
            ->whereIn('mahalla_id', $mahallaIds)
            ->where('period_year', $year)->where('period_month', $month)
            ->get()
            ->map(fn (MahallaBalance $b) => [
                'mahalla_id' => $b->mahalla_id,
                'name' => $names[$b->mahalla_id] ?? '—',
                'total' => $b->total,
                'green' => $b->green,
                'yellow' => $b->yellow,
                'red' => $b->red,
                'status' => $b->status,
            ]);

        return response()->json(['mahallas' => $balances->values()]);
    }

    // ---------------------------------------------------------------
    // OQIM
    // ---------------------------------------------------------------

    public function close(Request $request, string $type, string $id): JsonResponse
    {
        $balance = $this->findBalance($type, $id);
        $this->assertCanManage($request, $balance);

        try {
            $errors = $this->workflow->close($balance, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($errors !== []) {
            return response()->json([
                'message' => 'Balans tekshiruvdan o‘tmadi — yopish bloklandi.',
                'errors' => $errors,
            ], 422);
        }

        return $this->respond($balance->fresh());
    }

    public function sign(Request $request, string $type, string $id): JsonResponse
    {
        $balance = $this->findBalance($type, $id);

        if (! $this->access->can($request->user(), 'ayollar.balance.sign')) {
            abort(403, 'Imzo qo‘yishga ruxsat yo‘q.');
        }

        $orgCode = $this->access->signingOrgCode($request->user());

        if ($orgCode === null) {
            abort(403, 'Sizning idorangiz aniqlanmadi — imzo qo‘yib bo‘lmaydi.');
        }

        $data = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);

        try {
            $this->workflow->sign($balance, $request->user(), $orgCode, $data['comment'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->respond($balance->fresh());
    }

    public function returnBack(Request $request, string $type, string $id): JsonResponse
    {
        $balance = $this->findBalance($type, $id);

        if (! $this->access->can($request->user(), 'ayollar.balance.return')) {
            abort(403, 'Qaytarishga ruxsat yo‘q.');
        }

        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);

        try {
            $this->workflow->returnBack($balance, $request->user(), $data['reason']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->respond($balance->fresh());
    }

    // ---------------------------------------------------------------

    /** @param array<string, mixed> $extra */
    private function respond(Balance $balance, array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'balance' => $balance,
            // Shakl `metric_registry` dan generatsiya qilinadi — kodda
            // hardcode YO'Q (promt §14).
            'form' => Metric::query()->forForm($balance->levelCode())
                ->get(['code', 'name_lat', 'name_cyr', 'category', 'owner_org_code'])
                ->map(fn (Metric $m) => [
                    'code' => $m->code,
                    'name_lat' => $m->name_lat,
                    'name_cyr' => $m->name_cyr,
                    'category' => $m->category,
                    'owner_org_code' => $m->owner_org_code,
                    'value' => (int) (($balance->metrics ?? [])[$m->code] ?? 0),
                ]),
            'checks' => $this->calculator->verify($balance),
            'signatures' => $balance->status === Balance::OPEN ? [] : $this->workflow->signatureState($balance),
        ], $extra));
    }

    /** @return array{0: int, 1: int} */
    private function period(Request $request): array
    {
        // `period=2026-08` formatida. Berilmasa — joriy oy.
        $raw = $request->string('period')->toString();

        if (preg_match('/^(\d{4})-(\d{1,2})$/', $raw, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }

        return [(int) now()->year, (int) now()->month];
    }

    /** @return array{total: int, closed: int} */
    private function completeness(array $mahallaIds, int $year, int $month): array
    {
        $closed = MahallaBalance::query()
            ->whereIn('mahalla_id', $mahallaIds)
            ->where('period_year', $year)->where('period_month', $month)
            ->whereIn('status', [Balance::CLOSED, Balance::APPROVED])
            ->count();

        return ['total' => count($mahallaIds), 'closed' => $closed];
    }

    private function findBalance(string $type, string $id): Balance
    {
        return match ($type) {
            'mahalla' => MahallaBalance::query()->findOrFail($id),
            'district' => DistrictBalance::query()->findOrFail($id),
            'region' => RegionBalance::query()->findOrFail($id),
            default => abort(404),
        };
    }

    private function assertCanManage(Request $request, Balance $balance): void
    {
        $ownerId = (string) $balance->{$balance->ownerKey()};

        $ok = match ($balance->levelCode()) {
            'mahalla' => $this->scope->canAccessMahalla(
                $request->user(),
                $ownerId,
                (string) DB::connection('master')->table('mahallas')->where('id', $ownerId)->value('district_id'),
            ),
            'district' => $this->scope->canAccessDistrict($request->user(), $ownerId),
            default => $this->access->seesEverything($request->user()),
        };

        if (! $ok) {
            abort(403, 'Bu balans sizning doirangizda emas.');
        }
    }
}
