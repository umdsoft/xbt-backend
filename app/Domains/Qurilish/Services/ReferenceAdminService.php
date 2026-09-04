<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Services;

use App\Domains\Qurilish\Models\ConstructionObject;
use App\Domains\Qurilish\Models\Organization;
use App\Domains\Qurilish\Models\Program;
use App\Domains\Qurilish\Models\Sector;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Spravochniklar boshqaruvi: davlat dasturlari, sohalar, tashkilotlar.
 *
 * ASOSIY QOIDA: ishlatilayotgan yozuv O'CHIRILMAYDI — u faqat NOFAOL
 * qilinadi. Dastur o'chirilsa, unga bog'langan yuzlab obyekt «dasturisiz»
 * qolardi va jamlanmalar jim buzilardi. Nofaol dastur yangi obyektga
 * tanlanmaydi, lekin eskilarida ko'rinaveradi.
 */
class ReferenceAdminService
{
    public function __construct(private readonly AdminAuditLogger $audit) {}

    // ---------- Dasturlar ----------

    /** @return Collection<int, array<string, mixed>> */
    public function programs(): Collection
    {
        $usage = ConstructionObject::query()
            ->selectRaw('program_id, count(*) as cnt, coalesce(sum(limit_amount), 0) as amount')
            ->whereNotNull('program_id')
            ->groupBy('program_id')
            ->get()
            ->keyBy('program_id');

        return Program::query()->orderBy('sort_order')->orderBy('name_cyr')->get()
            ->map(fn (Program $p) => [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name_cyr,
                'name_lat' => $p->name_lat,
                'legal_basis' => $p->legal_basis,
                'year' => $p->year,
                'sort_order' => $p->sort_order,
                'is_active' => (bool) $p->is_active,
                'objects' => (int) ($usage[$p->id]->cnt ?? 0),
                'amount' => (float) ($usage[$p->id]->amount ?? 0),
            ]);
    }

    /** @param array<string, mixed> $data */
    public function createProgram(array $data, User $actor): Program
    {
        $program = Program::query()->create($this->programFields($data, null));
        $this->audit->log($actor, 'program_create', 'program', $program->id, $program->name_cyr, $data);

        return $program;
    }

    /** @param array<string, mixed> $data */
    public function updateProgram(string $id, array $data, User $actor): Program
    {
        $program = Program::query()->findOrFail($id);
        $program->update($this->programFields($data, $program));
        $this->audit->log($actor, 'program_update', 'program', $program->id, $program->name_cyr, $data);

        return $program->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function programFields(array $data, ?Program $existing): array
    {
        $code = $this->uniqueCode(Program::query(), $data, $existing, 'Дастур коди');
        $nameCyr = trim((string) ($data['name'] ?? $existing?->name_cyr ?? ''));

        if ($nameCyr === '') {
            throw ValidationException::withMessages(['name' => 'Дастур номини киритинг.']);
        }

        return [
            'code' => $code,
            'name_cyr' => $nameCyr,
            // Lotin nomi ixtiyoriy: kiritilmasa kirill nomi qo'yiladi va
            // eksportda bo'sh katak chiqmaydi.
            'name_lat' => trim((string) ($data['name_lat'] ?? '')) ?: $nameCyr,
            'legal_basis' => $data['legal_basis'] ?? $existing?->legal_basis,
            'year' => $data['year'] ?? $existing?->year,
            'sort_order' => (int) ($data['sort_order'] ?? $existing?->sort_order ?? 0),
            'is_active' => (bool) ($data['is_active'] ?? $existing?->is_active ?? true),
        ];
    }

    // ---------- Sohalar ----------

    /** @return Collection<int, array<string, mixed>> */
    public function sectors(): Collection
    {
        $usage = ConstructionObject::query()
            ->selectRaw('sector_id, count(*) as cnt, coalesce(sum(limit_amount), 0) as amount')
            ->whereNotNull('sector_id')
            ->groupBy('sector_id')
            ->get()
            ->keyBy('sector_id');

        $departments = Organization::query()->where('is_department', true)
            ->pluck('name_cyr', 'id');

        return Sector::query()->orderBy('sort_order')->orderBy('name_cyr')->get()
            ->map(fn (Sector $s) => [
                'id' => $s->id,
                'code' => $s->code,
                'name' => $s->name_cyr,
                'name_lat' => $s->name_lat,
                'default_department_org_id' => $s->default_department_org_id,
                'default_department_name' => $s->default_department_org_id
                    ? ($departments[$s->default_department_org_id] ?? null)
                    : null,
                'sort_order' => $s->sort_order,
                'is_active' => (bool) $s->is_active,
                'objects' => (int) ($usage[$s->id]->cnt ?? 0),
                'amount' => (float) ($usage[$s->id]->amount ?? 0),
            ]);
    }

    /** @param array<string, mixed> $data */
    public function createSector(array $data, User $actor): Sector
    {
        $sector = Sector::query()->create($this->sectorFields($data, null));
        $this->audit->log($actor, 'sector_create', 'sector', $sector->id, $sector->name_cyr, $data);

        return $sector;
    }

    /** @param array<string, mixed> $data */
    public function updateSector(string $id, array $data, User $actor): Sector
    {
        $sector = Sector::query()->findOrFail($id);
        $sector->update($this->sectorFields($data, $sector));
        $this->audit->log($actor, 'sector_update', 'sector', $sector->id, $sector->name_cyr, $data);

        return $sector->refresh();
    }

    /**
     * Sohani o'chirish — FAQAT ishlatilmagan bo'lsa.
     * Ishlatilgani nofaol qilinadi: jamlanmalar buzilmasin.
     */
    public function deleteSector(string $id, User $actor): string
    {
        $sector = Sector::query()->findOrFail($id);
        $used = ConstructionObject::query()->where('sector_id', $id)->count();

        if ($used > 0) {
            $sector->update(['is_active' => false]);
            $this->audit->log($actor, 'sector_disable', 'sector', $id, $sector->name_cyr, ['objects' => $used]);

            return "«{$sector->name_cyr}» {$used} та объектда ишлатилмоқда — ўчирилмади, нофаол қилинди.";
        }

        $this->audit->log($actor, 'sector_delete', 'sector', $id, $sector->name_cyr);
        $sector->delete();

        return "«{$sector->name_cyr}» ўчирилди.";
    }

    /** Dasturni o'chirish — soha bilan bir xil qoida. */
    public function deleteProgram(string $id, User $actor): string
    {
        $program = Program::query()->findOrFail($id);
        $used = ConstructionObject::query()->where('program_id', $id)->count();

        if ($used > 0) {
            $program->update(['is_active' => false]);
            $this->audit->log($actor, 'program_disable', 'program', $id, $program->name_cyr, ['objects' => $used]);

            return "«{$program->name_cyr}» {$used} та объектда ишлатилмоқда — ўчирилмади, нофаол қилинди.";
        }

        $this->audit->log($actor, 'program_delete', 'program', $id, $program->name_cyr);
        $program->delete();

        return "«{$program->name_cyr}» ўчирилди.";
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sectorFields(array $data, ?Sector $existing): array
    {
        $code = $this->uniqueCode(Sector::query(), $data, $existing, 'Соҳа коди');
        $nameCyr = trim((string) ($data['name'] ?? $existing?->name_cyr ?? ''));

        if ($nameCyr === '') {
            throw ValidationException::withMessages(['name' => 'Соҳа номини киритинг.']);
        }

        $dept = $data['default_department_org_id'] ?? $existing?->default_department_org_id;
        if ($dept !== null && $dept !== '' && ! Organization::query()->whereKey($dept)->exists()) {
            throw ValidationException::withMessages(['default_department_org_id' => 'Бошқарма топилмади.']);
        }

        return [
            'code' => $code,
            'name_cyr' => $nameCyr,
            'name_lat' => trim((string) ($data['name_lat'] ?? '')) ?: $nameCyr,
            'default_department_org_id' => $dept ?: null,
            'sort_order' => (int) ($data['sort_order'] ?? $existing?->sort_order ?? 0),
            'is_active' => (bool) ($data['is_active'] ?? $existing?->is_active ?? true),
        ];
    }

    // ---------- Tashkilotlar ----------

    /** @return Collection<int, array<string, mixed>> */
    public function organizations(?string $search = null, ?string $flag = null): Collection
    {
        $customerUse = ConstructionObject::query()
            ->selectRaw('customer_org_id, count(*) as cnt')->whereNotNull('customer_org_id')
            ->groupBy('customer_org_id')->pluck('cnt', 'customer_org_id');

        return Organization::query()
            ->when($search, function ($q) use ($search) {
                $like = '%'.str_replace('%', '\%', $search).'%';
                $q->where(fn ($w) => $w->where('name_cyr', 'ilike', $like)->orWhere('name_lat', 'ilike', $like));
            })
            ->when($flag && in_array($flag, ['is_customer', 'is_designer', 'is_contractor', 'is_department'], true),
                fn ($q) => $q->where($flag, true))
            ->orderBy('name_cyr')
            ->limit(500)
            ->get()
            ->map(fn (Organization $o) => [
                'id' => $o->id,
                'name' => $o->name_cyr,
                'short_name' => $o->short_name,
                'inn' => $o->inn,
                'is_customer' => (bool) $o->is_customer,
                'is_designer' => (bool) $o->is_designer,
                'is_contractor' => (bool) $o->is_contractor,
                'is_department' => (bool) $o->is_department,
                'is_active' => (bool) $o->is_active,
                'objects' => (int) ($customerUse[$o->id] ?? 0),
            ]);
    }

    /** @param array<string, mixed> $data */
    public function saveOrganization(?string $id, array $data, User $actor): Organization
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Ташкилот номини киритинг.']);
        }

        $fields = [
            'name_cyr' => $name,
            'name_lat' => trim((string) ($data['name_lat'] ?? '')) ?: $name,
            'short_name' => $data['short_name'] ?? null,
            'inn' => $data['inn'] ?? null,
            'is_customer' => (bool) ($data['is_customer'] ?? false),
            'is_designer' => (bool) ($data['is_designer'] ?? false),
            'is_contractor' => (bool) ($data['is_contractor'] ?? false),
            'is_department' => (bool) ($data['is_department'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];

        $org = $id === null
            ? Organization::query()->create($fields)
            : tap(Organization::query()->findOrFail($id))->update($fields);

        $this->audit->log(
            $actor,
            $id === null ? 'organization_create' : 'organization_update',
            'organization',
            $org->id,
            $org->name_cyr,
            $fields,
        );

        return $org->refresh();
    }

    // ---------- Umumiy ----------

    /**
     * Kod — inson o'qiydigan barqaror kalit; u UNIKAL bo'lishi shart, aks
     * holda import mos kelmay qoladi. Xato SQL dan emas, shu yerdan chiqadi.
     *
     * @param  Builder<covariant Model>  $query
     * @param  array<string, mixed>  $data
     */
    private function uniqueCode($query, array $data, ?Model $existing, string $label): string
    {
        $code = trim((string) ($data['code'] ?? $existing?->getAttribute('code') ?? ''));

        if ($code === '') {
            throw ValidationException::withMessages(['code' => $label.'ни киритинг.']);
        }

        if (! preg_match('/^[a-z0-9_]+$/', $code)) {
            throw ValidationException::withMessages([
                'code' => $label.' фақат лотин кичик ҳарф, рақам ва пастки чизиқдан иборат бўлади.',
            ]);
        }

        $taken = (clone $query)->where('code', $code)
            ->when($existing !== null, fn ($q) => $q->whereKeyNot($existing->getKey()))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['code' => 'Бу код банд.']);
        }

        return $code;
    }

    /** Administrator jurnali — oxirgi amallar. */
    public function auditLog(int $limit = 100): Collection
    {
        return DB::connection('qurilish')->table('qurilish.admin_audit_log as l')
            ->orderByDesc('l.created_at')
            ->limit($limit)
            ->get(['l.id', 'l.actor_id', 'l.action', 'l.entity', 'l.entity_id',
                'l.entity_label', 'l.ip', 'l.created_at'])
            ->map(fn ($r) => (array) $r);
    }
}
