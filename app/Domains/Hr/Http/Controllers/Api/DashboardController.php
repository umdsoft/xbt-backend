<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Controllers\Api;

use App\Domains\Hr\Enums\ReviewStatus;
use App\Domains\Hr\Enums\TaskSource;
use App\Domains\Hr\Models\Activity;
use App\Domains\Hr\Models\ControlPlan;
use App\Domains\Hr\Models\ControlPlanItem;
use App\Domains\Hr\Models\Department;
use App\Domains\Hr\Models\Employee;
use App\Domains\Hr\Models\HrProfile;
use App\Domains\Hr\Models\Organization;
use App\Domains\Hr\Support\ActivityTranslator;
use App\Domains\Hr\Support\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardController extends HrController
{
    public function __invoke(TenantContext $context): JsonResponse
    {
        $user = $this->actor();

        // Global rejim — har tuman/shahar uchun statistika (super-admin / viloyat-admin)
        if ($context->isGlobal()) {
            return response()->json([
                'mode' => 'cross-tenant',
                'tenants_stats' => $this->crossTenantStats(),
                'recent_activity' => $this->recentActivity(),
            ]);
        }

        // Tashkilot foydalanuvchisi — o'ziga kelgan topshiriqlar
        if ($user->organization_id !== null) {
            return response()->json([
                'mode' => 'organization',
                'org' => $this->orgStats($user),
            ]);
        }

        // Kotibyat mudiri — rahbariyat paneli (chart/grafiklar bilan)
        if ($user->hasRole('kotibyat-mudiri') && ! $user->hasRole('tuman-admin')) {
            return response()->json(array_merge(
                ['mode' => 'kotibyat'],
                $this->kotibyatStats($user),
            ));
        }

        // Per-tenant rejim — joriy tenant statistikasi
        return response()->json([
            'mode' => 'tenant',
            'stats' => $this->tenantStats(),
            'control_plan_stats' => $this->controlPlanStats(),
            'recent_activity' => $this->recentActivity(),
        ]);
    }

    /**
     * Kotibyat mudiri (rahbariyat) dashboardi: muddati yaqin, bajarilmagan, tashkilot kesimi.
     *
     * @return array<string, mixed>
     */
    private function kotibyatStats(HrProfile $user): array
    {
        $today = today();
        $soon = today()->addDays(7);

        $base = fn () => ControlPlanItem::query()
            ->where(fn ($q) => $q->where('created_by', $user->id)->orWhere('kompleks_id', $user->department_id));

        $kpi = [
            'tasks_total' => $base()->count(),
            'under_control' => $base()->whereNull('control_removed_at')->count(),
            'completed' => $base()->where('execution_status', 'completed')->count(),
            'overdue' => $base()->whereNull('control_removed_at')
                ->where('execution_status', '!=', 'completed')
                ->whereNotNull('deadline')->whereDate('deadline', '<', $today)->count(),
            'pending' => $base()->whereNull('control_removed_at')
                ->whereIn('execution_status', ['not_started', 'in_progress'])->count(),
            'awaiting' => $base()->whereNull('control_removed_at')
                ->where('review_status', ReviewStatus::SUBMITTED->value)->count(),
            'plans_active' => ControlPlan::where('status', 'active')->count(),
            'organizations' => Organization::where('is_active', true)->count(),
        ];

        $dueSoon = $base()->whereNull('control_removed_at')
            ->where('execution_status', '!=', 'completed')
            ->whereNotNull('deadline')->whereBetween('deadline', [$today, $soon])
            ->with('responsibles')->orderBy('deadline')->take(8)->get()
            ->map(fn ($t) => $this->presentDashTask($t, $today))->all();

        $pending = $base()->whereNull('control_removed_at')
            ->whereIn('execution_status', ['not_started', 'in_progress'])
            ->with('responsibles')->orderBy('deadline')->take(8)->get()
            ->map(fn ($t) => $this->presentDashTask($t, $today))->all();

        // Donut chart — топшириқлар ҳолати (бир-бирини қопламайдиган тоифалар)
        $inProgressActive = $base()->whereNull('control_removed_at')
            ->where('execution_status', 'in_progress')
            ->where(fn ($q) => $q->whereNull('deadline')->orWhereDate('deadline', '>=', $today))->count();
        $notStartedActive = $base()->whereNull('control_removed_at')
            ->where('execution_status', 'not_started')
            ->where(fn ($q) => $q->whereNull('deadline')->orWhereDate('deadline', '>=', $today))->count();

        $statusChart = [
            ['label' => 'Бажарилган', 'value' => $kpi['completed'], 'color' => '#16a34a'],
            ['label' => 'Бажарилмоқда', 'value' => $inProgressActive, 'color' => '#2563eb'],
            ['label' => 'Бажарилмаган', 'value' => $notStartedActive, 'color' => '#94a3b8'],
            ['label' => 'Муддати ўтган', 'value' => $kpi['overdue'], 'color' => '#dc2626'],
        ];

        // Tasdiqlash kutilayotgan ijrolar (tashkilot yuborgan) — EDO inbox
        $awaiting = $base()->whereNull('control_removed_at')
            ->where('review_status', ReviewStatus::SUBMITTED->value)
            ->with('responsibles')->orderBy('submitted_at')->take(10)->get()
            ->map(function ($t) use ($today) {
                $row = $this->presentDashTask($t, $today);
                $row['submitted_at'] = $t->submitted_at?->format('d.m.Y H:i');

                return $row;
            })->all();

        return [
            'kpi' => $kpi,
            'status_chart' => $statusChart,
            'awaiting_approval' => $awaiting,
            'due_soon' => $dueSoon,
            'pending_tasks' => $pending,
            'by_organization' => $this->tasksByOrganization($user, $today),
            'calendar_tasks' => $this->calendarTasks($base()),
        ];
    }

    /**
     * Taqvim uchun: deadline'i bor topshiriqlar (sana bo'yicha).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<ControlPlanItem>  $base
     * @return array<int, array<string, mixed>>
     */
    private function calendarTasks($base): array
    {
        // Кўринадиган ойна билан чегаралаймиз (M1) — жадвал ошган сари чексиз ўсиб кетмаслиги учун.
        return $base->whereNull('control_removed_at')
            ->whereNotNull('deadline')
            ->whereBetween('deadline', [today()->subMonth()->toDateString(), today()->addMonths(2)->toDateString()])
            ->orderBy('deadline')
            ->limit(200)
            ->get(['id', 'title', 'task_description', 'deadline', 'execution_status', 'review_status'])
            ->map(fn (ControlPlanItem $t) => [
                'id' => $t->id,
                'title' => $t->title ?? $t->task_description,
                'date' => $t->deadline?->format('Y-m-d'),
                'status' => $t->execution_status,
            ])->all();
    }

    /**
     * Tashkilot admini dashboardi — o'ziga kelgan topshiriqlar.
     *
     * @return array<string, mixed>
     */
    private function orgStats(HrProfile $user): array
    {
        $today = today();
        $orgId = $user->organization_id;

        $base = fn () => ControlPlanItem::query()
            ->whereHas('responsibles', fn ($q) => $q
                ->where('assignee_type', 'organization')->where('assignee_id', $orgId));

        return [
            'name' => $user->organization?->name_cyr,
            'kpi' => [
                'total' => $base()->count(),
                'pending' => $base()->whereNull('control_removed_at')
                    ->whereIn('execution_status', ['not_started', 'in_progress'])->count(),
                'completed' => $base()->where('execution_status', 'completed')->count(),
                'overdue' => $base()->whereNull('control_removed_at')
                    ->where('execution_status', '!=', 'completed')
                    ->whereNotNull('deadline')->whereDate('deadline', '<', $today)->count(),
            ],
            'due_soon' => $base()->whereNull('control_removed_at')
                ->where('execution_status', '!=', 'completed')
                ->whereNotNull('deadline')->whereBetween('deadline', [$today, today()->addDays(7)])
                ->with('responsibles')->orderBy('deadline')->take(8)->get()
                ->map(fn ($t) => $this->presentDashTask($t, $today))->all(),
            'calendar_tasks' => $this->calendarTasks($base()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDashTask(ControlPlanItem $t, $today): array
    {
        $primary = $t->responsibles->firstWhere('is_primary', true) ?? $t->responsibles->first();

        return [
            'id' => $t->id,
            'title' => $t->title ?? $t->task_description,
            'assignee' => $primary?->displayName(),
            'deadline' => $t->deadline?->format('d.m.Y'),
            'days_left' => $t->deadline
                ? (int) round(($t->deadline->copy()->startOfDay()->timestamp - $today->timestamp) / 86400)
                : null,
            'status' => $t->execution_status,
            'source' => $t->source instanceof TaskSource ? $t->source->value : $t->source,
        ];
    }

    /**
     * Tashkilotlar kesimida topshiriq statistikasi.
     *
     * @return array<int, array<string, mixed>>
     */
    private function tasksByOrganization(HrProfile $user, $today): array
    {
        $orgs = Organization::query()
            ->where(fn ($q) => $q->where('created_by', $user->id)->orWhere('kompleks_id', $user->department_id))
            ->orderBy('name_cyr')->get(['id', 'name_cyr']);

        if ($orgs->isEmpty()) {
            return [];
        }

        $todayStr = $today->toDateString();

        // Bitta guruhlangan query (single-quote — MySQL+sqlite mos)
        $rows = DB::connection('hr')->table('item_responsibles as ir')
            ->join('control_plan_items as ci', 'ir.control_plan_item_id', '=', 'ci.id')
            ->where('ir.assignee_type', 'organization')
            ->whereIn('ir.assignee_id', $orgs->pluck('id'))
            ->selectRaw("ir.assignee_id as org_id,
                COUNT(*) as total,
                SUM(CASE WHEN ci.execution_status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN ci.execution_status IN ('not_started','in_progress') AND ci.control_removed_at IS NULL THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN ci.deadline < ? AND ci.execution_status != 'completed' AND ci.control_removed_at IS NULL THEN 1 ELSE 0 END) as overdue", [$todayStr])
            ->groupBy('ir.assignee_id')
            ->get()->keyBy('org_id');

        return $orgs->map(fn ($o) => [
            'id' => $o->id,
            'name' => $o->name_cyr,
            'total' => (int) ($rows[$o->id]->total ?? 0),
            'completed' => (int) ($rows[$o->id]->completed ?? 0),
            'pending' => (int) ($rows[$o->id]->pending ?? 0),
            'overdue' => (int) ($rows[$o->id]->overdue ?? 0),
        ])->all();
    }

    /** @return array<string, int> */
    private function tenantStats(): array
    {
        return [
            'total_employees' => Employee::count(),
            'archived_employees' => Employee::onlyTrashed()->count(),
            'control_plans' => ControlPlan::count(),
            'active_plans' => ControlPlan::where('status', 'active')->count(),
        ];
    }

    /** @return array<string, int> */
    private function controlPlanStats(): array
    {
        // ControlPlanItem'ga global scope qo'llanmagan, lekin u plan'ga bog'langan
        // Tenant filter — plan orqali
        return [
            'total_items' => ControlPlanItem::query()
                ->whereHas('plan')
                ->count(),
            'completed' => ControlPlanItem::query()
                ->whereHas('plan')
                ->where('execution_status', 'completed')
                ->count(),
            'in_progress' => ControlPlanItem::query()
                ->whereHas('plan')
                ->where('execution_status', 'in_progress')
                ->count(),
            'overdue' => ControlPlanItem::query()
                ->whereHas('plan')
                ->where('execution_status', 'overdue')
                ->count(),
        ];
    }

    /**
     * Har tenant bo'yicha statistika (super-admin uchun).
     * 3 ta aggregation query — N+1 ga aylanmaydi.
     *
     * @return array<int, array<string, mixed>>
     */
    private function crossTenantStats(): array
    {
        $tenants = Department::query()
            ->tenants()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name_cyr', 'type']);

        // Bitta query — barcha tenantlar bo'yicha employee count
        $employeeCounts = Employee::withoutTenantScope()
            ->whereNotNull('hokimlik_id')
            ->selectRaw('hokimlik_id, COUNT(*) as cnt')
            ->groupBy('hokimlik_id')
            ->pluck('cnt', 'hokimlik_id');

        // Bitta query — control plans count
        $planCounts = ControlPlan::withoutTenantScope()
            ->whereNotNull('hokimlik_id')
            ->selectRaw('hokimlik_id, COUNT(*) as cnt')
            ->groupBy('hokimlik_id')
            ->pluck('cnt', 'hokimlik_id');

        // Bitta query — overdue items per tenant (plan.hokimlik_id orqali JOIN)
        $overdueCounts = ControlPlanItem::query()
            ->join('control_plans', 'control_plan_items.control_plan_id', '=', 'control_plans.id')
            ->whereNotNull('control_plans.hokimlik_id')
            ->where('control_plan_items.execution_status', 'overdue')
            ->selectRaw('control_plans.hokimlik_id as h_id, COUNT(*) as cnt')
            ->groupBy('control_plans.hokimlik_id')
            ->pluck('cnt', 'h_id');

        return $tenants->map(fn (Department $t) => [
            'id' => $t->id,
            'name_cyr' => $t->name_cyr,
            'type' => $t->type,
            'employees' => (int) ($employeeCounts[$t->id] ?? 0),
            'control_plans' => (int) ($planCounts[$t->id] ?? 0),
            'overdue_items' => (int) ($overdueCounts[$t->id] ?? 0),
        ])->values()->all();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function recentActivity()
    {
        return Activity::with('causer')
            ->latest()
            ->take(10)
            ->get()
            ->map(fn (Activity $a) => [
                'id' => $a->id,
                'description' => ActivityTranslator::event($a->description),
                'subject_type' => ActivityTranslator::subject(class_basename((string) $a->subject_type)),
                'causer' => $a->causer?->name ?? 'Тизим',
                'created_at' => $a->created_at?->format('d.m.Y H:i'),
            ]);
    }
}
