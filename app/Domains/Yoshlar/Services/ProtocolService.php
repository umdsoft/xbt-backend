<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Services;

use App\Domains\Yoshlar\Models\Protocol;
use App\Domains\Yoshlar\Models\Task;
use App\Domains\Yoshlar\Support\YoshlarScope;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * RASMIY HUJJAT KOʻRINISHI.
 *
 * Bu servis topshiriqlarni «roʻyxat» sifatida emas, HUJJAT sifatida
 * qaytaradi: boʻlimlarga ajratilgan, qogʻozdagi tartibda, har bandda
 * hujjatning oʻz ustunlari. Naqsh — `advisor.ActionPlanService::planOverview()`.
 *
 * NEGA ALOHIDA SERVIS: `TaskService` ijro bilan shugʻullanadi (kim yubordi,
 * kim tasdiqladi, muddat oʻtdimi). Hujjat koʻrinishi esa butunlay boshqa
 * savolga javob beradi — «bayonnomaning 6-bandi qay ahvolda?». Ikkalasini
 * bitta sinfga tiqish uni ikki xoʻjayinga xizmat qiladigan qilardi.
 */
class ProtocolService
{
    public function __construct(private readonly YoshlarScope $scope) {}

    /**
     * Hujjatlar roʻyxati — har biri boʻyicha ijro tallysi bilan.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function list(User $user, array $filters = []): array
    {
        $protocols = Protocol::query()
            ->when(
                isset($filters['type']) && $filters['type'] !== '',
                fn ($q) => $q->where('type', $filters['type']),
            )
            ->when(
                isset($filters['year']) && $filters['year'] !== '',
                fn ($q) => $q->where('year', (int) $filters['year']),
            )
            ->orderByDesc('protocol_date')
            ->get();

        if ($protocols->isEmpty()) {
            return [];
        }

        // Bandlar BITTA soʻrovda — har hujjat uchun alohida soʻrov N+1 boʻlardi
        // va 30 ta hujjatli roʻyxat 31 ta soʻrovga aylanardi.
        $tasks = $this->scope
            ->applyTask(Task::query(), $user)
            ->whereIn('protocol_id', $protocols->pluck('id'))
            ->get(['id', 'protocol_id', 'status', 'deadline', 'target_value', 'target_done']);

        $byProtocol = $tasks->groupBy('protocol_id');

        return $protocols
            ->map(fn (Protocol $p) => $this->present($p) + [
                'stats' => $this->tally($byProtocol->get($p->id) ?? collect()),
            ])
            ->all();
    }

    /**
     * Bitta hujjat — boʻlimlar va bandlar bilan, qogʻozdagi tartibda.
     *
     * @return array{document: array<string,mixed>, sections: array<int, array{title: string|null, items: array<int, array<string,mixed>>}>, stats: array<string,int>}
     */
    public function overview(User $user, Protocol $protocol): array
    {
        $tasks = $this->scope
            ->applyTask(Task::query(), $user)
            ->with([
                'organization:id,name_lat,name_cyr',
                'coExecutors:id,name_lat,name_cyr',
                'applicant:id,last_name,first_name,middle_name',
            ])
            ->where('protocol_id', $protocol->id)
            ->orderBy('sort_order')
            ->orderBy('item_number')
            ->get();

        return [
            'document' => $this->present($protocol),
            'sections' => $this->groupIntoSections($tasks),
            'stats' => $this->tally($tasks),
        ];
    }

    /**
     * Bandlarni boʻlimlarga ajratadi.
     *
     * Boʻlim sarlavhasi KELISH TARTIBIDA olinadi, alifbo boʻyicha EMAS:
     * «I. Kadrlar» boʻlimi «II. Moliya» dan oldin turishi kerak, alifbo
     * esa ularni aralashtirib yuborardi.
     *
     * @param  Collection<int, Task>  $tasks
     * @return array<int, array{title: string|null, items: array<int, array<string,mixed>>}>
     */
    private function groupIntoSections(Collection $tasks): array
    {
        $sections = [];
        $index = [];

        foreach ($tasks as $task) {
            $title = $task->section_title;
            $key = $title ?? '';

            if (! array_key_exists($key, $index)) {
                $index[$key] = count($sections);
                $sections[] = ['title' => $title, 'items' => []];
            }

            $sections[$index[$key]]['items'][] = $this->presentItem($task);
        }

        return $sections;
    }

    /** @return array<string, mixed> */
    private function presentItem(Task $task): array
    {
        return [
            'id' => $task->id,
            'item_number' => $task->item_number,
            'title' => $task->title,
            'description' => $task->description,
            'mechanism' => $task->mechanism,
            'steps' => $task->steps ?? [],
            'deadline' => $task->deadline?->toDateString(),
            'deadline_text' => $task->deadline_text,
            'deadline_state' => $task->deadline_state,
            'days_left' => $task->days_left,
            'responsible_text' => $task->responsible_text,
            'organization' => $task->organization?->only(['id', 'name_lat', 'name_cyr']),
            'co_executors' => $task->coExecutors->map->only(['id', 'name_lat', 'name_cyr'])->all(),
            'applicant' => $task->applicant_label,
            'applicant_youth_id' => $task->applicant_youth_id,
            'status' => $task->status,
            'progress' => $task->progress,
            'priority' => $task->priority,
            'target_value' => $task->target_value,
            'target_unit' => $task->target_unit,
            'target_done' => $task->target_done,
            'target_percent' => $task->target_percent,
        ];
    }

    /** @return array<string, mixed> */
    private function present(Protocol $protocol): array
    {
        return [
            'id' => $protocol->id,
            'type' => $protocol->type,
            'number' => $protocol->number,
            'protocol_date' => $protocol->protocol_date?->toDateString(),
            'year' => $protocol->year,
            'topic' => $protocol->topic,
            'event_title' => $protocol->event_title,
            'description' => $protocol->description,
            'issued_by' => $protocol->issued_by,
            'file_name' => $protocol->file_name,
            'has_file' => $protocol->file_path !== null,
            'shows_applicant' => $protocol->showsApplicant(),
        ];
    }

    /**
     * Hujjat boʻyicha ijro tallysi.
     *
     * `total` — foydalanuvchi KOʻRA OLADIGAN bandlar soni, hujjatdagi
     * jami emas: tuman xodimi oʻz tumaniga tegishli bandlarnigina
     * koʻradi va unga «13 banddan 2 tasi bajarildi» deyish notoʻgʻri
     * taassurot berardi.
     *
     * @param  Collection<int, Task>  $tasks
     * @return array<string, int>
     */
    private function tally(Collection $tasks): array
    {
        $today = Carbon::today();

        $done = 0;
        $overdue = 0;
        $inReview = 0;
        $targetValue = 0;
        $targetDone = 0;

        foreach ($tasks as $task) {
            if ($task->status === 'tasdiqlandi') {
                $done++;
            } elseif ($task->deadline !== null && Carbon::parse($task->deadline)->lt($today)) {
                $overdue++;
            }

            if ($task->status === 'tasdiq_kutilmoqda') {
                $inReview++;
            }

            if ($task->target_value !== null) {
                $targetValue += $task->target_value;
                $targetDone += $task->target_done;
            }
        }

        $total = $tasks->count();

        return [
            'total' => $total,
            'done' => $done,
            'overdue' => $overdue,
            'in_review' => $inReview,
            'done_percent' => $total === 0 ? 0 : (int) round(($done / $total) * 100),

            // Maqsad boʻlmagan hujjatda 0/0 — ekran buni «maqsad yoʻq» deb
            // talqin qiladi va ustunni koʻrsatmaydi.
            'target_value' => $targetValue,
            'target_done' => $targetDone,
        ];
    }
}
