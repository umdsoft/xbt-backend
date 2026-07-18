<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Controllers\Api;

use App\Domains\Hr\Actions\YoshlarYetakchilari\CreateYyAction;
use App\Domains\Hr\Actions\YoshlarYetakchilari\UpdateYyAction;
use App\Domains\Hr\Http\Requests\YoshlarYetakchilari\StoreYyRequest;
use App\Domains\Hr\Http\Requests\YoshlarYetakchilari\UpdateYyRequest;
use App\Domains\Hr\Models\HrProfile;
use App\Domains\Hr\Models\Mahalla;
use App\Domains\Hr\Models\YoshlarYetakchisi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class YoshlarYetakchisiController extends HrController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', YoshlarYetakchisi::class);

        $list = YoshlarYetakchisi::with(['user', 'mahalla'])
            ->withCount('events')
            ->when($request->search, fn ($q, $s) => $q->whereLike('full_name_cyr', "%{$s}%"))
            ->when($request->is_active !== null, fn ($q) => $q->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)))
            ->orderByDesc('created_at')
            ->paginate(25);

        return response()->json([
            'items' => $list,
            'filters' => $request->only(['search', 'is_active']),
        ]);
    }

    public function create(): JsonResponse
    {
        $this->authorize('create', YoshlarYetakchisi::class);

        return response()->json($this->formData());
    }

    public function store(StoreYyRequest $request, CreateYyAction $action): JsonResponse
    {
        $this->authorize('create', YoshlarYetakchisi::class);

        $item = $action->execute($request->validated(), $this->actor()->id);

        return response()->json([
            'message' => 'Ёшлар етакчиси қўшилди.',
            'item' => $item,
        ], 201);
    }

    public function show(YoshlarYetakchisi $yoshlarYetakchi): JsonResponse
    {
        $this->authorize('view', $yoshlarYetakchi);

        $yoshlarYetakchi->load(['user', 'mahalla', 'creator', 'events.creator']);

        return response()->json(['item' => $yoshlarYetakchi]);
    }

    public function edit(YoshlarYetakchisi $yoshlarYetakchi): JsonResponse
    {
        $this->authorize('update', $yoshlarYetakchi);

        return response()->json([
            ...$this->formData(),
            'item' => $yoshlarYetakchi,
        ]);
    }

    public function update(
        UpdateYyRequest $request,
        YoshlarYetakchisi $yoshlarYetakchi,
        UpdateYyAction $action,
    ): JsonResponse {
        $this->authorize('update', $yoshlarYetakchi);

        $action->execute($yoshlarYetakchi, $request->validated());

        return response()->json([
            'message' => 'Маълумот янгиланди.',
            'item' => $yoshlarYetakchi,
        ]);
    }

    public function destroy(YoshlarYetakchisi $yoshlarYetakchi): JsonResponse
    {
        $this->authorize('delete', $yoshlarYetakchi);

        $yoshlarYetakchi->delete();

        return response()->json([
            'message' => 'Ёшлар етакчиси ўчирилди.',
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
