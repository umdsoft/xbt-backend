<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Services;

use App\Domains\Yoshlar\Models\Patronage;
use App\Domains\Yoshlar\Models\PatronageLog;
use App\Domains\Yoshlar\Models\Staff;
use App\Domains\Yoshlar\Models\Youth;
use App\Domains\Yoshlar\Models\YouthCase;
use App\Domains\Yoshlar\Support\YoshlarAccess;
use App\Domains\Yoshlar\Support\YoshlarScope;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * F4 — muammolar (case) va otaliq.
 *
 * Ikkalasi bitta servisda, chunki ular bir-biriga ulanadi: otaliq jurnalida
 * «hal etilgan muammo» yozuvi aynan `youth_cases` ga havola qiladi. Ularni
 * ajratsak, bu bogʻlanish ikki servis orasida osilib qolardi.
 */
class CaseService
{
    /** SLA muddati kiritilmasa — toifaga qarab default (kun). */
    private const DEFAULT_SLA_DAYS = [
        'sogliq' => 3,
        'psixologik' => 3,
        'huquqiy' => 7,
        'oilaviy' => 7,
        'bandlik' => 14,
        'talim' => 14,
        'moliyaviy' => 14,
        'uy_joy' => 30,
    ];

    public function __construct(
        private readonly YoshlarAccess $access,
        private readonly YoshlarScope $scope,
        private readonly AuditLogger $audit,
    ) {}

    // ---------------- Muammolar ----------------

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<YouthCase>
     */
    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        $query = $this->visibleCases($user)
            ->with('youth:id,last_name,first_name,middle_name,birth_date,district_id,mahalla_id');

        foreach (['status', 'category', 'district_id', 'youth_id'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (! empty($filters['search'])) {
            $query->where('title', 'ilike', '%'.$filters['search'].'%');
        }

        if (($filters['only_open'] ?? '') === '1') {
            $query->open();
        }

        return $query->orderByRaw('sla_deadline nulls last')->paginate(min((int) ($filters['per_page'] ?? 25), 200));
    }

    public function findCase(User $user, string $id): ?YouthCase
    {
        return $this->visibleCases($user)->with('youth')->where('id', $id)->first();
    }

    /** @param array<string, mixed> $data */
    public function createCase(User $user, array $data): YouthCase
    {
        $youth = Youth::query()->findOrFail($data['youth_id']);

        abort_unless(
            $this->scope->canTouchDistrict($user, $youth->district_id),
            403,
            'Bu yosh sizning doirangizda emas.',
        );

        $staff = $this->access->staffFor($user);

        $slaDays = self::DEFAULT_SLA_DAYS[$data['category']] ?? 14;

        $case = YouthCase::query()->create([
            ...$data,
            'district_id' => $youth->district_id,
            'status' => 'royxatda',
            'sla_deadline' => $data['sla_deadline'] ?? now()->addDays($slaDays)->toDateString(),
            'created_by' => $user->id,
            'created_by_org_id' => $staff?->org_id,
        ]);

        // Reyestrdagi bayroq: yoshda ochiq muammo bor.
        $youth->update(['has_open_case' => true]);

        $this->audit->log($user, 'case.create', 'case', $case->id, [
            'youth_id' => $youth->id,
            'category' => $data['category'],
        ]);

        return $case;
    }

    /** @param array<string, mixed> $data */
    public function updateCase(User $user, YouthCase $case, array $data): YouthCase
    {
        // Hal etilgan deb belgilansa — yechim izohi MAJBURIY: «hal etildi»
        // degan bo'sh yozuv keyin nima qilinganini tekshirib bo'lmaydigan
        // qiladi va hisobotni yolg'onlashtiradi.
        if (($data['status'] ?? null) === 'hal_etildi' && trim((string) ($data['resolution_note'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'resolution_note' => 'Muammo qanday hal etilgani yozilishi shart.',
            ]);
        }

        if (($data['status'] ?? null) === 'hal_etildi') {
            $data['resolved_at'] = now();
        }

        $case->update($data);

        // Yoshda boshqa ochiq muammo qolmagan bo'lsa — bayroqni tushiramiz.
        if (($data['status'] ?? null) === 'hal_etildi') {
            $stillOpen = YouthCase::query()->where('youth_id', $case->youth_id)->open()->exists();
            Youth::query()->whereKey($case->youth_id)->update(['has_open_case' => $stillOpen]);
        }

        $this->audit->log($user, 'case.update', 'case', $case->id, $data);

        return $case->refresh();
    }

    /** @return array<string, mixed> */
    public function caseStats(User $user): array
    {
        $base = fn () => $this->visibleCases($user);

        return [
            'total' => $base()->count(),
            'open' => $base()->open()->count(),
            'resolved' => $base()->where('status', 'hal_etildi')->count(),
            'overdue' => $base()->open()->whereDate('sla_deadline', '<', now()->toDateString())->count(),
            'by_category' => $base()->open()->selectRaw('category, count(*) as total')
                ->groupBy('category')->pluck('total', 'category'),
        ];
    }

    // ---------------- Otaliq ----------------

    /** @return \Illuminate\Database\Eloquent\Collection<int, Patronage> */
    public function patronageList(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return $this->visiblePatronage($user)
            ->with(['youth:id,last_name,first_name,middle_name,birth_date,district_id,mahalla_id', 'mentor:id,user_id,org_id,position'])
            ->withCount('logs')
            ->where('is_active', true)
            ->get();
    }

    /**
     * Yoshni mentorga biriktirish.
     *
     * Mentor `can_patronage` huquqiga ega boʻlishi SHART — bu huquq tashkilot
     * ichida alohida beriladi (TZ 3-bo'lim: «Xodim darajasidagi ruxsat»).
     *
     * @param  array<string, mixed>  $data
     */
    public function assignPatronage(User $user, array $data): Patronage
    {
        $youth = Youth::query()->findOrFail($data['youth_id']);
        $mentor = Staff::query()->findOrFail($data['mentor_staff_id']);

        abort_unless(
            $this->scope->canTouchDistrict($user, $youth->district_id),
            403,
            'Bu yosh sizning doirangizda emas.',
        );

        if (! $mentor->can_patronage || ! $mentor->is_active) {
            throw ValidationException::withMessages([
                'mentor_staff_id' => 'Bu xodimda otaliq yuritish huquqi yoʻq.',
            ]);
        }

        $active = Patronage::query()->where('youth_id', $youth->id)->where('is_active', true)->exists();

        if ($active) {
            throw ValidationException::withMessages([
                'youth_id' => 'Bu yosh allaqachon otaliqqa olingan.',
            ]);
        }

        $patronage = Patronage::query()->create([
            'youth_id' => $youth->id,
            'mentor_staff_id' => $mentor->id,
            'district_id' => $youth->district_id,
            'started_at' => $data['started_at'] ?? now()->toDateString(),
            'is_active' => true,
            'note' => $data['note'] ?? null,
            'created_by' => $user->id,
        ]);

        $youth->update(['in_patronage' => true]);

        $this->audit->log($user, 'patronage.assign', 'patronage', $patronage->id, [
            'youth_id' => $youth->id,
            'mentor_staff_id' => $mentor->id,
        ]);

        return $patronage;
    }

    public function endPatronage(User $user, Patronage $patronage, ?string $note): Patronage
    {
        $patronage->update([
            'is_active' => false,
            'ended_at' => now()->toDateString(),
            'note' => $note ?? $patronage->note,
        ]);

        Youth::query()->whereKey($patronage->youth_id)->update(['in_patronage' => false]);

        $this->audit->log($user, 'patronage.end', 'patronage', $patronage->id);

        return $patronage->refresh();
    }

    /** @param array<string, mixed> $data */
    public function addLog(User $user, Patronage $patronage, array $data): PatronageLog
    {
        $log = PatronageLog::query()->create([
            'patronage_id' => $patronage->id,
            'log_date' => $data['log_date'] ?? now()->toDateString(),
            'kind' => $data['kind'] ?? 'uchrashuv',
            'note' => $data['note'],
            'case_id' => $data['case_id'] ?? null,
            'created_by' => $user->id,
        ]);

        $this->audit->log($user, 'patronage.log', 'patronage', $patronage->id, ['kind' => $log->kind]);

        return $log;
    }

    public function findPatronage(User $user, string $id): ?Patronage
    {
        return $this->visiblePatronage($user)->with(['youth', 'mentor', 'logs'])->where('id', $id)->first();
    }

    /** @return array<string, mixed> */
    public function patronageStats(User $user): array
    {
        $base = fn () => $this->visiblePatronage($user);

        $active = (clone $base())->where('is_active', true)->count();

        // FAOLLIK: oxirgi 30 kunda kamida bitta jurnal yozuvi bo'lgan otaliqlar.
        // «Biriktirilgan» degani hali «ishlanmoqda» degani emas.
        $withRecentLog = (clone $base())->where('is_active', true)
            ->whereExists(function ($q) {
                $q->selectRaw('1')
                    ->from('yoshlar.patronage_logs as pl')
                    ->whereColumn('pl.patronage_id', 'patronage.id')
                    ->whereDate('pl.log_date', '>=', now()->subDays(30)->toDateString());
            })
            ->count();

        return [
            'active' => $active,
            'active_with_recent_log' => $withRecentLog,
            'activity_rate' => $active === 0 ? 0 : (int) round(($withRecentLog / $active) * 100),
            'logs_30d' => PatronageLog::query()
                ->whereDate('log_date', '>=', now()->subDays(30)->toDateString())
                ->whereIn('patronage_id', (clone $base())->select('id'))
                ->count(),
        ];
    }

    // ---------------- Doira ----------------

    /** @return Builder<YouthCase> */
    private function visibleCases(User $user): Builder
    {
        return $this->applyGeo(YouthCase::query(), $user);
    }

    /** @return Builder<Patronage> */
    private function visiblePatronage(User $user): Builder
    {
        return $this->applyGeo(Patronage::query(), $user);
    }

    /**
     * Muammo ham, otaliq ham YOSHGA tegishli — shuning uchun geo doira.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function applyGeo(Builder $query, User $user): Builder
    {
        $districts = $this->scope->districtIds($user);

        if ($districts === null) {
            return $query;
        }

        return $districts === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn('district_id', $districts);
    }
}
