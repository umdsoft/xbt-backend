<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Http\Controllers\Api;

use App\Domains\Advisor\Models\MonitoringSheet;
use App\Domains\Advisor\Services\MonitoringService;
use App\Domains\Advisor\Support\AdvisorAccess;
use App\Http\Controllers\Controller;
use App\Support\SimpleXlsx;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * «СВОД ЖАДВАЛЛАР» — qaror/farmon ijrosi monitoringi.
 *
 * Qamrov: barcha rol butun svodni KO'RADI (shaffoflik); TUMAN faqat o'z satrини kiritadi
 * (monitoring.enter); VILOYAT svod/ustun yaratadi (monitoring.manage) + tasdiqlaydi
 * (monitoring.confirm); qiymatларни o'zgартирмайди (faqat tasdiq).
 */
class MonitoringController extends Controller
{
    public function __construct(
        private readonly AdvisorAccess $access,
        private readonly MonitoringService $monitoring,
    ) {}

    /** Svodlar ro'yxati (kategoriya/qidiruv/holat filtri). */
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'monitoring.view'), 403, 'Свод жадвалларни кўришга рухсат йўқ.');

        $v = $request->validate([
            'category' => ['nullable', 'string', 'max:24'],
            'status' => ['nullable', 'string', 'in:active,archived'],
            'q' => ['nullable', 'string', 'max:200'],
        ]);

        return response()->json(['sheets' => $this->monitoring->listSheets($v)]);
    }

    /** Yangi svod + ustunlar — FAQAT viloyat. */
    public function store(Request $request): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'monitoring.manage'), 403, 'Свод яратишга рухсат йўқ.');

        $v = $request->validate([
            'title' => ['required', 'string', 'max:500'],
            'basis' => ['nullable', 'string', 'max:1000'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'reference_date' => ['nullable', 'date'],
            'category' => ['nullable', 'string', 'in:qaror,farmon,pq,farmoyish,other'],
            'as_of_date' => ['nullable', 'date'],
            'completion_threshold' => ['nullable', 'integer', 'between:1,100'],
            'metrics' => ['required', 'array', 'min:1', 'max:12'],
            'metrics.*.name' => ['required', 'string', 'max:255'],
            'metrics.*.weight' => ['nullable', 'numeric', 'between:0,100'],
            'metrics.*.unit' => ['nullable', 'string', 'max:16'],
        ]);

        $sheet = $this->monitoring->createSheet($v, $v['metrics'], (string) $request->user()->id);

        return response()->json(['ok' => true, 'id' => $sheet->id], 201);
    }

    /** Statistika — rolга qarab (viloyat: umumiy + tuman kesimi; tuman: o'z holati). */
    public function stats(Request $request): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'monitoring.view'), 403, 'Статистикани кўришга рухсат йўқ.');

        return response()->json($this->monitoring->stats($this->access->scopeFor($request->user())));
    }

    /** Svod meta/ustun tahriri — FAQAT viloyat (ustun faqat satrlar yo'q bo'lса). */
    public function update(Request $request, MonitoringSheet $sheet): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'monitoring.manage'), 403, 'Свод таҳрирлашга рухсат йўқ.');

        $v = $request->validate([
            'title' => ['sometimes', 'string', 'max:500'],
            'basis' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'reference_no' => ['sometimes', 'nullable', 'string', 'max:100'],
            'reference_date' => ['sometimes', 'nullable', 'date'],
            'category' => ['sometimes', 'string', 'in:qaror,farmon,pq,farmoyish,other'],
            'as_of_date' => ['sometimes', 'nullable', 'date'],
            'completion_threshold' => ['sometimes', 'integer', 'between:1,100'],
            'status' => ['sometimes', 'string', 'in:active,archived'],
            'metrics' => ['sometimes', 'array', 'min:1', 'max:12'],
            'metrics.*.name' => ['required_with:metrics', 'string', 'max:255'],
            'metrics.*.weight' => ['nullable', 'numeric', 'between:0,100'],
            'metrics.*.unit' => ['nullable', 'string', 'max:16'],
        ]);

        $metricsUpdated = $this->monitoring->updateSheet($sheet, $v, $v['metrics'] ?? null);

        return response()->json(['ok' => true, 'metrics_updated' => $metricsUpdated]);
    }

    /** Svodни nusxalash (shablon) — FAQAT viloyat. */
    public function duplicate(Request $request, MonitoringSheet $sheet): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'monitoring.manage'), 403, 'Нусхалашга рухсат йўқ.');

        $copy = $this->monitoring->duplicateSheet($sheet, (string) $request->user()->id);

        return response()->json(['ok' => true, 'id' => $copy->id], 201);
    }

    /** Excel eksport (svod formatida). */
    public function export(Request $request, MonitoringSheet $sheet): Response
    {
        abort_unless($this->access->can($request->user(), 'monitoring.view'), 403, 'Эксортга рухсат йўқ.');

        $g = $this->monitoring->exportGrid($sheet);
        $xlsx = SimpleXlsx::build($g['headers'], $g['rows'], mb_substr($g['title'], 0, 31));

        return response($xlsx, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="svod-'.$sheet->id.'.xlsx"',
        ]);
    }

    /** Svod tafsiloti (13 tuman × ustunlar + UMUMIY + JAMI + tasdiq holati). */
    public function show(Request $request, MonitoringSheet $sheet): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'monitoring.view'), 403, 'Свод жадвалини кўришга рухсат йўқ.');

        return response()->json($this->monitoring->sheetDetail($sheet));
    }

    /** Tuman O'Z satrini kiritadi/yuboradi (qiymatlar + izoh). */
    public function entry(Request $request, MonitoringSheet $sheet): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'monitoring.enter'), 403, 'Свод киритишга рухсат йўқ.');

        $scope = $this->access->scopeFor($user);
        // FAQAT tuman o'z satrини kiritadi (viloyat monitoring qiladi, kiritmaydi).
        abort_unless($scope->isTuman() && $scope->districtId !== null, 403, 'Фақат туман маслаҳатчиси ўз сатрини киритади.');

        $v = $request->validate([
            'values' => ['required', 'array', 'min:1'],
            'values.*.metric_id' => ['required', 'uuid'],
            'values.*.value' => ['required', 'numeric', 'between:0,100'],
            'note' => ['nullable', 'string', 'max:5000'],
            'submit' => ['nullable', 'boolean'],
        ]);

        $values = [];
        foreach ($v['values'] as $row) {
            $values[$row['metric_id']] = (float) $row['value'];
        }

        $this->monitoring->upsertEntry(
            $sheet,
            (string) $scope->districtId,
            $values,
            $v['note'] ?? null,
            (bool) ($v['submit'] ?? false),
            (string) $user->id,
        );

        return response()->json(['ok' => true]);
    }

    /** Viloyat tasdiqlaydi (submitted -> confirmed). */
    public function confirm(Request $request, MonitoringSheet $sheet, string $district): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'monitoring.confirm'), 403, 'Тасдиқлашга рухсат йўқ.');

        $ok = $this->monitoring->confirmEntry($sheet, $district, (string) $request->user()->id);
        abort_unless($ok, 404, 'Сатр топилмади.');

        return response()->json(['ok' => true, 'review_status' => 'confirmed']);
    }

    /** Viloyat qaytaradi (izoh bilan) — tuman qayta kiritadi. */
    public function return(Request $request, MonitoringSheet $sheet, string $district): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'monitoring.confirm'), 403, 'Қайтаришга рухсат йўқ.');

        $v = $request->validate(['comment' => ['required', 'string', 'max:2000']]);

        $ok = $this->monitoring->returnEntry($sheet, $district, $v['comment'], (string) $request->user()->id);
        abort_unless($ok, 404, 'Сатр топилмади.');

        return response()->json(['ok' => true, 'review_status' => 'returned']);
    }
}
