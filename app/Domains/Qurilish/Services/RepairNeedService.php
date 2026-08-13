<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Services;

use App\Domains\Qurilish\Models\ConstructionObject;
use App\Domains\Qurilish\Models\RepairNeed;
use App\Domains\Qurilish\Support\QurilishAccess;
use App\Domains\Qurilish\Support\QurilishScope;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ta'mirtalab obyektlar reyestri — loyihaning IKKINCHI asosiy maqsadi.
 *
 * Manbadagi `ПАСПОРТ` varag'i faqat soha kesimidagi AGREGAT edi (1 298 obyekt,
 * 368 ta'mirtalab, 216 tasining moliyalashtirish manbai noaniq) — obyekt
 * darajasidagi ro'yxat umuman mavjud emas edi. Bu yerda u noldan yig'iladi,
 * `ПАСПОРТ` esa shu jadvaldan JONLI hisoblanadigan hisobotga aylanadi.
 *
 * `promote()` pipeline'ni yopadi: yozuv real loyihaga aylanadi va bog'lanish
 * saqlanadi — «bu obyekt qachon ta'mirtalab deb qayd etilgan edi» degan tarix
 * yo'qolmaydi.
 */
class RepairNeedService
{
    public function __construct(
        private readonly QurilishAccess $access,
        private readonly QurilishScope $scope,
        private readonly StageService $stages,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $filters */
    public function paginate(User $user, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->baseQuery($user)
            ->when($filters['sector_id'] ?? null, fn (Builder $q, $v) => $q->where('sector_id', $v))
            ->when($filters['district_id'] ?? null, fn (Builder $q, $v) => $q->where('district_id', $v))
            ->when($filters['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->when($filters['target_year'] ?? null, fn (Builder $q, $v) => $q->where('target_year', $v))
            ->when(
                array_key_exists('funding_source_known', $filters) && $filters['funding_source_known'] !== null,
                fn (Builder $q) => $q->where(
                    'funding_source_known',
                    filter_var($filters['funding_source_known'], FILTER_VALIDATE_BOOL),
                ),
            )
            ->when($filters['q'] ?? null, fn (Builder $q, $v) => $q->where('name', 'ilike', '%'.$v.'%'))
            ->with(['sector:id,code,name_lat', 'department:id,name_lat'])
            ->orderBy('priority')
            ->orderByDesc('estimated_amount')
            ->paginate(min($perPage, 100));
    }

    public function findOrFail(User $user, string $id): RepairNeed
    {
        $need = $this->baseQuery($user)->find($id);

        if ($need === null) {
            abort(404, 'Ёзув топилмади.');
        }

        return $need;
    }

    /** @param array<string, mixed> $data */
    public function create(User $user, array $data): RepairNeed
    {
        // Boshqarma faqat O'Z reyestriga yozadi — tashkilot majburan biriktiriladi.
        // Admin boshqa boshqarma nomidan kirita oladi.
        if ($this->access->roleFor($user) === 'qurilish_boshqarma') {
            $data['department_org_id'] = $this->access->profileFor($user)?->organization_id;
        }

        $data['created_by'] = $user->id;
        $data['status'] = 'yigilgan';

        return RepairNeed::query()->create($data);
    }

    /** @param array<string, mixed> $data */
    public function update(User $user, RepairNeed $need, array $data): RepairNeed
    {
        // Dasturga kiritilgan yozuvni tahrirlash pipeline tarixini buzardi.
        if ($need->status === 'dasturga_kiritildi') {
            throw ValidationException::withMessages([
                'status' => 'Дастурга киритилган ёзувни ўзгартириб бўлмайди.',
            ]);
        }

        unset($data['status'], $data['promoted_object_id'], $data['department_org_id']);
        $need->fill($data)->save();

        return $need->refresh();
    }

    /**
     * Ta'mirtalab yozuvni real obyektga aylantiradi (qoralama sifatida).
     *
     * Obyekt `qoralama` bo'lib tug'iladi: unga dastur biriktirilgunicha
     * dashboard jamlanmasiga tushmaydi va bosqichlari o'zgartirilmaydi.
     */
    public function promote(User $user, RepairNeed $need): ConstructionObject
    {
        if ($need->status === 'dasturga_kiritildi' && $need->promoted_object_id !== null) {
            throw ValidationException::withMessages([
                'status' => 'Бу ёзув аллақачон дастурга киритилган.',
            ]);
        }

        return DB::connection('qurilish')->transaction(function () use ($user, $need): ConstructionObject {
            $object = ConstructionObject::query()->create([
                'name' => $need->name,
                'sector_id' => $need->sector_id,
                'district_id' => $need->district_id,
                'mahalla_id' => $need->mahalla_id,
                'department_org_id' => $need->department_org_id,
                'limit_amount' => $need->estimated_amount ?? 0,
                'deadline_year' => $need->target_year,
                'lifecycle' => 'qoralama',
                'source' => 'manual',
                'note' => $need->condition_desc,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            $this->stages->ensureStages($object);
            $this->audit->log($object, $user, 'create', 'repair_need_id', (string) $need->id, $object->name);

            $need->forceFill([
                'status' => 'dasturga_kiritildi',
                'promoted_object_id' => $object->id,
            ])->save();

            return $object;
        });
    }

    /**
     * `ПАСПОРТ` hisoboti — soha kesimida, manbadagi varaq bilan bir xil ustunlar.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pasport(User $user): array
    {
        $rows = $this->baseQuery($user)
            ->leftJoin('qurilish.sectors as s', 's.id', '=', 'repair_needs.sector_id')
            ->selectRaw("
                repair_needs.sector_id as key,
                max(s.name_lat) as name,
                count(*) as needs,
                coalesce(sum(estimated_amount), 0) as amount_total,
                count(*) filter (where target_year = 2026) as year_2026,
                coalesce(sum(estimated_amount) filter (where target_year = 2026), 0) as amount_2026,
                count(*) filter (where target_year = 2027) as year_2027,
                coalesce(sum(estimated_amount) filter (where target_year = 2027), 0) as amount_2027,
                count(*) filter (where not funding_source_known) as funding_unknown,
                coalesce(sum(estimated_amount) filter (where not funding_source_known), 0) as amount_unknown
            ")
            ->groupBy('repair_needs.sector_id')
            ->orderByDesc('needs')
            ->get();

        return $rows->map(fn ($r): array => [
            'key' => $r->key,
            'name' => $r->name ?? 'Aniqlanmagan',
            'needs' => (int) $r->needs,
            'amount_total' => (float) $r->amount_total,
            'year_2026' => (int) $r->year_2026,
            'amount_2026' => (float) $r->amount_2026,
            'year_2027' => (int) $r->year_2027,
            'amount_2027' => (float) $r->amount_2027,
            'funding_unknown' => (int) $r->funding_unknown,
            'amount_unknown' => (float) $r->amount_unknown,
        ])->all();
    }

    /** @return Builder<RepairNeed> */
    private function baseQuery(User $user): Builder
    {
        return $this->scope->applyRepair(RepairNeed::query(), $user);
    }
}
