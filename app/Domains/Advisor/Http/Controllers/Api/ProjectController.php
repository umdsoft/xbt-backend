<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Http\Controllers\Api;

use App\Domains\Advisor\Http\Controllers\Api\Concerns\StreamsFiles;
use App\Domains\Advisor\Models\Project;
use App\Domains\Advisor\Models\ProjectFile;
use App\Domains\Advisor\Services\ProjectService;
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
 * LOYIHALAR — ro'yxat/yaratish/ko'rish/tahrir + progress yangilanishi + fayl
 * (spec §6). Mahalla `micro_projects` naqshi advisor uchun.
 *
 * Qamrov (AdvisorAccess::scopeFor) HAR amalda:
 *   - viloyat: barcha tuman (yaratish/tahrir/o'chirish).
 *   - tuman:   FAQAT o'z tumani (yaratish/tahrir/yangilanish; o'chira olmaydi).
 *   - bo'linma: faqat ko'rish (projects.view; projects.manage YO'Q).
 */
class ProjectController extends Controller
{
    use StreamsFiles;

    public function __construct(
        private readonly AdvisorAccess $access,
        private readonly ProjectService $projects,
    ) {}

    /** Loyihalar ro'yxati (filtr + sahifalash). */
    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'district_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'string', 'in:planned,in_progress,done,paused'],
            'q' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $scope = $this->access->scopeFor($request->user());

        return response()->json($this->projects->listProjects($scope, $v, (int) ($v['page'] ?? 1)));
    }

    /** Yangi loyiha (viloyat istalgan tuman; tuman FAQAT o'z tumani). */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'projects.manage'), 403, 'Лойиҳа яратишга рухсат йўқ.');

        $v = $request->validate([
            'district_id' => ['required', 'uuid'],
            'category_id' => ['nullable', 'uuid'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'planned_start' => ['nullable', 'date'],
            'planned_end' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'in:planned,in_progress,done,paused'],
        ]);

        $scope = $this->access->scopeFor($user);

        // Tuman FAQAT o'z tumani uchun loyiha yaratadi (IDOR himoyasi).
        if ($scope->isTuman() && $v['district_id'] !== $scope->districtId) {
            abort(403, 'Бошқа туман учун лойиҳа яратиб бўлмайди.');
        }

        $id = $this->projects->createProject($v, (string) $user->id);

        return response()->json(['ok' => true, 'id' => $id], 201);
    }

    /** Bitta loyiha (progress tarixi + fayllar). */
    public function show(Request $request, string $project): JsonResponse
    {
        $scope = $this->access->scopeFor($request->user());
        $data = $this->projects->showProject($project, $scope);

        if ($data === null) {
            return response()->json(['message' => 'Лойиҳа топилмади'], 404);
        }

        return response()->json($data);
    }

    /** Tahrir (viloyat istalgan tuman; tuman FAQAT o'z tumani). */
    public function update(Request $request, string $project): JsonResponse
    {
        $scope = $this->access->scopeFor($request->user());
        $model = $this->findManageable($request, $project, $scope);

        $v = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'status' => ['sometimes', 'string', 'in:planned,in_progress,done,paused'],
            'progress_percent' => ['sometimes', 'integer', 'between:0,100'],
            'planned_end' => ['sometimes', 'nullable', 'date'],
            'actual_end' => ['sometimes', 'nullable', 'date'],
        ]);

        $this->projects->updateProject($model, $v);

        return response()->json(['ok' => true]);
    }

    /** Progress yangilanishi qo'shish (progress berilsa loyiha progressi yangilanadi). */
    public function addUpdate(Request $request, string $project): JsonResponse
    {
        $scope = $this->access->scopeFor($request->user());
        $model = $this->findManageable($request, $project, $scope);

        $v = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
            'progress_percent' => ['nullable', 'integer', 'between:0,100'],
        ]);

        $id = $this->projects->addUpdate(
            $model,
            (string) $request->user()->id,
            $v['body'],
            isset($v['progress_percent']) ? (int) $v['progress_percent'] : null,
        );

        return response()->json(['ok' => true, 'id' => $id], 201);
    }

    /** Fayl(lar) yuklash (multipart files[]). */
    public function uploadFiles(Request $request, string $project): JsonResponse
    {
        $scope = $this->access->scopeFor($request->user());
        $model = $this->findManageable($request, $project, $scope);

        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:10'],
            'files.*' => ['file', 'mimetypes:application/pdf,image/jpeg,image/png,image/webp', 'max:10240'],
        ]);

        $ids = $this->projects->uploadFiles($model, (string) $request->user()->id, $request->file('files') ?? []);

        return response()->json(['ok' => true, 'ids' => $ids], 201);
    }

    /**
     * Loyiha faylini maxfiy diskdan uzatish (URL orqali ochib bo'lmaydi; advisor
     * ReportController naqshi). Tuman FAQAT o'z tumani fayllarini ko'radi.
     */
    public function file(Request $request, string $file): StreamedResponse
    {
        $scope = $this->access->scopeFor($request->user());

        // Qamrov: tuman boshqa tuman faylini ko'ra olmasin (IDOR himoyasi).
        $allowed = DB::connection('advisor')->table('project_files as pf')
            ->join('projects as p', 'p.id', '=', 'pf.project_id')
            ->where('pf.id', $file)
            ->whereNull('p.deleted_at')
            ->when($scope->isTuman(), fn ($q) => $q->where('p.district_id', $scope->districtId))
            ->value('pf.id');

        if ($allowed === null) {
            throw new NotFoundHttpException;
        }

        $pf = ProjectFile::findOrFail($file);
        $disk = (string) config('advisor.files_disk', 'local');

        if ($pf->path === null || ! Storage::disk($disk)->exists($pf->path)) {
            throw new NotFoundHttpException;
        }

        return $this->streamFile($disk, $pf->path, $pf->original_name, $request);
    }

    /** Loyihani o'chirish (soft delete) — FAQAT viloyat. */
    public function destroy(Request $request, string $project): JsonResponse
    {
        $scope = $this->access->scopeFor($request->user());
        abort_unless($scope->isViloyat(), 403, 'Лойиҳани фақат вилоят маслаҳатчиси ўчиради.');

        $model = Project::find($project);
        if ($model === null) {
            throw new NotFoundHttpException('Лойиҳа топилмади');
        }

        $this->projects->deleteProject($model);

        return response()->json(['ok' => true]);
    }

    /**
     * Yozuv amali uchun loyihani topadi + vakolatni tekshiradi: projects.manage
     * (bo'linma YO'Q) + tuman FAQAT o'z tumani (IDOR himoyasi).
     */
    private function findManageable(Request $request, string $project, AdvisorScope $scope): Project
    {
        abort_unless($this->access->can($request->user(), 'projects.manage'), 403, 'Лойиҳани бошқаришга рухсат йўқ.');

        $model = Project::find($project);
        if ($model === null) {
            throw new NotFoundHttpException('Лойиҳа топилмади');
        }

        if ($scope->isTuman() && $model->district_id !== $scope->districtId) {
            abort(403, 'Бошқа туман лойиҳасини ўзгартириб бўлмайди.');
        }

        return $model;
    }
}
