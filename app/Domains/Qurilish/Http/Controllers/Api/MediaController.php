<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Http\Controllers\Api;

use App\Domains\Qurilish\Models\ObjectMedia;
use App\Domains\Qurilish\Services\MediaService;
use App\Domains\Qurilish\Services\ObjectService;
use App\Domains\Qurilish\Support\QurilishAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Bosqich dalillari — surat va video. */
class MediaController extends QurilishController
{
    public function __construct(
        QurilishAccess $access,
        private readonly ObjectService $objects,
        private readonly MediaService $media,
    ) {
        parent::__construct($access);
    }

    public function index(Request $request, string $objectId): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.view');
        $object = $this->objects->findOrFail($request->user(), $objectId);

        $rows = ObjectMedia::query()
            ->where('object_id', $object->id)
            ->when($request->query('stage_code'), fn ($q, $c) => $q->where('stage_code', $c))
            ->when($request->query('weekly_report_id'), fn ($q, $c) => $q->where('weekly_report_id', $c))
            // Dalil vaqt bo'yicha o'qiladi: sanasi bor bo'lganlar avval,
            // sanasizlar oxirida (ular kamroq ishonchli).
            ->orderByRaw('taken_at asc nulls last')
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'data' => $rows->map(fn (ObjectMedia $m) => $this->present($m))->all(),
            'counts' => [
                'photo' => $rows->where('kind', 'photo')->count(),
                'video' => $rows->where('kind', 'video')->count(),
            ],
        ]);
    }

    public function store(Request $request, string $objectId): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.document.manage');
        $object = $this->objects->findOrFail($request->user(), $objectId);

        $request->validate([
            'file' => ['required', 'file'],
            'stage_code' => ['nullable', 'string', 'max:40'],
            'weekly_report_id' => ['nullable', 'uuid'],
            'title' => ['nullable', 'string', 'max:300'],
            'taken_at' => ['nullable', 'date', 'before_or_equal:today'],
        ], [
            'taken_at.before_or_equal' => 'Сурат олинган сана келажакда бўлиши мумкин эмас.',
        ]);

        $media = $this->media->upload(
            $object,
            $request->file('file'),
            $request->only(['stage_code', 'weekly_report_id', 'title', 'taken_at']),
            $request->user(),
        );

        return response()->json(['data' => $this->present($media)], 201);
    }

    public function setCover(Request $request, string $objectId, string $mediaId): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.document.manage');
        $object = $this->objects->findOrFail($request->user(), $objectId);

        $media = ObjectMedia::query()
            ->where('object_id', $object->id)->whereKey($mediaId)->firstOrFail();

        return response()->json(['data' => $this->present($this->media->setCover($media))]);
    }

    public function destroy(Request $request, string $objectId, string $mediaId): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.document.manage');
        $object = $this->objects->findOrFail($request->user(), $objectId);

        $media = ObjectMedia::query()
            ->where('object_id', $object->id)->whereKey($mediaId)->firstOrFail();

        $this->media->delete($media, $object, $request->user());

        return response()->json(['message' => 'Ўчирилди.']);
    }

    /**
     * Faylni beradi. Video uchun Range so'rovi MUHIM — usiz brauzer
     * butun faylni yuklab bo'lmaguncha o'ynatmaydi va oldinga o'tolmaydi.
     * `Storage::download()` Range ni qo'llab-quvvatlamaydi, shuning uchun
     * bu yerda `file()` ishlatiladi.
     */
    public function show(Request $request, string $objectId, string $mediaId): StreamedResponse|BinaryFileResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.view');
        $object = $this->objects->findOrFail($request->user(), $objectId);

        $media = ObjectMedia::query()
            ->where('object_id', $object->id)->whereKey($mediaId)->firstOrFail();

        $disk = Storage::disk($this->media->disk());

        if (! $disk->exists($media->stored_path)) {
            abort(404, 'Файл топилмади.');
        }

        return response()->file($disk->path($media->stored_path), [
            'Content-Type' => $media->mime,
            // `inline` — surat/video sahifada ochilsin, yuklab olinmasin.
            'Content-Disposition' => 'inline; filename="'.addslashes($media->original_name).'"',
            'Accept-Ranges' => 'bytes',
        ]);
    }

    /** @return array<string, mixed> */
    private function present(ObjectMedia $m): array
    {
        return [
            'id' => $m->id,
            'kind' => $m->kind,
            'stage_code' => $m->stage_code,
            'weekly_report_id' => $m->weekly_report_id,
            'title' => $m->title,
            'original_name' => $m->original_name,
            'mime' => $m->mime,
            'size' => $m->size,
            'width' => $m->width,
            'height' => $m->height,
            'duration_sec' => $m->duration_sec,
            'taken_at' => $m->taken_at?->toDateString(),
            'is_cover' => (bool) $m->is_cover,
            'created_at' => $m->created_at?->toIso8601String(),
            'url' => "/qurilish/objects/{$m->object_id}/media/{$m->id}/file",
        ];
    }
}
