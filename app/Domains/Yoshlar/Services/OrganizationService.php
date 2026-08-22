<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Services;

use App\Domains\Yoshlar\Models\Organization;
use App\Domains\Yoshlar\Support\Translit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tashkilot yaxlitligi. Bu qoidalar DB CHECK emas, servisda — chunki
 * kelajakda yangi tur qo'shilganda migratsiya emas, kod o'zgaradi.
 *
 * MUHIM: qoidalar buzilsa YoshlarScope noto'g'ri ishlaydi (tuman tashkiloti
 * district_id siz -> foydalanuvchi hech narsa ko'rmaydi yoki hammasini ko'radi).
 */
class OrganizationService
{
    /** @param array<string, mixed> $data */
    public function create(array $data): Organization
    {
        $data = $this->validated($data);

        return Organization::query()->create($data);
    }

    /** @param array<string, mixed> $data */
    public function update(Organization $org, array $data): Organization
    {
        $merged = $this->validated(array_merge($org->only([
            'type', 'parent_id', 'district_id', 'sector_id', 'name_cyr', 'name_lat', 'short_name',
        ]), $data));

        $org->update($merged);

        return $org->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validated(array $data): array
    {
        $type = (string) ($data['type'] ?? '');

        if (! in_array($type, Organization::TYPES, true)) {
            throw ValidationException::withMessages([
                'type' => "«{$type}» — noto‘g‘ri tashkilot turi.",
            ]);
        }

        $isDistrict = in_array($type, [Organization::TYPE_TUMAN_YOSHLAR, Organization::TYPE_TUMAN_SEKTOR], true);
        $isSector = in_array($type, [Organization::TYPE_VILOYAT_SEKTOR, Organization::TYPE_TUMAN_SEKTOR], true);

        if ($isDistrict && empty($data['district_id'])) {
            throw ValidationException::withMessages([
                'district_id' => 'Tuman darajasidagi tashkilot uchun tuman majburiy.',
            ]);
        }

        if ($isDistrict && empty($data['parent_id'])) {
            throw ValidationException::withMessages([
                'parent_id' => 'Tuman tashkiloti viloyat tashkilotiga bog‘lanishi shart.',
            ]);
        }

        if ($isSector && empty($data['sector_id'])) {
            throw ValidationException::withMessages([
                'sector_id' => 'Sektoral tashkilot uchun sektor majburiy.',
            ]);
        }

        if (! $isDistrict) {
            $data['district_id'] = null;
        }

        if (! $isSector) {
            $data['sector_id'] = null;
        }

        if (! empty($data['district_id']) && ! $this->districtExists((string) $data['district_id'])) {
            throw ValidationException::withMessages(['district_id' => 'Bunday tuman topilmadi.']);
        }

        if (! empty($data['parent_id'])) {
            $this->assertParentMatches($type, (string) $data['parent_id'], $data['sector_id'] ?? null);
        }

        $data['name_lat'] = trim((string) ($data['name_lat'] ?? ''));
        if ($data['name_lat'] === '') {
            throw ValidationException::withMessages(['name_lat' => 'Nom bo‘sh bo‘lishi mumkin emas.']);
        }

        // Kirill nomi berilmasa — lotin nomidan hosil qilinadi (keyin tahrirlanadi).
        $data['name_cyr'] = trim((string) ($data['name_cyr'] ?? '')) ?: Translit::toCyr($data['name_lat']);

        return $data;
    }

    private function districtExists(string $districtId): bool
    {
        return DB::connection('master')->table('districts')->where('id', $districtId)->exists();
    }

    private function assertParentMatches(string $type, string $parentId, ?string $sectorId): void
    {
        $parent = Organization::query()->find($parentId);

        if ($parent === null) {
            throw ValidationException::withMessages(['parent_id' => 'Yuqori tashkilot topilmadi.']);
        }

        $expected = $type === Organization::TYPE_TUMAN_SEKTOR
            ? Organization::TYPE_VILOYAT_SEKTOR
            : Organization::TYPE_VILOYAT_YOSHLAR;

        if ($parent->type !== $expected) {
            throw ValidationException::withMessages([
                'parent_id' => "Yuqori tashkilot turi «{$expected}» bo‘lishi kerak.",
            ]);
        }

        if ($type === Organization::TYPE_TUMAN_SEKTOR && $parent->sector_id !== $sectorId) {
            throw ValidationException::withMessages([
                'sector_id' => 'Tuman bo‘limining sektori yuqori boshqarma sektoriga mos emas.',
            ]);
        }
    }
}
