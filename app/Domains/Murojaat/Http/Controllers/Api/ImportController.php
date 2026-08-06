<?php

declare(strict_types=1);

namespace App\Domains\Murojaat\Http\Controllers\Api;

use App\Domains\Murojaat\Models\ImportSession;
use App\Domains\Murojaat\Services\ImportService;
use App\Domains\Murojaat\Support\MurojaatAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Excel import + import sessiyalari (arxiv). Client 44-ustunni maydonlarga bog'lab
 * xom qatorlar yuboradi; server normallashtiradi + saqlaydi.
 */
class ImportController extends Controller
{
    public function __construct(
        private readonly MurojaatAccess $access,
        private readonly ImportService $import,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'murojaat.import'), 403, 'Импорт рухсати йўқ.');
        $scope = $this->access->scopeFor($user);

        $v = $request->validate([
            'file_name' => ['nullable', 'string', 'max:500'],
            'district_id' => ['nullable', 'uuid'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*' => ['array'],
        ]);

        // Tuman: majburan o'z tumani; viloyat: so'rovdagi district_id (yoki null=umumiy).
        $districtId = $scope->seesAllDistricts() ? ($v['district_id'] ?? null) : $scope->districtId;

        $session = $this->import->import($v['rows'], $districtId, $v['file_name'] ?? 'import.xlsx', (string) $user->id);

        return response()->json([
            'ok' => true,
            'session_id' => $session->id,
            'count' => $session->records_count,
            'sayyor_count' => $session->sayyor_count,
        ], 201);
    }

    public function sessions(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'murojaat.view'), 403);
        $scope = $this->access->scopeFor($user);

        $rows = ImportSession::query()
            ->when(! $scope->seesAllDistricts(), fn ($q) => $q->where('district_id', $scope->districtId))
            ->orderByDesc('created_at')->limit(50)
            ->get(['id', 'district_id', 'file_name', 'records_count', 'sayyor_count', 'is_active', 'created_at']);

        return response()->json(['sessions' => $rows]);
    }

    public function activate(Request $request, ImportSession $session): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'murojaat.import'), 403);
        $scope = $this->access->scopeFor($user);
        if (! $scope->seesAllDistricts()) {
            abort_unless((string) $session->district_id === (string) $scope->districtId, 403, 'Бошқа туман сессияси.');
        }

        DB::connection('murojaat')->transaction(function () use ($session) {
            ImportSession::query()->where('district_id', $session->district_id)
                ->when($session->district_id === null, fn ($q) => $q->whereNull('district_id'))
                ->update(['is_active' => false]);
            $session->update(['is_active' => true]);
        });

        return response()->json(['ok' => true]);
    }
}
