<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Services;

use App\Domains\Qurilish\Models\ConstructionObject;
use App\Domains\Qurilish\Support\QurilishAccess;
use App\Domains\Qurilish\Support\QurilishScope;
use App\Domains\Qurilish\Support\Translit;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Obyekt reyestri: ro'yxat/filtr, yaratish, tahrirlash.
 *
 * Har so'rovga `QurilishScope` avtomatik qo'llanadi — kontroller uni unutib
 * qo'yishi mumkin bo'lgan yagona joy shu servis, shuning uchun u yerda.
 * Begona obyekt so'ralganda 403 emas, **404** qaytariladi: 403 obyektning
 * mavjudligini oshkor qilardi.
 */
class ObjectService
{
    public function __construct(
        private readonly QurilishAccess $access,
        private readonly QurilishScope $scope,
        private readonly AuditLogger $audit,
        private readonly StageService $stages,
    ) {}

    /** Rolga qarab tahrirlash mumkin bo'lgan maydonlar. */
    private const EDITABLE = [
        'qurilish_admin' => [
            'name', 'program_id', 'sector_id', 'district_id', 'mahalla_id', 'work_type',
            'customer_org_id', 'designer_org_id', 'contractor_org_id', 'department_org_id',
            'limit_amount', 'tender_amount', 'contract_amount', 'disbursed_amount', 'financed_amount',
            'deadline_date', 'deadline_year', 'is_carryover', 'lifecycle',
            'handover_planned', 'note',
        ],
        // Buyurtmachi loyihani YURITADI: moliya, pudratchi/loyihachi, muddat, izoh.
        // Obyekt nomini yoki hududini o'zgartira olmaydi — u boshqarma ma'lumoti.
        'qurilish_buyurtmachi' => [
            'designer_org_id', 'contractor_org_id',
            'tender_amount', 'contract_amount', 'disbursed_amount', 'financed_amount',
            'deadline_date', 'deadline_year', 'handover_planned', 'note',
        ],
        // Boshqarma obyektni KIRITADI: nom, hudud, soha, muddat, limit.
        'qurilish_boshqarma' => [
            'name', 'sector_id', 'district_id', 'mahalla_id', 'work_type',
            'program_id', 'limit_amount', 'deadline_date', 'deadline_year',
            'is_carryover', 'note',
        ],
    ];

    /** @param array<string, mixed> $filters */
    public function paginate(User $user, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->applyFilters($this->baseQuery($user), $filters)
            // Bosqichlar ham yuklanadi: ro'yxatdagi «spine» HAQIQIY holatni
            // ko'rsatishi shart. Uni `current_stage` dan taxmin qilib bo'lmaydi —
            // manbada shartnomasi bajarilgan-u, loyihachisi qayd etilmagan
            // obyektlar bor va taxmin ularni noto'g'ri chizardi.
            ->with(['program:id,code,name_lat', 'sector:id,code,name_lat',
                'customer:id,name_lat', 'contractor:id,name_lat', 'department:id,name_lat',
                'stages:id,object_id,stage_code,status'])
            ->orderByRaw('COALESCE(deadline_date, DATE \'2099-12-31\') ASC')
            ->orderBy('name')
            ->paginate(min($perPage, 100));
    }

    /**
     * Eksport uchun to'liq tanlov (sahifalashsiz).
     *
     * `paginate()` sahifa hajmini 100 ga cheklaydi — eksportda bu JIM
     * qirqilish bo'lardi. Bu yerda chegara aniq (`MAX_EXPORT`) va u
     * oshib ketsa kontroller foydalanuvchini ogohlantiradi.
     *
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Support\Collection<int, ConstructionObject>
     */
    public function forExport(User $user, array $filters): \Illuminate\Support\Collection
    {
        return $this->applyFilters($this->baseQuery($user), $filters)
            ->with(['program:id,name_lat', 'sector:id,name_lat',
                'customer:id,name_lat', 'contractor:id,name_lat'])
            ->orderBy('name')
            ->limit(self::MAX_EXPORT + 1)
            ->get();
    }

    /** Eksportdagi maksimal qator soni (oshsa foydalanuvchi ogohlantiriladi). */
    public const MAX_EXPORT = 5000;

    /** Foydalanuvchi ko'ra oladigan obyekt yoki 404. */
    public function findOrFail(User $user, string $id): ConstructionObject
    {
        $object = $this->baseQuery($user)->find($id);

        if ($object === null) {
            abort(404, 'Объект топилмади.');
        }

        return $object;
    }

    /** @param array<string, mixed> $data */
    public function create(User $user, array $data): ConstructionObject
    {
        $role = (string) $this->access->roleFor($user);
        $fields = $this->filterEditable($role, $data);

        // Boshqarma faqat O'Z obyektini yaratadi — tashkilot majburan biriktiriladi.
        if ($role === 'qurilish_boshqarma') {
            $fields['department_org_id'] = $this->access->profileFor($user)?->organization_id;
        }

        // Dasturi ko'rsatilmagan obyekt — kelajakdagi (ta'mirtalab) obyekt: qoralama.
        if (isset($fields['name'])) {
            $fields['name_lat'] = Translit::toLatin((string) $fields['name']);
        }

        $fields['lifecycle'] = ($fields['program_id'] ?? null) === null ? 'qoralama' : 'reja';
        $fields['source'] = 'manual';
        $fields['created_by'] = $user->id;
        $fields['updated_by'] = $user->id;

        return DB::connection('qurilish')->transaction(function () use ($fields, $user): ConstructionObject {
            $object = ConstructionObject::query()->create($fields);
            $this->stages->ensureStages($object);
            $this->audit->log($object, $user, 'create', null, null, $object->name);

            return $object;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $user, ConstructionObject $object, array $data): ConstructionObject
    {
        $role = (string) $this->access->roleFor($user);
        $fields = $this->filterEditable($role, $data);

        if ($fields === []) {
            return $object;
        }

        if (isset($fields['name'])) {
            $fields['name_lat'] = Translit::toLatin((string) $fields['name']);
        }

        $before = $object->only(array_keys($fields));
        $fields['updated_by'] = $user->id;

        return DB::connection('qurilish')->transaction(function () use ($object, $fields, $before, $user): ConstructionObject {
            $object->fill($fields)->save();
            $this->audit->logChanges($object, $user, $before, $fields);

            // Dastur biriktirilsa qoralama holatidan chiqadi.
            if ($object->lifecycle === 'qoralama' && $object->program_id !== null) {
                $object->forceFill(['lifecycle' => 'reja'])->save();
                $this->audit->log($object, $user, 'update', 'lifecycle', 'qoralama', 'reja');
            }

            return $object->refresh();
        });
    }

    /** @return Builder<ConstructionObject> */
    private function baseQuery(User $user): Builder
    {
        return $this->scope->apply(ConstructionObject::query(), $user);
    }

    /**
     * @param  Builder<ConstructionObject>  $q
     * @param  array<string, mixed>  $f
     * @return Builder<ConstructionObject>
     */
    private function applyFilters(Builder $q, array $f): Builder
    {
        return $q
            ->when($f['program_id'] ?? null, fn (Builder $q, $v) => $q->where('program_id', $v))
            ->when($f['sector_id'] ?? null, fn (Builder $q, $v) => $q->where('sector_id', $v))
            ->when($f['district_id'] ?? null, fn (Builder $q, $v) => $q->where('district_id', $v))
            ->when($f['customer_org_id'] ?? null, fn (Builder $q, $v) => $q->where('customer_org_id', $v))
            ->when($f['contractor_org_id'] ?? null, fn (Builder $q, $v) => $q->where('contractor_org_id', $v))
            ->when($f['department_org_id'] ?? null, fn (Builder $q, $v) => $q->where('department_org_id', $v))
            ->when($f['lifecycle'] ?? null, fn (Builder $q, $v) => $q->where('lifecycle', $v))
            ->when($f['current_stage'] ?? null, fn (Builder $q, $v) => $q->where('current_stage', $v))
            ->when($f['work_type'] ?? null, fn (Builder $q, $v) => $q->where('work_type', $v))
            // `is_carryover` bool: '0' ham qiymat, shuning uchun `when` emas —
            // array_key_exists bilan tekshiramiz (aks holda '0' e'tiborsiz qolardi).
            ->when(
                array_key_exists('is_carryover', $f) && $f['is_carryover'] !== null && $f['is_carryover'] !== '',
                fn (Builder $q) => $q->where('is_carryover', filter_var($f['is_carryover'], FILTER_VALIDATE_BOOL)),
            )
            ->when(
                filter_var($f['overdue'] ?? null, FILTER_VALIDATE_BOOL),
                fn (Builder $q) => $q->whereNotNull('deadline_date')
                    ->whereDate('deadline_date', '<', now()->toDateString())
                    ->where('handover_done', false),
            )
            // Qidiruv IKKALA ustun bo'yicha: foydalanuvchi lotin ham, kirill ham
            // yozishi mumkin (nom manbada kirill, sahifada lotin ko'rinadi).
            ->when($f['q'] ?? null, fn (Builder $q, $v) => $q->where(
                fn (Builder $w) => $w->where('name', 'ilike', '%'.$v.'%')
                    ->orWhere('name_lat', 'ilike', '%'.$v.'%')
                    ->orWhere('external_id', 'ilike', $v.'%'),
            ));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function filterEditable(string $role, array $data): array
    {
        $allowed = self::EDITABLE[$role] ?? [];

        return array_intersect_key($data, array_flip($allowed));
    }
}
