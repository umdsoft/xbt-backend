<?php

declare(strict_types=1);

namespace Tests\Feature\Yoshlar;

use App\Domains\Yoshlar\Models\Youth;

class YoshlarRegistryRefreshTest extends YoshlarTestCase
{
    public function test_over_age_youth_is_archived_not_deleted(): void
    {
        $districtId = $this->someDistrictId();

        $old = Youth::query()->create([
            'last_name' => 'Katta', 'first_name' => 'Yosh',
            'birth_date' => now()->subYears(32)->toDateString(), 'gender' => 'erkak',
            'district_id' => $districtId, 'mahalla_id' => $this->someMahallaId($districtId),
        ]);
        $young = Youth::query()->create([
            'last_name' => 'Yosh', 'first_name' => 'Bola',
            'birth_date' => now()->subYears(20)->toDateString(), 'gender' => 'erkak',
            'district_id' => $districtId, 'mahalla_id' => $this->someMahallaId($districtId),
        ]);

        $this->artisan('yoshlar:refresh-registry')->assertSuccessful();

        $this->assertSame('archived_age', $old->fresh()->registry_status);
        $this->assertSame('active', $young->fresh()->registry_status);
        $this->assertNotNull(Youth::query()->find($old->id), 'Yozuv o‘chirib yuborilgan');
    }

    public function test_dry_run_changes_nothing(): void
    {
        $districtId = $this->someDistrictId();
        $old = Youth::query()->create([
            'last_name' => 'Katta', 'first_name' => 'Yosh',
            'birth_date' => now()->subYears(35)->toDateString(), 'gender' => 'ayol',
            'district_id' => $districtId, 'mahalla_id' => $this->someMahallaId($districtId),
        ]);

        $this->artisan('yoshlar:refresh-registry --dry-run')->assertSuccessful();

        $this->assertSame('active', $old->fresh()->registry_status);
    }
}
