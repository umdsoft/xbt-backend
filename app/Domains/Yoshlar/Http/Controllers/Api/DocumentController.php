<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Http\Controllers\Api;

use App\Domains\Yoshlar\Models\Document;
use App\Domains\Yoshlar\Services\DocumentService;
use App\Domains\Yoshlar\Support\YoshlarAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Hujjat/media (TZ 5.7).
 *
 * Fayllar PUBLIC DISKDA EMAS — yuklab olish faqat shu kontroller orqali,
 * doira tekshirilgandan keyin. Havolani bilish yetarli emas.
 */
class DocumentController extends Controller
{
    public function __construct(
        private readonly DocumentService $service,
        private readonly YoshlarAccess $access,
    ) {}

    public function index(Request $request, string $entityType, string $entityId): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'yoshlar.view'), 403, 'Ruxsat yoʻq.');

        return response()->json([
            'data' => $this->service->list($request->user(), $entityType, $entityId),
        ]);
    }

    public function store(Request $request, string $entityType, string $entityId): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'yoshlar.document.manage'), 403, 'Hujjat yuklash huquqi yoʻq.');

        $request->validate([
            'file' => ['required', 'file', 'max:25600'],
            'category' => ['nullable', Rule::in(Document::CATEGORIES)],
        ]);

        $document = $this->service->upload(
            $request->user(),
            $entityType,
            $entityId,
            $request->file('file'),
            ['category' => $request->input('category', 'boshqa')],
        );

        return response()->json(['data' => $document], 201);
    }

    public function download(Request $request, string $document): BinaryFileResponse
    {
        abort_unless($this->access->can($request->user(), 'yoshlar.view'), 403, 'Ruxsat yoʻq.');

        $model = Document::query()->findOrFail($document);
        ['path' => $path, 'name' => $name] = $this->service->download($request->user(), $model);

        return response()->download($path, $name);
    }

    public function destroy(Request $request, string $document): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'yoshlar.document.manage'), 403, 'Ruxsat yoʻq.');

        $model = Document::query()->findOrFail($document);
        $this->service->delete($request->user(), $model);

        return response()->json(['status' => 'ok']);
    }
}
