<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Services;

use App\Domains\Yoshlar\Models\Organization;
use App\Domains\Yoshlar\Models\Task;
use App\Domains\Yoshlar\Models\TaskUpdate;
use App\Domains\Yoshlar\Support\YoshlarAccess;
use App\Domains\Yoshlar\Support\YoshlarScope;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Topshiriq ijrosi va tasdiqlash zanjiri.
 *
 * ZANJIR: ijrochi yuboradi -> `sector_review` -> sektor boshqarmasi
 * tasdiqlaydi -> `youth_review` -> yoshlar boshqarmasi yakunlaydi.
 * Har bosqichda qaytarish mumkin; qaytarilgan topshiriqni ijrochi tuzatib
 * qayta yuboradi.
 *
 * NEGA IJROCHINING O'ZI YOPA OLMAYDI: `progress = 100` yuborish topshiriqni
 * bajarilgan qilmaydi — u faqat DA'VO. Holat zanjir oxirida, boshqa
 * tashkilot tasdig'i bilan «tasdiqlandi» ga o'tadi.
 */
class TaskService
{
    public function __construct(
        private readonly YoshlarAccess $access,
        private readonly YoshlarScope $scope,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notify,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<Task>
     */
    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        $query = $this->scope->applyTask(Task::query()->with(['organization:id,name_lat,name_cyr,type', 'protocol:id,number,protocol_date']), $user);

        $this->applyFilters($query, $filters);

        $perPage = min((int) ($filters['per_page'] ?? 25), 200);

        return $query->orderBy('deadline')->paginate($perPage);
    }

    public function find(User $user, string $id): ?Task
    {
        return $this->scope->applyTask(Task::query()->with(['organization', 'protocol', 'updates']), $user)
            ->where('id', $id)
            ->first();
    }

    /** @param array<string, mixed> $data */
    public function create(User $user, array $data): Task
    {
        $data['created_by'] = $user->id;
        $data['status'] = 'belgilandi';
        $data['progress'] = 0;

        $task = Task::query()->create($data);

        $this->audit->log($user, 'task.create', 'task', $task->id, [
            'assigned_org_id' => $data['assigned_org_id'],
            'deadline' => $data['deadline'],
        ]);

        return $task;
    }

    /**
     * Ijro hisobotini yuborish. Ochiq yuborish mavjud bo'lsa — rad etiladi
     * (DB'da ham partial unique indeks bor; bu yerda tushunarli xato beramiz).
     *
     * @param  array<string, mixed>  $data
     */
    public function submitUpdate(User $user, Task $task, array $data): TaskUpdate
    {
        if ($task->openUpdate() !== null) {
            throw ValidationException::withMessages([
                'task' => 'Bu topshiriq boʻyicha tasdiq kutayotgan hisobot bor. Avval u koʻrib chiqilsin.',
            ]);
        }

        if ($task->status === 'tasdiqlandi') {
            throw ValidationException::withMessages([
                'task' => 'Topshiriq allaqachon tasdiqlangan.',
            ]);
        }

        $staff = $this->access->staffFor($user);

        $update = TaskUpdate::query()->create([
            'task_id' => $task->id,
            'progress' => (int) $data['progress'],
            'comment' => $data['comment'] ?? null,
            'file_path' => $data['file_path'] ?? null,
            'submitted_by' => $user->id,
            'submitted_org_id' => $staff?->org_id,
            'submitted_at' => now(),
            'review_stage' => 'sector_review',
        ]);

        $task->update(['status' => 'tasdiq_kutilmoqda']);

        $this->audit->log($user, 'task.submit', 'task', $task->id, ['progress' => $data['progress']]);

        // Zanjirdagi KEYINGI bo'g'inga xabar: sektor boshqarmasi (ijrochi
        // tashkilotning otasi). Xabarsiz hisobot navbatda unutilib qolardi.
        $parentOrgId = Organization::query()->whereKey($task->assigned_org_id)->value('parent_id');

        if ($parentOrgId !== null) {
            $this->notify->notifyOrganization((string) $parentOrgId, 'task.pending_review', [
                'title' => 'Ijro hisoboti tasdiq kutmoqda',
                'body' => $task->title,
                'link' => '/topshiriqlar/'.$task->id,
                'entity_type' => 'task',
                'entity_id' => $task->id,
            ]);
        }

        return $update;
    }

    /**
     * Zanjirning bir bosqichini o'tkazish.
     *
     * @param  'sector'|'youth'  $stage
     */
    public function review(User $user, TaskUpdate $update, string $stage, bool $approve, ?string $comment): TaskUpdate
    {
        $expected = $stage === 'sector' ? 'sector_review' : 'youth_review';

        if ($update->review_stage !== $expected) {
            throw ValidationException::withMessages([
                'stage' => 'Hisobot bu bosqichda emas — sahifani yangilang.',
            ]);
        }

        $task = $update->task;

        if (! $approve) {
            $update->update([
                'review_stage' => 'returned',
                'review_comment' => $comment,
                $stage === 'sector' ? 'sector_reviewed_by' : 'youth_reviewed_by' => $user->id,
                $stage === 'sector' ? 'sector_reviewed_at' : 'youth_reviewed_at' => now(),
            ]);

            $task->update(['status' => 'qaytarildi']);
            $this->audit->log($user, "task.return.{$stage}", 'task', $task->id, ['reason' => $comment]);

            $this->notify->notifyOrganization($task->assigned_org_id, 'task.returned', [
                'title' => 'Hisobot qayta ishlashga qaytarildi',
                'body' => $task->title.' — '.($comment ?? ''),
                'link' => '/topshiriqlar/'.$task->id,
                'entity_type' => 'task',
                'entity_id' => $task->id,
            ]);

            return $update->refresh();
        }

        if ($stage === 'sector') {
            $update->update([
                'review_stage' => 'youth_review',
                'sector_reviewed_by' => $user->id,
                'sector_reviewed_at' => now(),
                'review_comment' => $comment,
            ]);

            $this->audit->log($user, 'task.approve.sector', 'task', $task->id);

            // Yakuniy tasdiq viloyat yoshlar boshqarmasida — o'sha rolga xabar.
            $this->notify->notifyRole('yoshlar_boshqarma', 'task.pending_review', [
                'title' => 'Yakuniy tasdiq kutilmoqda',
                'body' => $task->title,
                'link' => '/topshiriqlar/'.$task->id,
                'entity_type' => 'task',
                'entity_id' => $task->id,
            ]);

            return $update->refresh();
        }

        // Yakuniy tasdiq: topshiriq holati va foizi shu yerda yangilanadi —
        // ijrochi da'vosi emas, tasdiqlangan natija hisobga olinadi.
        $update->update([
            'review_stage' => 'approved',
            'youth_reviewed_by' => $user->id,
            'youth_reviewed_at' => now(),
            'review_comment' => $comment,
        ]);

        $task->update([
            'progress' => $update->progress,
            'status' => $update->progress >= 100 ? 'tasdiqlandi' : 'ijroda',
        ]);

        $this->audit->log($user, 'task.approve.youth', 'task', $task->id, ['progress' => $update->progress]);

        return $update->refresh();
    }

    /**
     * Tasdiqlash navbati: foydalanuvchi qaysi bosqichda ishlaydi, shu
     * bosqichdagi hisobotlar.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, TaskUpdate>
     */
    public function reviewQueue(User $user): \Illuminate\Database\Eloquent\Collection
    {
        $stage = match (true) {
            $this->access->can($user, 'yoshlar.task.review.sector') => 'sector_review',
            $this->access->can($user, 'yoshlar.task.review.youth') => 'youth_review',
            default => null,
        };

        if ($stage === null) {
            return TaskUpdate::query()->whereRaw('1 = 0')->get();
        }

        $visibleTasks = $this->scope->applyTask(Task::query()->select('id'), $user);

        return TaskUpdate::query()
            ->with('task.organization:id,name_lat,name_cyr')
            ->where('review_stage', $stage)
            ->whereIn('task_id', $visibleTasks)
            ->orderBy('submitted_at')
            ->get();
    }

    /** Rahbariyat paneli uchun jamlanma. @return array<string, mixed> */
    public function stats(User $user): array
    {
        $base = fn () => $this->scope->applyTask(Task::query(), $user);

        $total = $base()->count();
        $done = $base()->where('status', 'tasdiqlandi')->count();
        $overdue = $base()->overdue()->count();
        $pending = $base()->where('status', 'tasdiq_kutilmoqda')->count();

        return [
            'total' => $total,
            'done' => $done,
            'overdue' => $overdue,
            'pending_review' => $pending,
            // O'z vaqtida ijro foizi — rahbariyatning asosiy KPI'si.
            'on_time_rate' => $total === 0 ? 0 : (int) round((($total - $overdue) / $total) * 100),
            'by_status' => $base()->selectRaw('status, count(*) as total')
                ->groupBy('status')->pluck('total', 'status'),
        ];
    }

    /**
     * @param  Builder<Task>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        foreach (['status', 'priority', 'district_id', 'assigned_org_id', 'protocol_id'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (! empty($filters['search'])) {
            $query->where('title', 'ilike', '%'.$filters['search'].'%');
        }

        // Svetofor bo'yicha filtr — sana oralig'iga aylantiriladi (indeks ishlaydi).
        $state = (string) ($filters['deadline_state'] ?? '');
        $today = now()->startOfDay();

        if ($state === 'red') {
            $query->where('status', '!=', 'tasdiqlandi')->whereDate('deadline', '<', $today->toDateString());
        } elseif ($state === 'amber') {
            $query->where('status', '!=', 'tasdiqlandi')
                ->whereDate('deadline', '>=', $today->toDateString())
                ->whereDate('deadline', '<', $today->copy()->addDays(Task::AMBER_DAYS)->toDateString());
        } elseif ($state === 'green') {
            $query->where('status', '!=', 'tasdiqlandi')
                ->whereDate('deadline', '>=', $today->copy()->addDays(Task::AMBER_DAYS)->toDateString());
        }
    }
}
