<?php

declare(strict_types=1);

namespace Tests\Feature\Yoshlar;

use App\Domains\Yoshlar\Models\Organization;
use App\Domains\Yoshlar\Models\Sector;
use App\Domains\Yoshlar\Services\OrganizationService;
use Illuminate\Validation\ValidationException;

/**
 * Tashkilot yaxlitligi: daraja/tuman/sektor mosligi. Bu qoidalar buzilsa
 * scope noto'g'ri ishlaydi — shuning uchun servis darajasida qattiq tekshiriladi.
 */
class YoshlarOrganizationTest extends YoshlarTestCase
{
    public function test_district_organization_requires_district_id(): void
    {
        $this->expectException(ValidationException::class);

        app(OrganizationService::class)->create([
            'type' => Organization::TYPE_TUMAN_YOSHLAR,
            'name_lat' => 'Tuman bolimi',
            'parent_id' => $this->makeOrganization(Organization::TYPE_VILOYAT_YOSHLAR)->id,
        ]);
    }

    public function test_district_organization_requires_parent(): void
    {
        $this->expectException(ValidationException::class);

        app(OrganizationService::class)->create([
            'type' => Organization::TYPE_TUMAN_YOSHLAR,
            'name_lat' => 'Tuman bolimi',
            'district_id' => $this->someDistrictId(),
        ]);
    }

    public function test_sector_organization_requires_sector_id(): void
    {
        $this->expectException(ValidationException::class);

        app(OrganizationService::class)->create([
            'type' => Organization::TYPE_VILOYAT_SEKTOR,
            'name_lat' => 'Viloyat bandlik boshqarmasi',
        ]);
    }

    public function test_child_sector_must_match_parent_sector(): void
    {
        $bandlik = Sector::query()->create(['code' => 'test_bandlik_'.uniqid(), 'name_cyr' => 'Бандлик', 'name_lat' => 'Bandlik']);
        $talim = Sector::query()->create(['code' => 'test_talim_'.uniqid(), 'name_cyr' => 'Таълим', 'name_lat' => 'Talim']);

        $parent = $this->makeOrganization(Organization::TYPE_VILOYAT_SEKTOR, ['sector_id' => $bandlik->id]);

        $this->expectException(ValidationException::class);

        app(OrganizationService::class)->create([
            'type' => Organization::TYPE_TUMAN_SEKTOR,
            'name_lat' => 'Tuman talim bolimi',
            'district_id' => $this->someDistrictId(),
            'parent_id' => $parent->id,
            'sector_id' => $talim->id,   // otasi bandlik — mos emas
        ]);
    }

    public function test_valid_district_sector_organization_is_created(): void
    {
        $sector = Sector::query()->create(['code' => 'test_s_'.uniqid(), 'name_cyr' => 'Синов', 'name_lat' => 'Sinov']);
        $parent = $this->makeOrganization(Organization::TYPE_VILOYAT_SEKTOR, ['sector_id' => $sector->id]);
        $districtId = $this->someDistrictId();

        $org = app(OrganizationService::class)->create([
            'type' => Organization::TYPE_TUMAN_SEKTOR,
            'name_lat' => 'Tuman sinov bolimi',
            'district_id' => $districtId,
            'parent_id' => $parent->id,
            'sector_id' => $sector->id,
        ]);

        $this->assertSame($districtId, $org->district_id);
        $this->assertSame('Туман синов болими', $org->name_cyr, 'name_cyr avtomatik to‘ldirilmadi');
    }
}
