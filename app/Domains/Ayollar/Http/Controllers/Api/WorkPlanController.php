<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Http\Controllers\Api;

use App\Domains\Ayollar\Models\Anketa;
use App\Domains\Ayollar\Models\WorkPlan;
use App\Domains\Ayollar\Services\SensitiveAccessService;
use App\Domains\Ayollar\Support\AyollarAccess;
use App\Domains\Ayollar\Support\AyollarScope;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Individual ish rejasi — qizil toifadagi ayollar bo'yicha chora.
 *
 * Ro'yxatda ISMLAR bor, shuning uchun `red.names` huquqi TALAB QILINADI
 * (promt §6.3: faqat 3 rol). Bu «ish rejasi» degan neytral nom ostidagi
 * eng nozik ekran: unda kimning nima muammosi borligi ochiq turadi.
 */
class WorkPlanController extends Controller
{
    public function __construct(
        private readonly AyollarAccess $access,
        private readonly AyollarScope $scope,
        private readonly SensitiveAccessService $sensitive,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (! $this->access->can($request->user(), 'ayollar.workplan.view')) {
            abort(403, 'Ish rejasini ko‘rishga ruxsat yo‘q.');
        }

        $query = WorkPlan::query()->with('woman:id,full_name,mahalla_id');
        $this->scope->apply($query, $request->user());

        foreach (['status', 'district_id', 'mahalla_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->string($filter)->toString());
            }
        }

        // Ismlarni ko'ra olmaydigan rol uchun ular OLIB TASHLANADI —
        // ro'yxatning o'zi qoladi (nechta reja bor, muddati qanday).
        $canSeeNames = $this->access->canSeeRedNames($request->user());

        $page = $query->orderBy('deadline')->paginate(min((int) $request->integer('per_page', 25), 100));

        if (! $canSeeNames) {
            $page->getCollection()->transform(function (WorkPlan $p) {
                $p->unsetRelation('woman');

                return $p;
            });
        }

        return response()->json($page + ['names_hidden' => ! $canSeeNames]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $this->access->can($request->user(), 'ayollar.workplan.manage')) {
            abort(403, 'Ish rejasini yozishga ruxsat yo‘q.');
        }

        $data = $request->validate([
            'woman_id' => ['required', 'uuid'],
            'problem_codes' => ['required', 'array', 'min:1'],
            'problem_codes.*' => ['string', 'max:40'],
            'action' => ['required', 'string', 'max:2000'],
            'responsible_user_id' => ['nullable', 'uuid'],
            'deadline' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        $woman = \App\Domains\Ayollar\Models\Woman::query()->findOrFail($data['woman_id']);

        if (! $this->scope->canAccessMahalla($request->user(), (string) $woman->mahalla_id, (string) $woman->district_id)) {
            abort(403, 'Bu MFY sizning doirangizda emas.');
        }

        $plan = WorkPlan::query()->create($data + [
            'mahalla_id' => $woman->mahalla_id,
            'district_id' => $woman->district_id,
            'status' => 'open',
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['work_plan' => $plan], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if (! $this->access->can($request->user(), 'ayollar.workplan.manage')) {
            abort(403);
        }

        $query = WorkPlan::query();
        $this->scope->apply($query, $request->user());
        $plan = $query->findOrFail($id);

        $plan->update($request->validate([
            'action' => ['sometimes', 'string', 'max:2000'],
            'responsible_user_id' => ['nullable', 'uuid'],
            'deadline' => ['nullable', 'date'],
            'status' => ['sometimes', 'in:open,in_progress,done,cancelled'],
            'result' => ['nullable', 'string', 'max:2000'],
        ]));

        return response()->json(['work_plan' => $plan->fresh()]);
    }

    /**
     * Qizil toifadagi ayollar RO'YXATI — ismlar bilan.
     *
     * `assertCanSeeRedNames()` — alohida darvoza. `workplan.view` yetarli
     * emas: reja ro'yxatini ko'rish va zo'ravonlik qurbonlari ro'yxatini
     * ko'rish ikki xil vakolat.
     */
    public function redList(Request $request): JsonResponse
    {
        $this->sensitive->assertCanSeeRedNames($request->user());

        $query = Anketa::query()->countable()
            ->has('redFlags')
            ->with(['woman:id,full_name,birth_date,mahalla_id', 'redFlags:id,anketa_id,flag_code']);

        $this->scope->apply($query, $request->user());

        if ($request->filled('flag')) {
            $flag = $request->string('flag')->toString();
            $query->whereHas('redFlags', fn ($q) => $q->where('flag_code', $flag));
        }

        return response()->json(
            $query->orderByDesc('filled_at')->paginate(min((int) $request->integer('per_page', 25), 100))
        );
    }
}
