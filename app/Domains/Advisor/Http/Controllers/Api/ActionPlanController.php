<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Http\Controllers\Api;

use App\Domains\Advisor\Http\Controllers\Api\Concerns\StreamsFiles;
use App\Domains\Advisor\Models\ActionPlan;
use App\Domains\Advisor\Models\ActionPlanEntry;
use App\Domains\Advisor\Models\ActionPlanEntryFile;
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
 * CHORA-TADBIRLAR — reja/band CRUD + bajarilishi JURNALI (arxiv modeli).
 *
 * Qamrov: tuman FAQAT o'z rejasi + umumiy; viloyat hammasi. Egalik: viloyat umumiy
 * (district null) rejani; tuman O'Z rejasini boshqaradi. Bajarilishi: tuman ma'lumot
 * QO'SHADI (tasdiqlamaydi); viloyat monitoring/arxiv.
 */
class ActionPlanController extends Controller
{
    use StreamsFiles;

    /**
     * Qabul qilinadigan fayl turlari — pdf/rasm/Word/Excel (foydalanuvchi tanlovi).
     * `extensions` (kengaytma bo'yicha) ishlatiladi, `mimes` EMAS: real .docx/.xlsx
     * (aslida zip) ba'zi libmagic'da application/zip aniqlanib `mimes:docx`dan
     * o'tmaydi. Fayllar maxfiy diskda, faqat yuklab olinadi (bajarilmaydi).
     */
    private const FILE_RULES = ['file', 'extensions:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx', 'max:20480'];

    public function __construct(
        private readonly AdvisorAccess $access,
        private readonly ActionPlanService $plans,
    ) {}

    /** Rejalar ro'yxati (qamrovга qarab). */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'plan.view'), 403, 'Чора-тадбирларни кўришга рухсат йўқ.');

        return response()->json(['plans' => $this->plans->listPlans($this->access->scopeFor($user))]);
    }

    /** Chora-tadbir statistikasi (arxiv: kiritilган/jami). */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'plan.view'), 403, 'Чора-тадбирларни кўришга рухсат йўқ.');

        return response()->json($this->plans->stats($this->access->scopeFor($user)));
    }

    /**
     * Yangi reja: viloyat umumiy (hujjat majburiy); tuman O'Z rejasi (hujжат ихтиёрий).
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
            'document' => [$isTumanOwn ? 'nullable' : 'required', 'file', 'mimetypes:application/pdf,image/jpeg,image/png,image/webp', 'max:20480'],
        ], [], ['document' => 'тасдиқловчи ҳужжат']);

        $v['district_id'] = $isTumanOwn ? $scope->districtId : null;

        $plan = $this->plans->createPlan($v, (string) $user->id);
        if ($request->hasFile('document')) {
            $this->plans->storeDocument($plan, $request->file('document'));
        }

        return response()->json(['ok' => true, 'id' => $plan->id], 201);
    }

    /** Reja tafsiloti (bandlar + qamrovга qarab my_progress/summary). */
    public function show(Request $request, ActionPlan $plan): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'plan.view'), 403, 'Чора-тадбирларни кўришга рухсат йўқ.');

        $scope = $this->access->scopeFor($user);
        if ($scope->isTuman() && $plan->district_id !== null && $plan->district_id !== $scope->districtId) {
            abort(403, 'Бу режа бошқа туманники.');
        }

        return response()->json($this->plans->planOverview($plan, $scope));
    }

    /** Reja meta tahriri (title/year/status) — egаси (viloyat umumiy / tuman o'z). */
    public function update(Request $request, ActionPlan $plan): JsonResponse
    {
        $this->authorizePlan($request, $plan);

        $v = $request->validate([
            'title' => ['sometimes', 'string', 'max:500'],
            'year' => ['sometimes', 'integer', 'min:2020', 'max:2100'],
            'status' => ['sometimes', 'string', 'in:draft,active,closed'],
        ]);

        $this->plans->updatePlan($plan, $v);

        return response()->json(['ok' => true]);
    }

    /** Tasdiqlovchi hujjatni maxfiy diskdan uzatish. */
    public function document(Request $request, ActionPlan $plan): StreamedResponse
    {
        abort_unless($this->access->can($request->user(), 'plan.view'), 403);
        $disk = (string) config('advisor.files_disk', 'local');
        if ($plan->document_path === null || ! Storage::disk($disk)->exists($plan->document_path)) {
            throw new NotFoundHttpException('Ҳужжат топилмади');
        }

        return Storage::disk($disk)->response($plan->document_path, $plan->document_name);
    }

    // ------------------------------------------------------------- band CRUD

    /** Rejaga band qo'shish — egаси. */
    public function storeItem(Request $request, ActionPlan $plan): JsonResponse
    {
        $this->authorizePlan($request, $plan);

        $v = $this->validateItem($request);
        $id = $this->plans->addItem($plan, $v);

        return response()->json(['ok' => true, 'id' => $id], 201);
    }

    /** Band tahriri — egаси. */
    public function updateItem(Request $request, ActionPlanItem $item): JsonResponse
    {
        $this->authorizeItem($request, $item);

        $v = $request->validate([
            'section_title' => ['sometimes', 'string', 'max:255'],
            'item_number' => ['sometimes', 'string', 'max:16'],
            'title' => ['sometimes', 'string', 'max:2000'],
            'mechanism' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'deadline_text' => ['sometimes', 'nullable', 'string', 'max:255'],
            'deadline' => ['sometimes', 'nullable', 'date'],
            'responsible_text' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'steps' => ['sometimes', 'nullable', 'array', 'max:20'],
            'steps.*.text' => ['required_with:steps', 'string', 'max:2000'],
            'steps.*.deadline' => ['nullable', 'date'],
        ]);

        $this->plans->updateItem($item, $v);

        return response()->json(['ok' => true]);
    }

    /** Bandni o'chirish — egаси. */
    public function destroyItem(Request $request, ActionPlanItem $item): JsonResponse
    {
        $this->authorizeItem($request, $item);
        $this->plans->deleteItem($item);

        return response()->json(['ok' => true]);
    }

    // ------------------------------------------------------------- bajarilishi (jurnal)

    /** Band bo'yicha tuman kesimi (jurnal xulosalari). */
    public function showItem(Request $request, ActionPlanItem $item): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'plan.view'), 403, 'Чора-тадбирларни кўришга рухсат йўқ.');

        $scope = $this->access->scopeFor($user);
        if ($scope->isTuman() && ! $this->tumanMayAccessItem($item, $scope)) {
            abort(403, 'Бу режа бошқа туманники.');
        }

        $data = $this->plans->itemRows($item, $scope);

        return $data === null
            ? response()->json(['message' => 'Банд топилмади'], 404)
            : response()->json($data);
    }

    /** Bitta (band × tuman) to'liq JURNALI (arxiv). */
    public function archive(Request $request, ActionPlanItem $item): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'plan.view'), 403, 'Чора-тадбирларни кўришга рухсат йўқ.');

        $scope = $this->access->scopeFor($user);
        if ($scope->isTuman() && ! $this->tumanMayAccessItem($item, $scope)) {
            abort(403, 'Бу режа бошқа туманники.');
        }

        $v = $request->validate(['district_id' => ['nullable', 'uuid']]);
        $districtId = $scope->isTuman() ? $scope->districtId : ($v['district_id'] ?? null);

        return response()->json(['entries' => $this->plans->itemArchive($item, $districtId)]);
    }

    /** Jurnalga yangi yozuv (ma'lumot kiritish). Tuman: o'z tumani; viloyat: viloyat-band. */
    public function addEntry(Request $request, ActionPlanItem $item): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'plan.progress'), 403, 'Маълумот киритишга рухсат йўқ.');

        $scope = $this->access->scopeFor($user);
        if ($scope->isTuman() && ! $this->tumanMayAccessItem($item, $scope)) {
            abort(403, 'Бу режа бошқа туманники.');
        }

        // Ijrochi (rol) tekshiruvi FAYL validatsiyasidan OLDIN — noto'g'ri rol 403,
        // 422 emas. Tuman all_districts bandни (o'z tumani); viloyat viloyat-band (district null).
        if ($scope->isTuman()) {
            abort_if($item->scope === 'viloyat', 403, 'Бу вилоят даражасидаги топшириқ — туман киритмайди.');
            $districtId = $scope->districtId;
        } else {
            abort_if($item->scope !== 'viloyat', 403, 'Туман топшириғини вилоят киритмайди — мониторинг.');
            $districtId = null;
        }

        $v = $request->validate([
            'report' => ['required', 'string', 'max:10000'],
            'progress_percent' => ['nullable', 'integer', 'between:0,100'],
            'occurred_at' => ['nullable', 'date'],
            // Tasdiqlovchi fayl(lar) MAJBURIY — bajarilishi dalili (foydalanuvchi talabi).
            'files' => ['required', 'array', 'min:1', 'max:10'],
            'files.*' => self::FILE_RULES,
        ], [], ['files' => 'тасдиқловчи файл']);

        $id = $this->plans->addEntry(
            $item,
            $districtId,
            $v['report'],
            isset($v['progress_percent']) ? (int) $v['progress_percent'] : null,
            $v['occurred_at'] ?? null,
            (string) $user->id,
            $request->file('files') ?? [],
        );

        return response()->json(['ok' => true, 'id' => $id], 201);
    }

    /** Mavjud yozuvga qo'shimcha tasdiqlovchi fayl biriktirish — FAQAT egаси. */
    public function addEntryFiles(Request $request, ActionPlanEntry $entry): JsonResponse
    {
        abort_unless((string) $entry->created_by === (string) $request->user()->id, 403, 'Фақат ўзингиз киритган ёзувга файл қўшасиз.');

        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:10'],
            'files.*' => self::FILE_RULES,
        ], [], ['files' => 'тасдиқловчи файл']);

        $ids = $this->plans->storeEntryFiles($entry, $request->file('files') ?? [], (string) $request->user()->id);

        return response()->json(['ok' => true, 'ids' => $ids], 201);
    }

    /** Yozuv faylini o'chirish — FAQAT egаси. */
    public function destroyEntryFile(Request $request, ActionPlanEntryFile $file): JsonResponse
    {
        $entry = ActionPlanEntry::findOrFail($file->entry_id);
        abort_unless((string) $entry->created_by === (string) $request->user()->id, 403, 'Фақат ўзингиз киритган файлни ўчирасиз.');

        $this->plans->deleteEntryFile($file);

        return response()->json(['ok' => true]);
    }

    /**
     * Tasdiqlovchi faylni maxfiy diskdan uzatish (URL orqali ochib bo'lmaydi;
     * ReportController naqshi). Tuman FAQAT o'z tumani fayllarини ko'radi (IDOR).
     */
    public function entryFile(Request $request, ActionPlanEntryFile $file): StreamedResponse
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'plan.view'), 403, 'Кўришга рухсат йўқ.');

        $scope = $this->access->scopeFor($user);
        $entry = ActionPlanEntry::findOrFail($file->entry_id);

        // Qamrov: tuman boshqa tuman (yoki viloyat-band) faylini ko'ra olmasin.
        if ($scope->isTuman()) {
            abort_unless(
                $entry->district_id !== null && (string) $entry->district_id === (string) $scope->districtId,
                404,
            );
        }

        $disk = (string) config('advisor.files_disk', 'local');
        if ($file->path === null || ! Storage::disk($disk)->exists($file->path)) {
            throw new NotFoundHttpException;
        }

        return $this->streamFile($disk, $file->path, $file->original_name, $request);
    }

    /** Jurnal yozuvини tahrirlash — FAQAT egаси (kiritган). */
    public function updateEntry(Request $request, ActionPlanEntry $entry): JsonResponse
    {
        abort_unless((string) $entry->created_by === (string) $request->user()->id, 403, 'Фақат ўзингиз киритган ёзувни таҳрирлайсиз.');

        $v = $request->validate([
            'report' => ['sometimes', 'string', 'max:10000'],
            'progress_percent' => ['sometimes', 'nullable', 'integer', 'between:0,100'],
            'occurred_at' => ['sometimes', 'date'],
        ]);

        $this->plans->updateEntry($entry, $v);

        return response()->json(['ok' => true]);
    }

    /** Jurnal yozuvини o'chirish — FAQAT egаси. */
    public function destroyEntry(Request $request, ActionPlanEntry $entry): JsonResponse
    {
        abort_unless((string) $entry->created_by === (string) $request->user()->id, 403, 'Фақат ўзингиз киритган ёзувни ўчирасиз.');
        $this->plans->deleteEntry($entry);

        return response()->json(['ok' => true]);
    }

    // ----------------------------------------------------------------- yordamchi

    /** @return array<string, mixed> */
    private function validateItem(Request $request): array
    {
        return $request->validate([
            'section_title' => ['required', 'string', 'max:255'],
            'item_number' => ['required', 'string', 'max:16'],
            'title' => ['required', 'string', 'max:2000'],
            'mechanism' => ['nullable', 'string', 'max:5000'],
            'deadline_text' => ['nullable', 'string', 'max:255'],
            'deadline' => ['nullable', 'date'],
            'responsible_text' => ['nullable', 'string', 'max:2000'],
            'scope' => ['nullable', 'string', 'in:all_districts,viloyat'],
            // Mexanizm bosqichlari (har biri matn + kalendar sana).
            'steps' => ['nullable', 'array', 'max:20'],
            'steps.*.text' => ['required_with:steps', 'string', 'max:2000'],
            'steps.*.deadline' => ['nullable', 'date'],
        ]);
    }

    /** Rejani boshqarish huquqi: viloyat umumiy (null) yoki tuman O'Z rejasi. */
    private function authorizePlan(Request $request, ActionPlan $plan): void
    {
        $scope = $this->access->scopeFor($request->user());
        $ok = ($scope->isViloyat() && $plan->district_id === null)
            || ($scope->isTuman() && $plan->district_id === $scope->districtId);
        abort_unless($ok, 403, 'Бу режани бошқаришга рухсат йўқ.');
    }

    /** Band (tegishli reja) boshqarish huquqi. */
    private function authorizeItem(Request $request, ActionPlanItem $item): void
    {
        $scope = $this->access->scopeFor($request->user());
        $planDistrict = $this->itemPlanDistrict($item);
        $ok = ($scope->isViloyat() && $planDistrict === null)
            || ($scope->isTuman() && $planDistrict === $scope->districtId);
        abort_unless($ok, 403, 'Бу бандни бошқаришга рухсат йўқ.');
    }

    /** Tuman shu band rejасига kira oladimi (umumiy yoki o'z tumani). */
    private function tumanMayAccessItem(ActionPlanItem $item, AdvisorScope $scope): bool
    {
        $d = $this->itemPlanDistrict($item);

        return $d === null || $d === $scope->districtId;
    }

    private function itemPlanDistrict(ActionPlanItem $item): ?string
    {
        return DB::connection('advisor')->table('action_plans')->where('id', $item->plan_id)->value('district_id');
    }
}
