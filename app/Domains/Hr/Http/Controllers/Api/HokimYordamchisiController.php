<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Controllers\Api;

use App\Domains\Hr\Actions\HokimYordamchilari\CreateHyAction;
use App\Domains\Hr\Actions\HokimYordamchilari\UpdateHyAction;
use App\Domains\Hr\Http\Requests\HokimYordamchilari\StoreHyRequest;
use App\Domains\Hr\Http\Requests\HokimYordamchilari\UpdateHyRequest;
use App\Domains\Hr\Models\HokimYordamchisi;
use App\Domains\Hr\Models\HrProfile;
use App\Domains\Hr\Models\Mahalla;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HokimYordamchisiController extends HrController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', HokimYordamchisi::class);

        $list = HokimYordamchisi::with(['user', 'mahalla', 'creator'])
            ->withCount('assignments')
            ->when($request->search, fn ($q, $s) => $q->whereLike('full_name_cyr', "%{$s}%"))
            ->when($request->direction, fn ($q, $d) => $q->where('direction', $d))
            ->when($request->is_active !== null, fn ($q) => $q->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)))
            ->orderByDesc('created_at')
            ->paginate(25);

        return response()->json([
            'items' => $list,
            'filters' => $request->only(['search', 'direction', 'is_active']),
        ]);
    }

    public function create(): JsonResponse
    {
        $this->authorize('create', HokimYordamchisi::class);

        return response()->json($this->formData());
    }

    public function store(StoreHyRequest $request, CreateHyAction $action): JsonResponse
    {
        $this->authorize('create', HokimYordamchisi::class);

        $item = $action->execute($request->validated(), $this->actor()->id);

        return response()->json([
            'message' => 'Ҳоким ёрдамчиси қўшилди.',
            'item' => $item,
        ], 201);
    }

    public function show(HokimYordamchisi $hokimYordamchisi): JsonResponse
    {
        $this->authorize('view', $hokimYordamchisi);

        $hokimYordamchisi->load(['user', 'mahalla', 'creator', 'assignments.creator']);

        return response()->json([
            'item' => $hokimYordamchisi,
        ]);
    }

    public function edit(HokimYordamchisi $hokimYordamchisi): JsonResponse
    {
        $this->authorize('update', $hokimYordamchisi);

        return response()->json([
            ...$this->formData(),
            'item' => $hokimYordamchisi->load(['user', 'mahalla']),
        ]);
    }

    public function update(
        UpdateHyRequest $request,
        HokimYordamchisi $hokimYordamchisi,
        UpdateHyAction $action,
    ): JsonResponse {
        $this->authorize('update', $hokimYordamchisi);

        $action->execute($hokimYordamchisi, $request->validated());

        return response()->json([
            'message' => 'Маълумот янгиланди.',
            'item' => $hokimYordamchisi,
        ]);
    }

    public function destroy(HokimYordamchisi $hokimYordamchisi): JsonResponse
    {
        $this->authorize('delete', $hokimYordamchisi);

        $hokimYordamchisi->delete();

        return response()->json([
            'message' => 'Ҳоким ёрдамчиси ўчирилди.',
        ]);
    }

    /** @return array<string, mixed> */
    private function formData(): array
    {
        return [
            'users' => HrProfile::orderBy('name')->get(['id', 'name']),
            'mahallas' => Mahalla::orderBy('name_cyr')->get(['id', 'district_id', 'name_cyr']),
        ];
    }
}
