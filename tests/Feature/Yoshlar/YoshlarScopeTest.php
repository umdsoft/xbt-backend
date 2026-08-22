<?php

declare(strict_types=1);

namespace Tests\Feature\Yoshlar;

use App\Domains\Yoshlar\Models\Organization;
use App\Domains\Yoshlar\Models\Staff;
use App\Domains\Yoshlar\Support\YoshlarScope;

/**
 * Ko'rish doirasi. Eng muhim tekshiruv — FAIL-CLOSED: profil topilmasa
 * foydalanuvchi HECH NARSA ko'rmaydi (hammasini emas).
 */
class YoshlarScopeTest extends YoshlarTestCase
{
    public function test_staffless_role_sees_nothing(): void
    {
        $user = $this->makeUser('yoshlar_bolim');   // staff yozuvi YO'Q

        $this->assertSame([], app(YoshlarScope::class)->districtIds($user));
    }

    public function test_province_roles_are_unrestricted(): void
    {
        foreach (['yoshlar_admin', 'yoshlar_hokim_orinbosari', 'yoshlar_boshqarma'] as $role) {
            $this->assertNull(
                app(YoshlarScope::class)->districtIds($this->makeUser($role)),
                "«{$role}» viloyat darajasida cheklanmasligi kerak",
            );
        }
    }

    public function test_district_role_is_limited_to_own_district(): void
    {
        $districtId = $this->someDistrictId();
        $org = $this->makeOrganization(Organization::TYPE_TUMAN_YOSHLAR, ['district_id' => $districtId]);
        $user = $this->makeUser('yoshlar_bolim', $org->id);

        $this->assertSame([$districtId], app(YoshlarScope::class)->districtIds($user));
        $this->assertTrue(app(YoshlarScope::class)->canTouchDistrict($user, $districtId));
        $this->assertFalse(
            app(YoshlarScope::class)->canTouchDistrict($user, $this->otherDistrictId($districtId)),
        );
    }

    public function test_sector_boshqarma_sees_all_districts_but_own_org_subtree(): void
    {
        $parent = $this->makeOrganization(Organization::TYPE_VILOYAT_SEKTOR);
        $child = $this->makeOrganization(Organization::TYPE_TUMAN_SEKTOR, [
            'parent_id' => $parent->id,
            'district_id' => $this->someDistrictId(),
        ]);
        $user = $this->makeUser('sektor_boshqarma', $parent->id);

        // Reyestr geo bo'yicha cheklanmaydi — yosh sektorga tegishli emas.
        $this->assertNull(app(YoshlarScope::class)->districtIds($user));

        $orgIds = app(YoshlarScope::class)->orgIds($user);
        $this->assertContains($parent->id, $orgIds);
        $this->assertContains($child->id, $orgIds);
    }

    public function test_inactive_staff_sees_nothing(): void
    {
        $org = $this->makeOrganization(Organization::TYPE_TUMAN_SEKTOR, [
            'district_id' => $this->someDistrictId(),
        ]);
        $user = $this->makeUser('sektor_bolim', $org->id);

        Staff::query()->where('user_id', $user->id)->update(['is_active' => false]);

        $this->assertSame([], app(YoshlarScope::class)->districtIds($user));
    }
}
