<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Controllers\Api;

use App\Domains\Hr\Models\ControlPlanItem;
use App\Domains\Hr\Models\ItemDocument;
use App\Domains\Hr\Services\ControlPlanAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ItemDocumentController extends HrController
{
    public function __construct(
        private ControlPlanAccessService $access,
    ) {}

    public function store(Request $request, string $itemId): JsonResponse
    {
        $item = ControlPlanItem::with('responsibles', 'plan')->findOrFail($itemId);

        abort_unless($this->access->canEditItem($this->actor(), $item), 403);

        $request->validate([
            // MIME allow-list — ихтиёрий тур (масалан .html/.svg) юклаб stored-XSS олдини олиш.
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $file = $request->file('file');
        // Махфий ҳужжатлар public дискда эмас — private (local) дискда сақланади,
        // фақат авторизацияланган download() маршрути орқали берилади.
        // Мустақил топшириқ (control_plan_id = null) учун алоҳида папка.
        $folder = $item->control_plan_id ? "control-plan-docs/{$item->control_plan_id}" : "task-docs/{$item->id}";
        $path = $file->store($folder, 'local');

        $document = ItemDocument::create([
            'control_plan_item_id' => $item->id,
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'description' => $request->input('description'),
            'uploaded_by' => $this->actor()->id,
        ]);

        return response()->json([
            'message' => 'Ҳужжат юкланди.',
            'document' => $document,
        ], 201);
    }

    public function download(string $id): BinaryFileResponse
    {
        $doc = ItemDocument::with('item.responsibles', 'item.plan')->findOrFail($id);

        // Tenant scope tufayli boshqa hokimlik hujjatida $doc->item null bo'lishi mumkin —
        // access-check'dan oldin 404 (TypeError/500 emas). Cross-tenant sizishni oldini oladi.
        abort_if($doc->item === null, 404);

        abort_unless($this->access->canViewItem($this->actor(), $doc->item), 403);

        return response()->download(
            Storage::disk('local')->path($doc->file_path),
            $doc->original_name,
        );
    }

    public function destroy(string $id): JsonResponse
    {
        $doc = ItemDocument::with('item.responsibles', 'item.plan')->findOrFail($id);

        // Tenant scope tufayli $doc->item null bo'lishi mumkin — 404 (500 emas).
        abort_if($doc->item === null, 404);

        abort_unless($this->access->canEditItem($this->actor(), $doc->item), 403);

        Storage::disk('local')->delete($doc->file_path);
        $doc->delete();

        return response()->json([
            'message' => 'Ҳужжат ўчирилди.',
        ]);
    }
}
