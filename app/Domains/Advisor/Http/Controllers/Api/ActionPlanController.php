<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Http\Controllers\Api;

use App\Domains\Advisor\Models\ActionPlan;
use App\Domains\Advisor\Models\ActionPlanItem;
use App\Domains\Advisor\Services\ActionPlanService;
use App\Domains\Advisor\Support\AdvisorAccess;
use App\Domains\Advisor\Support\AdvisorScope;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * CHORA-TADBIRLAR — bir nechta yillik reja (jadval) + reja tafsiloti (bo'limlar+
 * bandlar) + band tuman kesimi + bajarilishini kiritish. Reja QO'LDA yaratiladi
 * (tasdiqlovchi hujjat MAJBURIY); bandlar qo'lda qo'shiladi (viloyat).
 *
 * Qamrov (AdvisorAccess):
 *   - viloyat: reja/band yaratadi, barcha tuman kesimi + istalgan tuman bajarilishi.
 *   - bo'linma: barcha tuman kesimi (faqat ko'rish).
 *   - tuman:   o'z tumani bandlari + bajarilishi (o'zi kiritadi).
 */
class ActionPlanController extends Controller
{
    public function __construct(
        private readonly AdvisorAccess $access,
        private readonly ActionPlanService $plans,
    ) {}

    /** Rejalar ro'yxati (jadval) — qamrovга qarab (tuman: o'z + umumiy). */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'plan.view'), 403, 'Чора-тадбирларни кўришга рухсат йўқ.');

        return response()->json(['plans' => $this->plans->listPlans($this->access->scopeFor($user))]);
    }

    /**
     * Yangi reja:
     *   - VILOYAT: umumiy (13 tuman) reja — tasdiqlovchi hujjat MAJBURIY.
     *   - TUMAN:   O'Z rejasi (district_id = o'z tumani) — hujjat ixtiyoriy.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $scope = $this->access->scopeFor($user);
        abort_unless($scope->isViloyat() || $scope->isTuman(), 403, 'Режа яратишга рухсат йўқ.');

        $isTumanOwn = $scope->isTuman();

        $v = $request->validate([
            'title' => ['required', 'string', 'max:500'],
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'status' => ['nullable', 'string', 'in:draft,active,closed'],
            // Viloyat umumiy rejasida hujjat majburiy; tuman o'z rejasida ixtiyoriy.
            'document' => [$isTumanOwn ? 'nullable' : 'required', 'file', 'mimetypes:application/pdf,image/jpeg,image/png,image/webp', 'max:20480'],
        ], [], ['document' => 'тасдиқловчи ҳужжат']);

        // Tuman o'z tumani uchun; viloyat umumiy (null).
        $v['district_id'] = $isTumanOwn ? $scope->districtId : null;

        $plan = $this->plans->createPlan($v, (string) $user->id);
        if ($request->hasFile('document')) {
            $this->plans->storeDocument($plan, $request->file('document'));
        }

        return response()->json(['ok' => true, 'id' => $plan->id], 201);
    }

    /**
     * CHORA-TADBIR STATISTIKASI (har band = bitta topshiriq). Rolга qarab:
     *   - tuman: FAQAT o'z tumani yig'masi.
     *   - viloyat/bo'linма: umumiy + tuman kesimi (leaderboard).
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'plan.view'), 403, 'Чора-тадбирларни кўришга рухсат йўқ.');

        return response()->json($this->plans->stats($this->access->scopeFor($user)));
    }

    /** Reja tafsiloti (bo'limlar bo'yicha bandlar; qamrovга qarab my_progress/summary). */
    public function show(Request $request, ActionPlan $plan): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'plan.view'), 403, 'Чора-тадбирларни кўришга рухсат йўқ.');

        $scope = $this->access->scopeFor($user);
        // Tuman boshqa tuman rejаsини ko'ra olmaydi (umumiy + o'ziники mumkin).
        if ($scope->isTuman() && $plan->district_id !== null && $plan->district_id !== $scope->districtId) {
            abort(403, 'Бу режа бошқа туманники.');
        }

        return response()->json($this->plans->planOverview($plan, $scope));
    }

    /**
     * Rejaga qo'lда band qo'shish. Egalik: viloyat -> umumiy (district_id null) reja;
     * tuman -> FAQAT O'Z rejasi (district_id = o'z tumани).
     */
    public function storeItem(Request $request, ActionPlan $plan): JsonResponse
    {
        $user = $request->user();
        $scope = $this->access->scopeFor($user);

        $canManage = ($scope->isViloyat() && $plan->district_id === null)
            || ($scope->isTuman() && $plan->district_id === $scope->districtId);
        abort_unless($canManage, 403, 'Бу режага банд қўшишга рухсат йўқ.');

        $v = $request->validate([
            'section_title' => ['required', 'string', 'max:255'],
            'item_number' => ['required', 'string', 'max:16'],
            'title' => ['required', 'string', 'max:2000'],
            'mechanism' => ['nullable', 'string', 'max:5000'],
            'deadline_text' => ['nullable', 'string', 'max:255'],
            'deadline' => ['nullable', 'date'],
            'responsible_text' => ['nullable', 'string', 'max:2000'],
            // Tuman o'z rejasида kesim yubormaydi -> all_districts (o'z tumани).
            'scope' => ['nullable', 'string', 'in:all_districts,viloyat'],
        ]);

        $id = $this->plans->addItem($plan, $v);

        return response()->json(['ok' => true, 'id' => $id], 201);
    }

    /** Tasdiqlovchi hujjatni maxfiy diskdan uzatish (URL orqali ochib bo'lmaydi). */
    public function document(Request $request, ActionPlan $plan): StreamedResponse
    {
        abort_unless($this->access->can($request->user(), 'plan.view'), 403);

        $disk = (string) config('advisor.files_disk', 'local');

        if ($plan->document_path === null || ! Storage::disk($disk)->exists($plan->document_path)) {
            throw new NotFoundHttpException('Ҳужжат топилмади');
        }

        return Storage::disk($disk)->response($plan->document_path, $plan->document_name);
    }

    /** Bitta band bo'yicha tuman kesimi (viloyat/bo'linma: 13 tuman; tuman: o'zi). */
    public function showItem(Request $request, ActionPlanItem $item): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'plan.view'), 403, 'Чора-тадбирларни кўришга рухсат йўқ.');

        $scope = $this->access->scopeFor($user);
        if ($scope->isTuman() && ! $this->tumanMayAccessPlanOfItem($item, $scope)) {
            abort(403, 'Бу режа бошқа туманники.');
        }

        $data = $this->plans->itemRows($item, $scope);

        if ($data === null) {
            return response()->json(['message' => 'Банд топилмади'], 404);
        }

        return response()->json($data);
    }

    /** Band bajарилишини kiritish/yangilash (tuman: o'z tumani; viloyat: istalgan). */
    public function progress(Request $request, ActionPlanItem $item): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'plan.progress'), 403, 'Бажарилишини киритишга рухсат йўқ.');

        $v = $request->validate([
            'district_id' => ['nullable', 'uuid'],
            'status' => ['required', 'string', 'in:not_started,in_progress,completed'],
            'report' => ['nullable', 'string', 'max:10000'],
            'progress_percent' => ['nullable', 'integer', 'between:0,100'],
        ]);

        $scope = $this->access->scopeFor($user);

        // Viloyat/bo'linма TUMAN bajarilishini O'ZGARТИРА ОЛМАЙДИ — faqat monitoring.
        // Viloyat faqat viloyat-darajасидаги bandни (scope=viloyat, district null) kiritadi.
        // Tuman boshqa tuman rejаsига yoza olmaydi (o'z rejаsи + umumiy reja mumkin).
        if ($scope->isTuman() && ! $this->tumanMayAccessPlanOfItem($item, $scope)) {
            abort(403, 'Бу режа бошқа туманники.');
        }

        if (! $scope->isTuman() && $item->scope !== 'viloyat') {
            abort(403, 'Туман бажарилишини вилоят ўзгартира олмайди — фақат мониторинг.');
        }

        $this->plans->upsertProgress(
            $item,
            $v['district_id'] ?? null,
            $v['status'],
            $v['report'] ?? null,
            isset($v['progress_percent']) ? (int) $v['progress_percent'] : null,
            (string) $user->id,
            $scope,
        );

        return response()->json(['ok' => true]);
    }

    /**
     * Tuman shu band tegishli rejага kira oladimi? Ha — agar reja UMUMIY (district
     * null) yoki O'Z tumани rejasi bo'lsa (IDOR himoyasi). Boshqa tuman rejasига yo'q.
     */
    private function tumanMayAccessPlanOfItem(ActionPlanItem $item, AdvisorScope $scope): bool
    {
        $planDistrict = DB::connection('advisor')->table('action_plans')
            ->where('id', $item->plan_id)->value('district_id');

        return $planDistrict === null || $planDistrict === $scope->districtId;
    }
}
