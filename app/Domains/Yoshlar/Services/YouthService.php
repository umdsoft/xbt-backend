<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Services;

use App\Domains\Yoshlar\Models\Organization;
use App\Domains\Yoshlar\Models\Youth;
use App\Domains\Yoshlar\Support\Translit;
use App\Domains\Yoshlar\Support\YoshlarAccess;
use App\Domains\Yoshlar\Support\YoshlarScope;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reyestr biznes qoidalari.
 *
 * TASDIQLASH: reyestrga yoshlar vertikali egalik qiladi. Sektor bo'limi yangi
 * yosh qo'sha oladi, lekin yozuv `pending` bo'lib tushadi va tuman yoshlar
 * bo'limi tasdiqlamaguncha umumiy reyestrga kirmaydi.
 */
class YouthService
{
    public function __construct(
        private readonly YoshlarAccess $access,
        private readonly YoshlarScope $scope,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notify,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<Youth>
     */
    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        $query = $this->scope->applyYouth(Youth::query(), $user);

        $this->applyFilters($query, $filters);

        $perPage = min((int) ($filters['per_page'] ?? 25), 200);

        return $query->orderBy('last_name')->orderBy('first_name')->paginate($perPage);
    }

    /** Bitta yozuv — doiradan tashqarida bo'lsa `null` (kontroller 404 qaytaradi). */
    public function find(User $user, string $id): ?Youth
    {
        return $this->scope->applyYouth(Youth::query(), $user)->where('id', $id)->first();
    }

    /** @param array<string, mixed> $data */
    public function create(User $user, array $data): Youth
    {
        $staff = $this->access->staffFor($user);
        $role = $this->access->roleFor($user);

        // Sektor bo'limi TAKLIF kiritadi — tasdiqlanmaguncha reyestrga kirmaydi.
        $data['verification_status'] = $role === 'sektor_bolim' ? 'pending' : 'verified';
        $data['created_by'] = $user->id;
        $data['created_by_org_id'] = $staff?->org_id;
        $data['registry_status'] = 'active';

        $youth = Youth::query()->create($data);

        $this->audit->log($user, 'youth.create', 'youth', $youth->id, [
            'verification_status' => $data['verification_status'],
        ]);

        // Taklif tushdi — tuman yoshlar bo'limiga xabar (reyestrga u egalik qiladi).
        if ($data['verification_status'] === 'pending') {
            $officeId = Organization::query()
                ->where('type', Organization::TYPE_TUMAN_YOSHLAR)
                ->where('district_id', $youth->district_id)
                ->value('id');

            if ($officeId !== null) {
                $this->notify->notifyOrganization((string) $officeId, 'youth.pending', [
                    'title' => 'Reyestrga yangi taklif',
                    'body' => $youth->full_name,
                    'link' => '/navbat',
                    'entity_type' => 'youth',
                    'entity_id' => $youth->id,
                ]);
            }
        }

        return $youth;
    }

    /** @param array<string, mixed> $data */
    public function update(User $user, Youth $youth, array $data): Youth
    {
        $data['updated_by'] = $user->id;
        $before = $youth->only(array_keys($data));

        $youth->update($data);

        $this->audit->log($user, 'youth.update', 'youth', $youth->id, [
            'before' => $this->withoutPii($before),
            'after' => $this->withoutPii($data),
        ]);

        return $youth->refresh();
    }

    public function verify(User $user, Youth $youth): Youth
    {
        $youth->update([
            'verification_status' => 'verified',
            'verified_by' => $user->id,
            'verified_at' => now(),
            'reject_reason' => null,
        ]);

        $this->audit->log($user, 'youth.verify', 'youth', $youth->id);

        return $youth->refresh();
    }

    public function reject(User $user, Youth $youth, string $reason): Youth
    {
        $youth->update([
            'verification_status' => 'rejected',
            'verified_by' => $user->id,
            'verified_at' => now(),
            'reject_reason' => $reason,
        ]);

        $this->audit->log($user, 'youth.reject', 'youth', $youth->id, ['reason' => $reason]);

        return $youth->refresh();
    }

    /**
     * PINFLsiz yozuv uchun ehtimoliy dublikatlar soni (bloklamaydi, ogohlantiradi).
     *
     * @param  array<string, mixed>  $data
     */
    public function possibleDuplicates(array $data): int
    {
        $norm = Translit::normalize(
            trim(((string) ($data['last_name'] ?? '')).' '.((string) ($data['first_name'] ?? ''))),
        );

        if ($norm === '') {
            return 0;
        }

        return Youth::query()
            ->where('full_name_norm', 'like', $norm.'%')
            ->where('birth_date', $data['birth_date'] ?? null)
            ->where('mahalla_id', $data['mahalla_id'] ?? null)
            ->count();
    }

    /**
     * @param  Builder<Youth>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        // Tasdiqlanmagan yozuvlar umumiy ro'yxatga KIRMAYDI — ular alohida
        // «tasdiq navbati» so'rovi bilan olinadi (?verification_status=pending).
        $verification = (string) ($filters['verification_status'] ?? 'verified');
        if (in_array($verification, Youth::VERIFICATION_STATUSES, true)) {
            $query->where('verification_status', $verification);
        }

        $query->where('registry_status', (string) ($filters['registry_status'] ?? 'active'));

        foreach (['district_id', 'mahalla_id', 'gender', 'education_status', 'employment_status'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        foreach (['is_neet', 'is_graduate_unemployed', 'in_youth_book', 'is_entrepreneur'] as $flag) {
            if (array_key_exists($flag, $filters) && $filters[$flag] !== '') {
                $query->where($flag, filter_var($filters[$flag], FILTER_VALIDATE_BOOL));
            }
        }

        $min = (int) ($filters['age_min'] ?? Youth::MIN_AGE);
        $max = (int) ($filters['age_max'] ?? Youth::MAX_AGE);
        $query->ageBetween($min, $max);

        if (! empty($filters['search'])) {
            $key = Translit::normalize((string) $filters['search']);
            $query->where('full_name_norm', 'like', '%'.$key.'%');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withoutPii(array $data): array
    {
        foreach (['pinfl', 'passport_series', 'passport_number'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = '***';   // fakt yoziladi, qiymat emas
            }
        }

        return $data;
    }
}
