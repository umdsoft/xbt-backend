<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Http\Controllers\Api;

use App\Domains\Advisor\Models\TaskTarget;
use App\Domains\Advisor\Services\TaskService;
use App\Domains\Advisor\Support\AdvisorAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * TOPSHIRIQLAR — ro'yxat/yaratish/ko'rish + hisobot yuborish (spec §5).
 *
 * Qamrov (AdvisorAccess::scopeFor) HAR amalda: viloyat/bo'linma hammasini,
 * tuman FAQAT o'z tumanini ko'radi. Yaratish — faqat viloyat. Hisobot — tuman.
 */
class TaskController extends Controller
{
    public function __construct(
        private readonly AdvisorAccess $access,
        private readonly TaskService $tasks,
    ) {}

    /** Topshiriqlar ro'yxati (filtr + sahifalash). */
    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'status' => ['nullable', 'string', 'in:open,in_progress,closed'],
            'district_id' => ['nullable', 'uuid'],
            'category_id' => ['nullable', 'uuid'],
            'q' => ['nullable', 'string', 'max:200'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $scope = $this->access->scopeFor($request->user());

        return response()->json($this->tasks->listTasks($scope, $v, (int) ($v['page'] ?? 1)));
    }

    /** Yangi topshiriq (FAQAT viloyat). */
    public function store(Request $request): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'tasks.create'), 403, 'Топшириқ яратишга рухсат йўқ.');

        $v = $request->validate([
            'source' => ['nullable', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'expected_result' => ['nullable', 'string', 'max:10000'],
            'category_id' => ['nullable', 'uuid'],
            'priority' => ['nullable', 'string', 'in:low,normal,high'],
            'deadline' => ['nullable', 'date'],
            'district_ids' => ['required', 'array', 'min:1'],
            'district_ids.*' => ['uuid'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:60'],
        ]);

        $id = $this->tasks->createTask($v, (string) $request->user()->id);

        return response()->json(['ok' => true, 'id' => $id], 201);
    }

    /** Bitta topshiriq (nishonlar + hisobotlar). */
    public function show(Request $request, string $task): JsonResponse
    {
        $scope = $this->access->scopeFor($request->user());
        $data = $this->tasks->showTask($task, $scope);

        if ($data === null) {
            return response()->json(['message' => 'Топшириқ топилмади'], 404);
        }

        return response()->json($data);
    }

    /** Hisobot + dalil yuborish (tuman) — o'z tumanidagi nishonga. */
    public function report(Request $request, string $task): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'reports.submit'), 403, 'Ҳисобот юборишга рухсат йўқ.');

        $v = $request->validate([
            'target_id' => ['required', 'uuid'],
            'body' => ['nullable', 'string', 'max:10000'],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'mimetypes:application/pdf,image/jpeg,image/png,image/webp', 'max:10240'],
            'links' => ['nullable', 'array', 'max:20'],
            'links.*' => ['string', 'url', 'max:2000'],
            'gps' => ['nullable', 'array'],
            'gps.lat' => ['required_with:gps', 'numeric', 'between:-90,90'],
            'gps.lng' => ['required_with:gps', 'numeric', 'between:-180,180'],
            'gps.accuracy' => ['nullable', 'numeric', 'min:0'],
        ]);

        $scope = $this->access->scopeFor($user);

        $target = TaskTarget::query()
            ->where('id', $v['target_id'])
            ->where('task_id', $task)
            ->first();

        abort_if($target === null, 404, 'Нишон топилмади');

        // Tuman FAQAT o'z tumanidagi nishonga hisobot beradi (IDOR himoyasi).
        if ($scope->isTuman() && $target->district_id !== $scope->districtId) {
            abort(403, 'Бошқа туман нишонига ҳисобот бериб бўлмайди.');
        }

        $gps = $v['gps'] ?? null;
        if ($gps !== null) {
            $gps = [
                'lat' => (float) $gps['lat'],
                'lng' => (float) $gps['lng'],
                'accuracy' => isset($gps['accuracy']) ? (float) $gps['accuracy'] : null,
            ];
        }

        $reportId = $this->tasks->submitReport(
            $target,
            (string) $user->id,
            $scope->advisorId,
            $v['body'] ?? null,
            $request->file('files') ?? [],
            $v['links'] ?? [],
            $gps,
        );

        return response()->json(['ok' => true, 'id' => $reportId, 'status' => 'pending'], 201);
    }
}
