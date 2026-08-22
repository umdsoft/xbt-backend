<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Http\Controllers\Api;

use App\Domains\Yoshlar\Services\EmploymentService;
use App\Domains\Yoshlar\Support\YoshlarAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmploymentController extends Controller
{
    public function __construct(
        private readonly EmploymentService $service,
        private readonly YoshlarAccess $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAction($request, 'yoshlar.employment.view');

        $page = $this->service->paginate($request->user(), $request->query());

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $this->authorizeAction($request, 'yoshlar.employment.view');

        return response()->json($this->service->stats($request->user()));
    }

    public function queue(Request $request): JsonResponse
    {
        $this->authorizeAction($request, 'yoshlar.employment.view');

        return response()->json(['data' => $this->service->reviewQueue($request->user())]);
    }

    public function show(Request $request, string $employment): JsonResponse
    {
        $this->authorizeAction($request, 'yoshlar.employment.view');

        $case = $this->service->find($request->user(), $employment);
        abort_if($case === null, 404, 'Ariza topilmadi.');

        return response()->json(['data' => $case]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAction($request, 'yoshlar.employment.create');

        $data = $request->validate([
            'youth_id' => ['required', 'uuid'],
            'employer_name' => ['required', 'string', 'max:500'],
            'employer_inn' => ['nullable', 'string', 'max:20'],
            'position' => ['nullable', 'string', 'max:300'],
            'salary' => ['nullable', 'numeric', 'min:0'],
            'start_date' => ['required', 'date'],
            'contract_number' => ['nullable', 'string', 'max:100'],
            'document_path' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json(['data' => $this->service->create($request->user(), $data)], 201);
    }

    /** Zanjir bosqichi: tuman soliq yoki viloyat soliq. */
    public function review(Request $request, string $employment): JsonResponse
    {
        $data = $request->validate([
            'approve' => ['required', 'boolean'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $case = $this->service->find($request->user(), $employment);
        abort_if($case === null, 404, 'Ariza topilmadi.');

        $stage = $case->nextStage();
        abort_if($stage === null, 422, 'Ariza yakunlangan yoki qaytarilgan.');

        // Qaytarish sababi MAJBURIY — ijrochi nimani tuzatishni bilishi kerak.
        if ($data['approve'] === false && trim((string) ($data['comment'] ?? '')) === '') {
            abort(422, 'Qaytarish sababini yozing.');
        }

        return response()->json([
            'data' => $this->service->review($request->user(), $case, $stage, (bool) $data['approve'], $data['comment'] ?? null),
        ]);
    }

    private function authorizeAction(Request $request, string $permission): void
    {
        abort_unless($this->access->can($request->user(), $permission), 403, 'Ruxsat yoʻq.');
    }
}
