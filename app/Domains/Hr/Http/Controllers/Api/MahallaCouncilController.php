<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Controllers\Api;

use App\Domains\Hr\Models\HrProfile;
use App\Domains\Hr\Models\Mahalla;
use App\Domains\Hr\Models\MahallaCouncil;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MahallaCouncilController extends HrController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MahallaCouncil::class);

        $councils = MahallaCouncil::with(['mahalla', 'members'])
            ->withCount('members')
            ->when($request->search, fn ($q, $s) => $q->whereHas('mahalla', fn ($mq) => $mq->whereLike('name_cyr', "%{$s}%")))
            ->orderBy('mahalla_id')
            ->paginate(25);

        return response()->json([
            'councils' => $councils,
            'filters' => $request->only('search'),
        ]);
    }

    public function create(): JsonResponse
    {
        $this->authorize('create', MahallaCouncil::class);

        return response()->json($this->formData());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', MahallaCouncil::class);

        $validated = $request->validate([
            'mahalla_id' => ['required', 'string', 'exists:mahallas,id', 'unique:mahalla_councils,mahalla_id'],
            'name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'is_active' => ['boolean'],
            'members' => ['nullable', 'array'],
            'members.*.user_id' => ['nullable', 'string', 'exists:users,id'],
            'members.*.full_name' => ['required', 'string', 'max:255'],
            'members.*.role' => ['required', Rule::in(['rais', 'imom', 'yoshlar', 'ayollar', 'posbon', 'maktab', 'soliq', 'boshqa'])],
            'members.*.phone' => ['nullable', 'string', 'max:20'],
        ]);

        $council = DB::transaction(function () use ($validated) {
            $council = MahallaCouncil::create([
                'mahalla_id' => $validated['mahalla_id'],
                'name' => $validated['name'] ?? 'Маҳалла еттилиги',
                'phone' => $validated['phone'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
            ]);

            foreach (($validated['members'] ?? []) as $member) {
                $council->members()->create($member + ['is_active' => true]);
            }

            return $council;
        });

        return response()->json([
            'message' => 'Маҳалла еттилиги яратилди.',
            'council' => $council,
        ], 201);
    }

    public function show(MahallaCouncil $council): JsonResponse
    {
        $this->authorize('view', $council);

        $council->load(['mahalla', 'members.user']);

        return response()->json(['council' => $council]);
    }

    public function edit(MahallaCouncil $council): JsonResponse
    {
        $this->authorize('update', $council);

        $council->load(['mahalla', 'members']);

        return response()->json([
            ...$this->formData(),
            'council' => $council,
        ]);
    }

    public function update(Request $request, MahallaCouncil $council): JsonResponse
    {
        $this->authorize('update', $council);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'is_active' => ['boolean'],
            'members' => ['nullable', 'array'],
            'members.*.id' => ['nullable', 'string', 'exists:council_members,id'],
            'members.*.user_id' => ['nullable', 'string', 'exists:users,id'],
            'members.*.full_name' => ['required', 'string', 'max:255'],
            'members.*.role' => ['required', Rule::in(['rais', 'imom', 'yoshlar', 'ayollar', 'posbon', 'maktab', 'soliq', 'boshqa'])],
            'members.*.phone' => ['nullable', 'string', 'max:20'],
        ]);

        DB::transaction(function () use ($council, $validated) {
            $council->update([
                'name' => $validated['name'] ?? $council->name,
                'phone' => $validated['phone'] ?? null,
                'is_active' => $validated['is_active'] ?? $council->is_active,
            ]);

            // A'zolarni qayta yaratish (oddiy approach — keyinchalik diff bilan optimize)
            $council->members()->delete();
            foreach (($validated['members'] ?? []) as $member) {
                unset($member['id']);
                $council->members()->create($member + ['is_active' => true]);
            }
        });

        return response()->json([
            'message' => 'Маълумот янгиланди.',
            'council' => $council,
        ]);
    }

    public function destroy(MahallaCouncil $council): JsonResponse
    {
        $this->authorize('delete', $council);

        $council->delete();

        return response()->json([
            'message' => 'Маҳалла еттилиги ўчирилди.',
        ]);
    }

    /** @return array<string, mixed> */
    private function formData(): array
    {
        return [
            'mahallas' => Mahalla::orderBy('name_cyr')->get(['id', 'district_id', 'name_cyr']),
            'users' => HrProfile::orderBy('name')->get(['id', 'name']),
        ];
    }
}
