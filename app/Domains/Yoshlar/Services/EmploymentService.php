<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Services;

use App\Domains\Yoshlar\Models\EmploymentCase;
use App\Domains\Yoshlar\Models\Youth;
use App\Domains\Yoshlar\Support\YoshlarAccess;
use App\Domains\Yoshlar\Support\YoshlarScope;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bandlik: 3 tomonlama tasdiqlash zanjiri (TZ 5.5).
 *
 * ish beruvchi maʼlumoti -> TUMAN SOLIQ -> VILOYAT SOLIQ -> «rasman band».
 *
 * ZANJIR ROLGA EMAS, SEKTORGA bogʻlangan: soliq tasdigʻini faqat sektori
 * `soliq` boʻlgan tashkilot xodimi bera oladi. Shu sabab yangi tasdiqlovchi
 * organ qoʻshish uchun kod emas, tashkilot yozuvi kerak.
 */
class EmploymentService
{
    public const TAX_SECTOR = 'soliq';

    public function __construct(
        private readonly YoshlarAccess $access,
        private readonly YoshlarScope $scope,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notify,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<EmploymentCase>
     */
    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        $query = $this->visible($user)->with('youth:id,last_name,first_name,middle_name,birth_date,mahalla_id,district_id');

        foreach (['status', 'district_id', 'youth_id'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (! empty($filters['search'])) {
            $query->where('employer_name', 'ilike', '%'.$filters['search'].'%');
        }

        return $query->orderByDesc('submitted_at')->paginate(min((int) ($filters['per_page'] ?? 25), 200));
    }

    public function find(User $user, string $id): ?EmploymentCase
    {
        return $this->visible($user)->with('youth')->where('id', $id)->first();
    }

    /**
     * Ariza yaratish. Yosh reyestrda boʻlishi va doirada turishi shart.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data): EmploymentCase
    {
        $youth = Youth::query()->findOrFail($data['youth_id']);

        if (! $this->scope->canTouchDistrict($user, $youth->district_id)) {
            throw ValidationException::withMessages([
                'youth_id' => 'Bu yosh sizning doirangizda emas.',
            ]);
        }

        if ($youth->verification_status !== 'verified') {
            throw ValidationException::withMessages([
                'youth_id' => 'Yosh reyestrda tasdiqlanmagan — avval yozuv tasdiqlansin.',
            ]);
        }

        $open = EmploymentCase::query()
            ->where('youth_id', $youth->id)
            ->whereIn('status', EmploymentCase::OPEN_STATUSES)
            ->exists();

        if ($open) {
            throw ValidationException::withMessages([
                'youth_id' => 'Bu yosh boʻyicha koʻrib chiqilayotgan ariza bor.',
            ]);
        }

        $staff = $this->access->staffFor($user);

        $case = EmploymentCase::query()->create([
            ...$data,
            'district_id' => $youth->district_id,
            'status' => EmploymentCase::STATUS_SUBMITTED,
            'submitted_by' => $user->id,
            'submitted_org_id' => $staff?->org_id,
            'submitted_at' => now(),
        ]);

        $this->audit->log($user, 'employment.create', 'employment', $case->id, [
            'youth_id' => $youth->id,
            'employer' => $data['employer_name'],
        ]);

        // Zanjirning 1-bo'g'ini — SHU TUMAN soliq bo'limi.
        $this->notify->notifySector(self::TAX_SECTOR, 'tuman_sektor', $youth->district_id, 'employment.pending', [
            'title' => 'Bandlik arizasi tasdiq kutmoqda',
            'body' => $data['employer_name'],
            'link' => '/bandlik-navbati',
            'entity_type' => 'employment',
            'entity_id' => $case->id,
        ]);

        return $case;
    }

    /**
     * Zanjir bosqichi. `$stage`: `tax_district` yoki `tax_province`.
     *
     * Ikki tekshiruv: (1) foydalanuvchida shu bosqich RUXSATI bormi,
     * (2) uning tashkiloti SOLIQ sektoridami. Ikkinchisi boʻlmasa, bandlik
     * boʻlimi oʻz arizasini oʻzi tasdiqlab yuborardi.
     */
    public function review(User $user, EmploymentCase $case, string $stage, bool $approve, ?string $comment): EmploymentCase
    {
        $expected = $case->nextStage();

        if ($expected !== $stage) {
            throw ValidationException::withMessages([
                'stage' => 'Ariza bu bosqichda emas — sahifani yangilang.',
            ]);
        }

        $permission = $stage === 'tax_district'
            ? 'yoshlar.employment.review.district'
            : 'yoshlar.employment.review.province';

        abort_unless($this->access->can($user, $permission), 403, 'Bu bosqichni tasdiqlash huquqingiz yoʻq.');

        abort_unless(
            $this->access->sectorCodeFor($user) === self::TAX_SECTOR,
            403,
            'Bandlikni faqat soliq organi tasdiqlaydi.',
        );

        // Tuman soliq faqat O'Z tumanidagi arizani ko'radi (geo doira).
        if ($stage === 'tax_district') {
            abort_unless(
                $this->scope->canTouchDistrict($user, $case->district_id),
                403,
                'Bu tuman sizning doirangizda emas.',
            );
        }

        if (! $approve) {
            $case->update([
                'status' => EmploymentCase::STATUS_RETURNED,
                'reject_reason' => $comment,
                $stage === 'tax_district' ? 'tax_district_by' : 'tax_province_by' => $user->id,
                $stage === 'tax_district' ? 'tax_district_at' : 'tax_province_at' => now(),
            ]);

            $this->audit->log($user, "employment.return.{$stage}", 'employment', $case->id, ['reason' => $comment]);

            if ($case->submitted_org_id !== null) {
                $this->notify->notifyOrganization($case->submitted_org_id, 'employment.returned', [
                    'title' => 'Bandlik arizasi qaytarildi',
                    'body' => $case->employer_name.' — '.($comment ?? ''),
                    'link' => '/bandlik',
                    'entity_type' => 'employment',
                    'entity_id' => $case->id,
                ]);
            }

            return $case->refresh();
        }

        if ($stage === 'tax_district') {
            $case->update([
                'status' => EmploymentCase::STATUS_TAX_DISTRICT,
                'tax_district_by' => $user->id,
                'tax_district_at' => now(),
                'reject_reason' => null,
            ]);

            $this->audit->log($user, 'employment.approve.tax_district', 'employment', $case->id);

            // Keyingi bo'g'in — VILOYAT soliq boshqarmasi (tumansiz).
            $this->notify->notifySector(self::TAX_SECTOR, 'viloyat_sektor', null, 'employment.pending', [
                'title' => 'Yakuniy soliq tasdigʻi kutilmoqda',
                'body' => $case->employer_name,
                'link' => '/bandlik-navbati',
                'entity_type' => 'employment',
                'entity_id' => $case->id,
            ]);

            return $case->refresh();
        }

        // ATOMAR: ariza holati va reyestr birga o'zgaradi. Aks holda
        // ikkinchi so'rov yiqilsa, ariza «rasman band» bo'lib, reyestrda
        // odam «band emas» bo'lib qolardi — ikki joyda ikki xil haqiqat.
        DB::connection('yoshlar')->transaction(function () use ($case, $user): void {
            $case->update([
                'status' => EmploymentCase::STATUS_CONFIRMED,
                'tax_province_by' => $user->id,
                'tax_province_at' => now(),
            ]);

            Youth::query()->whereKey($case->youth_id)->update([
                'employment_status' => 'band',
                'workplace' => $case->employer_name,
                'is_neet' => false,
                'is_graduate_unemployed' => false,
            ]);
        });

        $this->audit->log($user, 'employment.approve.tax_province', 'employment', $case->id);

        return $case->refresh();
    }

    /**
     * Tasdiqlash navbati — foydalanuvchi qaysi bosqichda ishlasa, oʻsha.
     *
     * @return Collection<int, EmploymentCase>
     */
    public function reviewQueue(User $user): Collection
    {
        if ($this->access->sectorCodeFor($user) !== self::TAX_SECTOR) {
            return EmploymentCase::query()->whereRaw('1 = 0')->get();
        }

        $status = match (true) {
            $this->access->can($user, 'yoshlar.employment.review.district') => EmploymentCase::STATUS_SUBMITTED,
            $this->access->can($user, 'yoshlar.employment.review.province') => EmploymentCase::STATUS_TAX_DISTRICT,
            default => null,
        };

        if ($status === null) {
            return EmploymentCase::query()->whereRaw('1 = 0')->get();
        }

        return $this->visible($user)
            ->with('youth:id,last_name,first_name,middle_name,birth_date,district_id,mahalla_id')
            ->where('status', $status)
            ->orderBy('submitted_at')
            ->get();
    }

    /** @return array<string, mixed> */
    public function stats(User $user): array
    {
        $base = fn () => $this->visible($user);

        return [
            'total' => $base()->count(),
            'confirmed' => $base()->where('status', EmploymentCase::STATUS_CONFIRMED)->count(),
            'in_review' => $base()->whereIn('status', EmploymentCase::OPEN_STATUSES)->count(),
            'returned' => $base()->where('status', EmploymentCase::STATUS_RETURNED)->count(),
            'by_district' => $base()->where('status', EmploymentCase::STATUS_CONFIRMED)
                ->selectRaw('district_id, count(*) as total')
                ->groupBy('district_id')->pluck('total', 'district_id'),
        ];
    }

    /**
     * Koʻrish doirasi — GEO oʻlchov (ariza yoshga, ya'ni mahallaga tegishli).
     *
     * @return Builder<EmploymentCase>
     */
    private function visible(User $user): Builder
    {
        $districts = $this->scope->districtIds($user);

        $query = EmploymentCase::query();

        if ($districts === null) {
            return $query;
        }

        return $districts === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn('district_id', $districts);
    }
}
