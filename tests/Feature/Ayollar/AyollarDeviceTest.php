<?php

declare(strict_types=1);

namespace Tests\Feature\Ayollar;

use App\Domains\Ayollar\Models\AuditLog;
use App\Domains\Ayollar\Models\Staff;
use App\Domains\Ayollar\Support\AyollarAccess;

/**
 * QURILMANI RO'YXATGA OLISH (promt §11 «PIN + qurilma ID»).
 *
 * Markaziy identifikatsiya qurilma haqida bilmaydi — u 8 ta tizimga
 * umumiy. Shuning uchun qurilma kirgandan KEYIN alohida qayd etiladi.
 */
class AyollarDeviceTest extends AyollarApiTestCase
{
    public function test_device_is_recorded_on_staff(): void
    {
        $d = $this->someDistrictId();
        $m = $this->someMahallaId($d);
        $user = $this->makeUser(AyollarAccess::ROLE_ACTIVIST, ['mahalla_id' => $m, 'district_id' => $d]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/ayollar/device/register', [
                'device_id' => 'DEV-TEST01',
                'platform' => 'android',
                'app_version' => '1.0.0',
            ])
            ->assertOk()
            ->assertJsonPath('registered', true);

        $staff = Staff::query()->where('user_id', $user->id)->first();

        $this->assertSame('DEV-TEST01', $staff->last_device_id);
        $this->assertSame('android', $staff->last_platform);
        $this->assertNotNull($staff->last_seen_at);
    }

    /**
     * BIR XIL qurilmadan qayta kirish jurnalga TUSHMAYDI.
     *
     * Aks holda jurnal har kunlik kirish bilan to'lardi va haqiqiy
     * hodisa — planshet almashgani — ko'rinmay qolardi.
     */
    public function test_same_device_does_not_spam_audit(): void
    {
        $d = $this->someDistrictId();
        $m = $this->someMahallaId($d);
        $user = $this->makeUser(AyollarAccess::ROLE_ACTIVIST, ['mahalla_id' => $m, 'district_id' => $d]);

        $before = AuditLog::query()->where('action', 'device.changed')->count();

        foreach ([1, 2, 3] as $_) {
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/ayollar/device/register', ['device_id' => 'DEV-SAME'])
                ->assertOk();
        }

        $this->assertSame($before, AuditLog::query()->where('action', 'device.changed')->count());
    }

    /** Qurilma ALMASHGANI jurnalga tushadi. */
    public function test_device_change_is_logged(): void
    {
        $d = $this->someDistrictId();
        $m = $this->someMahallaId($d);
        $user = $this->makeUser(AyollarAccess::ROLE_ACTIVIST, ['mahalla_id' => $m, 'district_id' => $d]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/ayollar/device/register', ['device_id' => 'DEV-ESKI'])->assertOk();

        $before = AuditLog::query()->where('action', 'device.changed')->count();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/ayollar/device/register', ['device_id' => 'DEV-YANGI'])->assertOk();

        $this->assertSame($before + 1, AuditLog::query()->where('action', 'device.changed')->count());

        $row = AuditLog::query()->where('action', 'device.changed')->latest('created_at')->first();

        $this->assertSame('DEV-ESKI', $row->changes['dan']);
        $this->assertSame('DEV-YANGI', $row->changes['ga']);
    }

    /**
     * Doira yozuvisiz foydalanuvchi — XATO EMAS.
     *
     * Veb brauzerdan kirgan tahlilchida `staff` bo'lmasligi normal;
     * kirish qurilma qaydiga bog'liq bo'lmasligi kerak.
     */
    public function test_user_without_staff_is_not_an_error(): void
    {
        $user = $this->makeUser(AyollarAccess::ROLE_ANALYST);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/ayollar/device/register', ['device_id' => 'DEV-X'])
            ->assertOk()
            ->assertJsonPath('registered', false);
    }

    public function test_device_id_is_required(): void
    {
        $d = $this->someDistrictId();
        $user = $this->makeUser(AyollarAccess::ROLE_ACTIVIST, [
            'mahalla_id' => $this->someMahallaId($d), 'district_id' => $d,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/ayollar/device/register', [])
            ->assertStatus(422);
    }

    /** Faollar monitoringida qurilma va oxirgi kirish ko'rinadi. */
    public function test_activists_report_includes_device(): void
    {
        $d = $this->someDistrictId();
        $m = $this->someMahallaId($d);

        $activist = $this->makeUser(AyollarAccess::ROLE_ACTIVIST, ['mahalla_id' => $m, 'district_id' => $d]);
        $this->anketaIn($m, $d, createdBy: $activist);

        $this->actingAs($activist, 'sanctum')
            ->postJson('/api/ayollar/device/register', ['device_id' => 'DEV-RPT'])->assertOk();

        $family = $this->makeUser(AyollarAccess::ROLE_FAMILY_DEPT, ['district_id' => $d]);

        $this->actingAs($family, 'sanctum')
            ->getJson('/api/ayollar/analytics/activists')
            ->assertOk()
            ->assertJsonStructure(['activists' => [['user_id', 'quality', 'device_id', 'last_seen_at']]]);
    }
}
