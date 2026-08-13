<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Http\Controllers\Api;

use App\Domains\Qurilish\Models\ObjectDocument;
use App\Domains\Qurilish\Services\DocumentService;
use App\Domains\Qurilish\Services\ObjectService;
use App\Domains\Qurilish\Support\QurilishAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Obyekt hujjatlari.
 *
 * Yuklab olish HAR DOIM `ObjectService::findOrFail` dan o'tadi — ya'ni
 * hujjat havolasini bilgan begona foydalanuvchi ham 404 oladi. Fayl
 * to'g'ridan-to'g'ri veb-serverdan olinmaydi (disk `storage/app/private`).
 */
class DocumentController extends QurilishController
{
    public function __construct(
        QurilishAccess $access,
        private readonly ObjectService $objects,
        private readonly DocumentService $documents,
    ) {
        parent::__construct($access);
    }

    public function index(Request $request, string $objectId): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.view');

        $object = $this->objects->findOrFail($request->user(), $objectId);

        $rows = ObjectDocument::query()
            ->where('object_id', $object->id)
            ->orderByDesc('uploaded_at')
            ->get();

        return response()->json([
            'data' => $rows->map(fn (ObjectDocument $d) => [
                'id' => $d->id,
                'category' => $d->category,
                'stage_code' => $d->stage_code,
                'original_name' => $d->original_name,
                'mime' => $d->mime,
                'size' => $d->size,
                'version' => $d->version,
                'uploaded_at' => $d->uploaded_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    public function store(Request $request, string $objectId): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.document.manage');

        $object = $this->objects->findOrFail($request->user(), $objectId);

        $request->validate([
            'file' => ['required', 'file'],
            'category' => ['nullable', 'string'],
            'stage_code' => ['nullable', 'string'],
        ]);

        $document = $this->documents->upload(
            $object,
            $request->file('file'),
            $request->only(['category', 'stage_code']),
            $request->user(),
        );

        return response()->json([
            'data' => [
                'id' => $document->id,
                'original_name' => $document->original_name,
                'category' => $document->category,
                'version' => $document->version,
                'size' => $document->size,
            ],
        ], 201);
    }

    public function download(Request $request, string $objectId, string $documentId): StreamedResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.view');

        $object = $this->objects->findOrFail($request->user(), $objectId);

        $document = ObjectDocument::query()
            ->where('object_id', $object->id)->where('id', $documentId)->first();

        if ($document === null || ! Storage::disk($this->documents->disk())->exists($document->stored_path)) {
            abort(404, 'Ҳужжат топилмади.');
        }

        return Storage::disk($this->documents->disk())
            ->response($document->stored_path, $document->original_name);
    }

    public function destroy(Request $request, string $objectId, string $documentId): JsonResponse
    {
        $this->authorizeAction($request->user(), 'qurilish.document.manage');

        $object = $this->objects->findOrFail($request->user(), $objectId);

        $document = ObjectDocument::query()
            ->where('object_id', $object->id)->where('id', $documentId)->first();

        if ($document === null) {
            abort(404, 'Ҳужжат топилмади.');
        }

        $this->documents->delete($document, $object, $request->user());

        return response()->json(['deleted' => true]);
    }
}
