<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Services;

use App\Domains\Qurilish\Models\ConstructionObject;
use App\Domains\Qurilish\Models\ObjectStage;
use App\Domains\Qurilish\Models\WeeklyReport;
use App\Domains\Qurilish\Support\QurilishAccess;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Haftalik ijro hisoboti — qurilish davom etayotgan obyekt uchun.
 *
 * NEGA HAFTALIK: ijro bosqichi oylab davom etadi va oylik grafik faqat pulni
 * ko'rsatadi. Nazorat organiga esa qurilishning O'ZI kerak: shu haftada nima
 * qilindi, nechta ishchi chiqdi, nima to'sqinlik qildi, dalil surati bormi.
 * Oy oxirida bularni eslab bo'lmaydi — shuning uchun hafta.
 *
 * Hisobot ham moderatsiyadan o'tadi: tasdiqlanmagan raqam jamlanmaga
 * kirmaydi, lekin arxivda «тасдиқланмаган» belgisi bilan qolaveradi —
 * o'chirilmaydi, chunki kim nima yozgani ham nazorat ma'lumoti.
 */
class WeeklyReportService
{
    /**
     * Hisobot faqat shu bosqichlarda yuritiladi.
     * Loyihalash bosqichida «shu hafta nechta ishchi chiqdi» degan savol yo'q.
     */
    private const ACTIVE_STAGES = ['execution', 'handover'];

    public function __construct(
        private readonly QurilishAccess $access,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Obyekt arxivi — barcha haftalar, yangisidan eskisiga.
     *
     * @return Collection<int, WeeklyReport>
     */
    public function archive(ConstructionObject $object, ?int $year = null): Collection
    {
        return WeeklyReport::query()
            ->where('object_id', $object->id)
            ->when($year !== null, fn ($q) => $q->where('year', $year))
            ->with(['media' => fn ($q) => $q->orderBy('taken_at')->orderBy('created_at')])
            ->orderByDesc('year')->orderByDesc('week_no')
            ->get();
    }

    /**
     * Hisobot yaratish yoki qoralamasini yangilash.
     *
     * `(obyekt, yil, hafta)` yagona — bir haftaga ikkinchi hisobot ochilmaydi,
     * mavjudi tahrirlanadi. Tasdiqlangan hisobot esa qulflanadi.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveDraft(ConstructionObject $object, array $data, User $user): WeeklyReport
    {
        $this->assertCanFill($user);
        $this->assertObjectInProgress($object);

        [$year, $week] = $this->resolveWeek($data);
        $period = $this->weekPeriod($year, $week);

        $report = WeeklyReport::query()
            ->where('object_id', $object->id)->where('year', $year)->where('week_no', $week)
            ->first();

        if ($report !== null && ! in_array($report->status, WeeklyReport::EDITABLE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => 'Бу ҳафта ҳисоботи тасдиққа юборилган ёки тасдиқланган — таҳрирланмайди.',
            ]);
        }

        $progress = $this->clampPct($data['progress_pct'] ?? $report?->progress_pct ?? 0);

        $values = [
            'object_id' => $object->id,
            'year' => $year,
            'week_no' => $week,
            'period_start' => $period['start'],
            'period_end' => $period['end'],
            'progress_pct' => $progress,
            // Haftalik o'sish HOSILA: oldingi tasdiqlangan hisobotdan farq.
            // Uni foydalanuvchi kiritmaydi — ikki raqam bir-biriga zid bo'lmasin.
            'week_progress_pct' => max(0, round($progress - $this->previousProgress($object, $year, $week), 2)),
            'disbursed_amount' => max(0, (float) ($data['disbursed_amount'] ?? $report?->disbursed_amount ?? 0)),
            'workers_count' => $data['workers_count'] ?? $report?->workers_count,
            'equipment_count' => $data['equipment_count'] ?? $report?->equipment_count,
            'works_done' => $data['works_done'] ?? $report?->works_done,
            'problems' => $data['problems'] ?? $report?->problems,
            'status' => 'qoralama',
            'rejection_reason' => null,
            'created_by' => $report?->created_by ?? $user->id,
        ];

        if ($report === null) {
            $report = WeeklyReport::query()->create($values);
            $this->audit->log($object, $user, 'weekly_create', "{$year}-W{$week}", null, 'qoralama');
        } else {
            $report->update($values);
        }

        return $report->refresh();
    }

    public function submit(WeeklyReport $report, ConstructionObject $object, User $user): WeeklyReport
    {
        $this->assertCanFill($user);

        if (! in_array($report->status, WeeklyReport::EDITABLE_STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Бу ҳисобот аллақачон юборилган.']);
        }

        if (trim((string) $report->works_done) === '') {
            // Bo'sh hisobotni tasdiqlashga yuborish moderator vaqtini yeydi.
            throw ValidationException::withMessages([
                'works_done' => 'Бажарилган ишларни ёзинг — бўш ҳисобот тасдиққа юборилмайди.',
            ]);
        }

        $report->update([
            'status' => 'tasdiqlash_kutilmoqda',
            'submitted_at' => now(),
            'submitted_by' => $user->id,
            'rejection_reason' => null,
        ]);

        $this->audit->log($object, $user, 'weekly_submit', $this->label($report), 'qoralama', 'tasdiqlash_kutilmoqda');

        return $report->refresh();
    }

    public function review(WeeklyReport $report, ConstructionObject $object, User $user): WeeklyReport
    {
        $this->assertCanModerate($user);

        if ($report->status !== 'tasdiqlash_kutilmoqda') {
            throw ValidationException::withMessages(['status' => 'Бу ҳисобот кўриб чиқишга тайёр эмас.']);
        }

        $report->update(['status' => 'korib_chiqilmoqda', 'reviewed_by' => $user->id]);
        $this->audit->log($object, $user, 'weekly_review', $this->label($report), 'tasdiqlash_kutilmoqda', 'korib_chiqilmoqda');

        return $report->refresh();
    }

    public function approve(WeeklyReport $report, ConstructionObject $object, User $user): WeeklyReport
    {
        $this->assertCanModerate($user);

        if (! in_array($report->status, WeeklyReport::PENDING_STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Фақат тасдиқ кутаётган ҳисобот тасдиқланади.']);
        }

        $report->update([
            'status' => 'tasdiqlangan',
            'reviewed_at' => now(),
            'reviewed_by' => $user->id,
            'rejection_reason' => null,
        ]);

        $this->audit->log($object, $user, 'weekly_approve', $this->label($report), null, 'tasdiqlangan');

        return $report->refresh();
    }

    public function reject(WeeklyReport $report, ConstructionObject $object, string $reason, User $user): WeeklyReport
    {
        $this->assertCanModerate($user);

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Рад этиш сабабини киритинг.']);
        }

        if (! in_array($report->status, WeeklyReport::PENDING_STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Фақат тасдиқ кутаётган ҳисобот рад этилади.']);
        }

        $report->update([
            'status' => 'rad_etilgan',
            'reviewed_at' => now(),
            'reviewed_by' => $user->id,
            'rejection_reason' => $reason,
        ]);

        $this->audit->log($object, $user, 'weekly_reject', $this->label($report), null, $reason);

        return $report->refresh();
    }

    /**
     * Kiritilmagan haftalar — «qaysi hafta o'tkazib yuborilgan».
     *
     * Ijro boshlangan haftadan bugungacha bo'lgan har hafta hisobot talab
     * qiladi; yo'qlari shu yerda ko'rinadi va nazoratning asosiy signali shu.
     *
     * @return array<int, array{year: int, week_no: int, period_start: string, period_end: string}>
     */
    public function missingWeeks(ConstructionObject $object, int $limit = 12): array
    {
        $start = $this->executionStart($object);
        if ($start === null) {
            return [];
        }

        $have = WeeklyReport::query()->where('object_id', $object->id)
            ->get(['year', 'week_no'])
            ->map(fn (WeeklyReport $r) => $r->year.'-'.$r->week_no)
            ->flip();

        $missing = [];
        $cursor = $start->startOfWeek();
        $now = CarbonImmutable::now()->startOfWeek();

        while ($cursor <= $now && count($missing) < $limit) {
            $year = (int) $cursor->isoWeekYear;
            $week = (int) $cursor->isoWeek;

            if (! $have->has($year.'-'.$week)) {
                $missing[] = [
                    'year' => $year,
                    'week_no' => $week,
                    'period_start' => $cursor->toDateString(),
                    'period_end' => $cursor->endOfWeek()->toDateString(),
                ];
            }

            $cursor = $cursor->addWeek();
        }

        return $missing;
    }

    /** Moderator navbati uchun: tasdiq kutayotgan hisobotlar soni. */
    public function pendingCount(): int
    {
        return WeeklyReport::query()->whereIn('status', WeeklyReport::PENDING_STATUSES)->count();
    }

    // ---------- ichki ----------

    /** @param array<string, mixed> $data @return array{0: int, 1: int} */
    private function resolveWeek(array $data): array
    {
        // Sana bo'yicha kiritish qulayroq: foydalanuvchi ISO hafta raqamini
        // bilishi shart emas, u shunchaki sanani tanlaydi.
        if (! empty($data['date'])) {
            $d = CarbonImmutable::parse((string) $data['date']);

            return [(int) $d->isoWeekYear, (int) $d->isoWeek];
        }

        $year = (int) ($data['year'] ?? CarbonImmutable::now()->isoWeekYear);
        $week = (int) ($data['week_no'] ?? CarbonImmutable::now()->isoWeek);

        if ($week < 1 || $week > 53) {
            throw ValidationException::withMessages(['week_no' => 'Ҳафта рақами 1–53 оралиғида бўлади.']);
        }

        return [$year, $week];
    }

    /** @return array{start: string, end: string} */
    private function weekPeriod(int $year, int $week): array
    {
        $start = CarbonImmutable::now()->setISODate($year, $week)->startOfWeek();

        return ['start' => $start->toDateString(), 'end' => $start->endOfWeek()->toDateString()];
    }

    /** Oldingi haftadagi umumiy bajarilish (hosila hisoblash uchun). */
    private function previousProgress(ConstructionObject $object, int $year, int $week): float
    {
        $prev = WeeklyReport::query()
            ->where('object_id', $object->id)
            ->where(fn ($q) => $q->where('year', '<', $year)
                ->orWhere(fn ($w) => $w->where('year', $year)->where('week_no', '<', $week)))
            ->orderByDesc('year')->orderByDesc('week_no')
            ->first();

        return (float) ($prev?->progress_pct ?? 0);
    }

    private function executionStart(ConstructionObject $object): ?CarbonImmutable
    {
        $stage = ObjectStage::query()
            ->where('object_id', $object->id)->where('stage_code', 'execution')->first();

        $date = $stage?->started_at ?? $stage?->created_at;

        return $date === null ? null : CarbonImmutable::parse($date);
    }

    private function assertObjectInProgress(ConstructionObject $object): void
    {
        $stage = $object->current_stage;

        if (! in_array($stage, self::ACTIVE_STAGES, true)) {
            throw ValidationException::withMessages([
                'object' => 'Ҳафталик ҳисобот фақат ижро ва топшириш босқичида юритилади. '
                    ."Жорий босқич: «{$stage}».",
            ]);
        }
    }

    private function assertCanFill(User $user): void
    {
        if (! $this->access->can($user, 'qurilish.stage.update')) {
            abort(403, 'Ҳафталик ҳисобот киритиш ҳуқуқи йўқ.');
        }
    }

    private function assertCanModerate(User $user): void
    {
        if (! $this->access->can($user, 'qurilish.stage.moderate')) {
            abort(403, 'Модерация ҳуқуқи йўқ.');
        }
    }

    private function clampPct(mixed $value): float
    {
        return max(0.0, min(100.0, round((float) $value, 2)));
    }

    private function label(WeeklyReport $report): string
    {
        return $report->year.'-W'.$report->week_no;
    }
}
