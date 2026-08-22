<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Http\Controllers\Api;

use App\Domains\Yoshlar\Models\EmploymentCase;
use App\Domains\Yoshlar\Models\Task;
use App\Domains\Yoshlar\Models\Youth;
use App\Domains\Yoshlar\Models\YouthCase;
use App\Domains\Yoshlar\Services\CaseService;
use App\Domains\Yoshlar\Services\TaskService;
use App\Domains\Yoshlar\Support\YoshlarAccess;
use App\Domains\Yoshlar\Support\YoshlarScope;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * F5 — rahbariyat paneli: barcha modul KPI'lari bitta soʻrovda.
 *
 * NEGA BITTA SOʻROV: rahbariyat sahifasi 5 ta modul kesimini koʻrsatadi;
 * har biri alohida soʻrov boʻlsa, sahifa 5 marta kutardi va tuman
 * jadvalini birlashtirish frontendga tushardi.
 *
 * Barcha sonlar foydalanuvchi DOIRASI ichida hisoblanadi — hokim
 * oʻrinbosari viloyatni, tuman boʻlimi oʻz tumanini koʻradi.
 */
class ExecutiveController extends Controller
{
    public function __construct(
        private readonly YoshlarAccess $access,
        private readonly YoshlarScope $scope,
        private readonly TaskService $tasks,
        private readonly CaseService $cases,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'yoshlar.view'), 403, 'Ruxsat yoʻq.');

        $user = $request->user();
        $districtIds = $this->scope->districtIds($user);

        $youthBase = fn () => $this->scope->applyYouth(Youth::query(), $user)
            ->where('registry_status', 'active')
            ->where('verification_status', 'verified');

        $employmentBase = function () use ($districtIds) {
            $q = EmploymentCase::query();
            if ($districtIds === []) {
                return $q->whereRaw('1 = 0');
            }

            return $districtIds === null ? $q : $q->whereIn('district_id', $districtIds);
        };

        $total = $youthBase()->count();
        $neet = $youthBase()->where('is_neet', true)->count();

        return response()->json([
            'registry' => [
                'total' => $total,
                'neet' => $neet,
                'neet_rate' => $total === 0 ? 0 : (int) round(($neet / $total) * 100),
                'in_patronage' => $youthBase()->where('in_patronage', true)->count(),
                'employed' => $youthBase()->where('employment_status', 'band')->count(),
                'graduates_unemployed' => $youthBase()->where('is_graduate_unemployed', true)->count(),
            ],
            'tasks' => $this->tasks->stats($user),
            'cases' => $this->cases->caseStats($user),
            'patronage' => $this->cases->patronageStats($user),
            'employment' => [
                'confirmed' => $employmentBase()->where('status', EmploymentCase::STATUS_CONFIRMED)->count(),
                'in_review' => $employmentBase()->whereIn('status', EmploymentCase::OPEN_STATUSES)->count(),
            ],
            // Tuman kesimi: rahbariyat qaysi tuman orqada qolayotganini
            // bitta jadvalda koʻradi.
            'by_district' => $this->byDistrict($user, $districtIds),
        ]);
    }

    /**
     * @param  array<int, string>|null  $districtIds
     * @return array<int, array<string, mixed>>
     */
    private function byDistrict(\App\Models\User $user, ?array $districtIds): array
    {
        $districts = DB::connection('master')->table('districts')
            ->when($districtIds !== null, fn ($q) => $q->whereIn('id', $districtIds ?? []))
            ->orderBy('sort_order')
            ->get(['id', 'name_lat', 'name_cyr']);

        $youthCounts = $this->scope->applyYouth(Youth::query(), $user)
            ->where('registry_status', 'active')->where('verification_status', 'verified')
            ->selectRaw('district_id, count(*) as total')->groupBy('district_id')->pluck('total', 'district_id');

        $neetCounts = $this->scope->applyYouth(Youth::query(), $user)
            ->where('registry_status', 'active')->where('verification_status', 'verified')
            ->where('is_neet', true)
            ->selectRaw('district_id, count(*) as total')->groupBy('district_id')->pluck('total', 'district_id');

        $overdueTasks = $this->scope->applyTask(Task::query(), $user)->overdue()
            ->selectRaw('district_id, count(*) as total')->groupBy('district_id')->pluck('total', 'district_id');

        $openCases = YouthCase::query()->open()
            ->when($districtIds !== null, fn ($q) => $q->whereIn('district_id', $districtIds ?? []))
            ->selectRaw('district_id, count(*) as total')->groupBy('district_id')->pluck('total', 'district_id');

        $employed = EmploymentCase::query()->where('status', EmploymentCase::STATUS_CONFIRMED)
            ->when($districtIds !== null, fn ($q) => $q->whereIn('district_id', $districtIds ?? []))
            ->selectRaw('district_id, count(*) as total')->groupBy('district_id')->pluck('total', 'district_id');

        return $districts->map(fn ($d): array => [
            'id' => $d->id,
            'name_lat' => $d->name_lat,
            'name_cyr' => $d->name_cyr,
            'youth' => (int) ($youthCounts[$d->id] ?? 0),
            'neet' => (int) ($neetCounts[$d->id] ?? 0),
            'overdue_tasks' => (int) ($overdueTasks[$d->id] ?? 0),
            'open_cases' => (int) ($openCases[$d->id] ?? 0),
            'employed' => (int) ($employed[$d->id] ?? 0),
        ])->all();
    }
}
