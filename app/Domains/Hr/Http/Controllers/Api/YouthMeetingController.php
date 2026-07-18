<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Controllers\Api;

use App\Domains\Hr\Http\Requests\Meetings\StoreMeetingRequest;
use App\Domains\Hr\Models\HrProfile;
use App\Domains\Hr\Models\Mahalla;
use App\Domains\Hr\Models\YouthMeeting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class YouthMeetingController extends HrController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', YouthMeeting::class);

        $meetings = YouthMeeting::with(['mahalla', 'chairman'])
            ->withCount('appeals')
            ->when($request->search, fn ($q, $s) => $q->whereLike('location', "%{$s}%"))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('meeting_date')
            ->paginate(25);

        return response()->json([
            'meetings' => $meetings,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    public function create(): JsonResponse
    {
        $this->authorize('create', YouthMeeting::class);

        return response()->json($this->formData());
    }

    public function store(StoreMeetingRequest $request): JsonResponse
    {
        $this->authorize('create', YouthMeeting::class);

        $meeting = YouthMeeting::create([
            ...$request->validated(),
            'created_by' => $this->actor()->id,
        ]);

        return response()->json([
            'message' => 'Учрашув яратилди.',
            'meeting' => $meeting,
        ], 201);
    }

    public function show(YouthMeeting $meeting): JsonResponse
    {
        $this->authorize('view', $meeting);

        $meeting->load(['mahalla', 'chairman', 'creator', 'appeals.category']);

        return response()->json(['meeting' => $meeting]);
    }

    public function edit(YouthMeeting $meeting): JsonResponse
    {
        $this->authorize('update', $meeting);

        return response()->json([
            ...$this->formData(),
            'meeting' => $meeting,
        ]);
    }

    public function update(StoreMeetingRequest $request, YouthMeeting $meeting): JsonResponse
    {
        $this->authorize('update', $meeting);

        $meeting->update($request->validated());

        return response()->json([
            'message' => 'Учрашув янгиланди.',
            'meeting' => $meeting,
        ]);
    }

    public function destroy(YouthMeeting $meeting): JsonResponse
    {
        $this->authorize('delete', $meeting);

        $meeting->delete();

        return response()->json([
            'message' => 'Учрашув ўчирилди.',
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
