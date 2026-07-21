<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Services;

use App\Domains\Mahalla\Support\ExecutiveCache;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Ижтимоий шартнома — хонадон кесимида юклаш ва статистика.
 *
 * Shartnoma xonadonga (kadastr binosi) bog'lanadi. Bitta xonadonda bir necha
 * shartnoma bo'lishi mumkin (turli tur: bandlik, tadbirkorlik...). "Qamrov"
 * hisobida esa xonadon BIR MARTA sanaladi — nechta shartnoma emas, nechta
 * xonadon qamralgani muhim.
 *
 * Fayllar maxfiy diskda (`config('mahalla.contracts_disk')`), URL orqali
 * ochib bo'lmaydi — faqat qamrov tekshiruvidan o'tgan kontroller uzatadi.
 * Shartnomada shaxsiy ma'lumot bor.
 */
class ContractService
{
    private function disk(): string
    {
        return (string) config('mahalla.contracts_disk', config('mahalla.photos_disk', 'local'));
    }

    /**
     * Mahalladagi xonadonlar — har biriga shartnoma bor-yo'qligi bilan.
     *
     * @return array{total: int, with_contract: int, items: array<int, array<string, mixed>>}
     */
    public function households(
        string $mahallaId,
        ?string $streetId = null,
        ?bool $onlyWithout = null,
        ?string $search = null,
        int $limit = 300,
    ): array {
        $counts = $this->contractCountByBuilding($mahallaId);

        $base = fn () => DB::connection('master')->table('buildings')
            ->where('buildings.mahalla_id', $mahallaId)
            ->where('buildings.type', 'residential')
            ->when($streetId !== null, fn ($q) => $q->where('buildings.street_id', $streetId))
            ->when($search !== null && $search !== '', function ($q) use ($search) {
                $like = '%'.$search.'%';
                $q->where(fn ($x) => $x->where('buildings.address', 'ilike', $like)
                    ->orWhere('buildings.kadastr', 'ilike', $like));
            });

        $total = (int) $base()->count();

        $rows = $base()
            ->leftJoin('streets as s', 's.id', '=', 'buildings.street_id')
            ->orderBy('s.name')->orderBy('buildings.address')
            ->limit($limit)
            ->get([
                'buildings.id', 'buildings.kadastr', 'buildings.address',
                'buildings.house_number', 's.id as street_id', 's.name as street_name',
            ]);

        $items = [];
        foreach ($rows as $r) {
            $n = $counts[$r->id] ?? 0;
            if ($onlyWithout === true && $n > 0) {
                continue;
            }

            $items[] = [
                'id' => $r->id,
                'kadastr' => $r->kadastr,
                'address' => $r->address,
                'house_number' => $r->house_number,
                'street' => $r->street_id === null ? null
                    : ['id' => $r->street_id, 'name' => $r->street_name],
                'contract_count' => $n,
            ];
        }

        return [
            'total' => $total,
            'with_contract' => count(array_filter($counts, fn ($n) => $n > 0)),
            'items' => $items,
        ];
    }

    /**
     * Bitta xonadon shartnomalari (fayllari bilan).
     *
     * @return array<int, array<string, mixed>>
     */
    public function forBuilding(string $buildingId, string $mahallaId): array
    {
        $contracts = DB::connection('mahalla')->table('social_contracts as c')
            ->leftJoin('contract_types as t', 't.id', '=', 'c.contract_type_id')
            ->where('c.building_id', $buildingId)
            ->where('c.mahalla_id', $mahallaId)
            ->whereNull('c.deleted_at')
            ->orderByDesc('c.signed_at')
            ->get([
                'c.id', 'c.contract_number', 'c.signed_at', 'c.status', 'c.notes',
                'c.contract_type_id', 't.name_cyr as type_name',
            ]);

        if ($contracts->isEmpty()) {
            return [];
        }

        $files = DB::connection('mahalla')->table('contract_files')
            ->whereIn('contract_id', $contracts->pluck('id'))
            ->get(['id', 'contract_id', 'original_name', 'size_bytes'])
            ->groupBy('contract_id');

        return $contracts->map(fn ($c) => [
            'id' => $c->id,
            'contract_number' => $c->contract_number,
            'signed_at' => $c->signed_at,
            'status' => $c->status,
            'notes' => $c->notes,
            'type_id' => $c->contract_type_id,
            'type_name' => $c->type_name,
            'files' => ($files[$c->id] ?? collect())->map(fn ($f) => [
                'id' => $f->id, 'name' => $f->original_name, 'size' => (int) $f->size_bytes,
            ])->values()->all(),
        ])->all();
    }

    /**
     * Yangi shartnoma + PDF.
     *
     * @return array{ok: bool, message?: string, id?: string}
     */
    public function store(
        string $mahallaId,
        string $buildingId,
        array $data,
        UploadedFile $file,
        string $userId,
    ): array {
        $building = DB::connection('master')->table('buildings')
            ->where('id', $buildingId)
            ->where('mahalla_id', $mahallaId)
            ->first(['id', 'district_id', 'street_id']);

        if ($building === null) {
            return ['ok' => false, 'message' => 'Хонадон топилмади'];
        }

        // Raqam mahalla ichida takrorlanmasligi kerak — unique cheklovi bor,
        // lekin tushunarli xabar berish uchun oldindan tekshiramiz.
        $exists = DB::connection('mahalla')->table('social_contracts')
            ->where('mahalla_id', $mahallaId)
            ->where('contract_number', $data['contract_number'])
            ->whereNull('deleted_at')
            ->exists();
        if ($exists) {
            return ['ok' => false, 'message' => 'Бу рақамли шартнома аллақачон мавжуд'];
        }

        $contractId = (string) Str::uuid();
        $path = $file->store("mahalla/contracts/{$mahallaId}/{$contractId}", $this->disk());

        DB::connection('mahalla')->transaction(function () use (
            $contractId, $mahallaId, $building, $buildingId, $data, $userId, $file, $path
        ) {
            DB::connection('mahalla')->table('social_contracts')->insert([
                'id' => $contractId,
                'building_id' => $buildingId,
                'street_id' => $building->street_id,
                'mahalla_id' => $mahallaId,
                'district_id' => $building->district_id,
                'contract_type_id' => $data['contract_type_id'] ?? null,
                'contract_number' => $data['contract_number'],
                'signed_at' => $data['signed_at'] ?? null,
                'status' => $data['status'] ?? 'signed',
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::connection('mahalla')->table('contract_files')->insert([
                'id' => (string) Str::uuid(),
                'contract_id' => $contractId,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime' => $file->getClientMimeType(),
                'size_bytes' => $file->getSize(),
                'uploaded_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        // Qamrov soni panelda keshlangan — yangi shartnoma darhol ko'rinsin.
        ExecutiveCache::flush();

        return ['ok' => true, 'id' => $contractId];
    }

    /** Shartnomani (va fayllarini) o'chiradi. Qamrov shu yerda tekshiriladi. */
    public function delete(string $contractId, string $mahallaId): bool
    {
        $contract = DB::connection('mahalla')->table('social_contracts')
            ->where('id', $contractId)
            ->where('mahalla_id', $mahallaId)
            ->whereNull('deleted_at')
            ->first(['id']);

        if ($contract === null) {
            return false;
        }

        $files = DB::connection('mahalla')->table('contract_files')
            ->where('contract_id', $contractId)->pluck('path');
        foreach ($files as $path) {
            Storage::disk($this->disk())->delete($path);
        }

        DB::connection('mahalla')->table('social_contracts')
            ->where('id', $contractId)
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
        DB::connection('mahalla')->table('contract_files')->where('contract_id', $contractId)->delete();

        ExecutiveCache::flush();

        return true;
    }

    /**
     * Yuklab olish uchun fayl yo'li — qamrov tekshiruvi bilan.
     *
     * @return array{path: string, name: string, disk: string}|null
     */
    public function fileFor(string $fileId, string $mahallaId): ?array
    {
        $file = DB::connection('mahalla')->table('contract_files as f')
            ->join('social_contracts as c', 'c.id', '=', 'f.contract_id')
            ->where('f.id', $fileId)
            ->where('c.mahalla_id', $mahallaId)
            ->whereNull('c.deleted_at')
            ->first(['f.path', 'f.original_name']);

        if ($file === null) {
            return null;
        }

        return ['path' => $file->path, 'name' => $file->original_name, 'disk' => $this->disk()];
    }

    /**
     * Xonadon (building) bo'yicha shartnoma soni.
     *
     * @return array<string, int>
     */
    private function contractCountByBuilding(string $mahallaId): array
    {
        return DB::connection('mahalla')->table('social_contracts')
            ->where('mahalla_id', $mahallaId)
            ->whereNull('deleted_at')
            ->whereNotNull('building_id')
            ->groupBy('building_id')
            ->selectRaw('building_id, count(*) as n')
            ->pluck('n', 'building_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }
}
