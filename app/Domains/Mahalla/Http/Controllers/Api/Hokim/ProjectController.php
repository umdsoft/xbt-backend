<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Controllers\Api\Hokim;

use App\Domains\Mahalla\Services\ExecutiveStats;
use App\Domains\Mahalla\Services\MicroProjectService;
use App\Domains\Mahalla\Services\RaisCadastre;
use App\Domains\Mahalla\Http\Controllers\Api\MahallaPanelController;
use App\Domains\Mahalla\Support\MahallaAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ҳоким ёрдамчиси панели — микролойиҳалар.
 *
 * Qamrov (`mahallaId`) foydalanuvchi profilidan. So'rovdan mahalla qabul
 * qilinmaydi. Har amal (list/show/update/file) mahallani o'zi tekshiradi.
 */
class ProjectController extends MahallaPanelController
{
    public function __construct(
        MahallaAccess $access,
        private readonly MicroProjectService $projects,
        private readonly RaisCadastre $cadastre,
        private readonly ExecutiveStats $stats,
    ) {
        parent::__construct($access);
    }

    /** Mahalla konteksti: ko'rsatkichlar, xarita, loyiha holatlari. */
    public function overview(Request $request): JsonResponse
    {
        $mahallaId = $this->mahallaId($request);
        if ($mahallaId === null) {
            return $this->noMahalla();
        }

        $m = DB::connection('master')->table('mahallas as m')
            ->leftJoin('districts as d', 'd.id', '=', 'm.district_id')
            ->where('m.id', $mahallaId)
            ->first(['m.id', 'm.name_cyr', 'd.id as district_id', 'd.name_cyr as district_name']);

        $data = $this->stats->mahalla($mahallaId);

        return response()->json([
            'mahalla' => [
                'id' => $m?->id,
                'name' => $m?->name_cyr,
                'district' => ['id' => $m?->district_id, 'name' => $m?->district_name],
            ],
            'households' => $data['households'],
            'indicators' => $data['indicators'],
            'social_objects' => $data['social_objects'],
            'boundary' => $this->cadastre->boundary($mahallaId),
            'social_points' => $this->cadastre->socialPoints($mahallaId),
            'project_counts' => $this->projects->statusCounts($mahallaId),
            'categories' => DB::connection('master')->table('project_categories')
                ->where('is_active', true)->orderBy('sort_order')
                ->get(['id', 'code', 'name_cyr as name']),
        ]);
    }

    /** Loyihalar ro'yxati — sahifalab. */
    public function index(Request $request): JsonResponse
    {
        $mahallaId = $this->mahallaId($request);
        if ($mahallaId === null) {
            return $this->noMahalla();
        }

        $v = $request->validate([
            'status' => ['nullable', 'string', 'in:planned,in_progress,done,cancelled'],
            'category' => ['nullable', 'uuid'],
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json($this->projects->list(
            $mahallaId,
            $v['status'] ?? null,
            $v['category'] ?? null,
            $v['q'] ?? null,
            (int) ($v['page'] ?? 1),
        ));
    }

    /** Bitta loyiha. */
    public function show(Request $request, string $project): JsonResponse
    {
        $mahallaId = $this->mahallaId($request);
        if ($mahallaId === null) {
            return $this->noMahalla();
        }

        $data = $this->projects->show($project, $mahallaId);
        if ($data === null) {
            return response()->json(['message' => 'Лойиҳа топилмади'], 404);
        }

        return response()->json($data);
    }

    /** Yangi loyiha. */
    public function store(Request $request): JsonResponse
    {
        $mahallaId = $this->mahallaId($request);
        if ($mahallaId === null) {
            return $this->noMahalla();
        }

        $v = $this->validated($request, required: true);
        $districtId = DB::connection('master')->table('mahallas')->where('id', $mahallaId)->value('district_id');

        $id = $this->projects->create($mahallaId, (string) $districtId, $v, (string) $request->user()->id);

        return response()->json(['ok' => true, 'id' => $id], 201);
    }

    /** Loyihani tahrirlaydi. */
    public function update(Request $request, string $project): JsonResponse
    {
        $mahallaId = $this->mahallaId($request);
        if ($mahallaId === null) {
            return $this->noMahalla();
        }

        $v = $this->validated($request, required: false);

        if (! $this->projects->update($project, $mahallaId, $v)) {
            return response()->json(['message' => 'Лойиҳа топилмади'], 404);
        }

        return response()->json(['ok' => true]);
    }

    /** Jarayon yozuvi. */
    public function addUpdate(Request $request, string $project): JsonResponse
    {
        $mahallaId = $this->mahallaId($request);
        if ($mahallaId === null) {
            return $this->noMahalla();
        }

        $v = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'progress_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $ok = $this->projects->addUpdate(
            $project, $mahallaId, $v['body'], $v['progress_percent'] ?? null, (string) $request->user()->id,
        );

        if (! $ok) {
            return response()->json(['message' => 'Лойиҳа топилмади'], 404);
        }

        return response()->json(['ok' => true], 201);
    }

    /** Fayl biriktirish. */
    public function uploadFile(Request $request, string $project): JsonResponse
    {
        $mahallaId = $this->mahallaId($request);
        if ($mahallaId === null) {
            return $this->noMahalla();
        }

        $request->validate([
            // Rasm yoki PDF — jarayon dalili.
            'file' => ['required', 'file', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:10240'],
        ]);

        if (! $this->projects->attachFile($project, $mahallaId, $request->file('file'), (string) $request->user()->id)) {
            return response()->json(['message' => 'Лойиҳа топилмади'], 404);
        }

        return response()->json(['ok' => true], 201);
    }

    /** Faylni maxfiy diskdan uzatadi. */
    public function downloadFile(Request $request, string $file): StreamedResponse
    {
        $mahallaId = $this->mahallaId($request);
        abort_if($mahallaId === null, 409);

        $meta = $this->projects->fileFor($file, $mahallaId);
        abort_if($meta === null, 404, 'Файл топилмади');
        abort_unless(Storage::disk($meta['disk'])->exists($meta['path']), 404);

        return Storage::disk($meta['disk'])->download($meta['path'], $meta['name']);
    }

    public function destroy(Request $request, string $project): JsonResponse
    {
        $mahallaId = $this->mahallaId($request);
        if ($mahallaId === null) {
            return $this->noMahalla();
        }

        if (! $this->projects->delete($project, $mahallaId)) {
            return response()->json(['message' => 'Лойиҳа топилмади'], 404);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $required): array
    {
        return $request->validate([
            'title' => [$required ? 'required' : 'sometimes', 'string', 'max:255'],
            'category_id' => ['nullable', 'uuid'],
            'description' => ['nullable', 'string', 'max:5000'],
            'planned_start' => ['nullable', 'date'],
            'planned_end' => ['nullable', 'date'],
            'actual_end' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'in:planned,in_progress,done,cancelled'],
            'progress_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'street_id' => ['nullable', 'uuid'],
            'object_building_id' => ['nullable', 'uuid'],
        ]);
    }

}
