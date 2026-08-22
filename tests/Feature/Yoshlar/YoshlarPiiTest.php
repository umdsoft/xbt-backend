<?php

declare(strict_types=1);

namespace Tests\Feature\Yoshlar;

use App\Domains\Yoshlar\Models\Youth;
use Illuminate\Support\Facades\DB;

/**
 * PII: kim ocha oladi, ochilish jurnalga tushadimi, ruxsatsizga sizadimi.
 */
class YoshlarPiiTest extends YoshlarTestCase
{
    public function test_authorized_role_reveals_pinfl_and_it_is_logged(): void
    {
        $youth = $this->youthWithPinfl();
        $user = $this->makeUser('yoshlar_boshqarma');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/yoshlar/youth/{$youth->id}/reveal-pii")
            ->assertOk()
            ->assertJsonPath('pinfl', $youth->pinfl);

        $logged = DB::connection('yoshlar')->table('pii_access_log')
            ->where('user_id', $user->id)->where('youth_id', $youth->id)->count();

        $this->assertSame(1, $logged, 'PII ochilishi jurnalga tushmadi');
    }

    public function test_hokim_orinbosari_cannot_reveal(): void
    {
        $youth = $this->youthWithPinfl();

        $this->actingAs($this->makeUser('yoshlar_hokim_orinbosari'), 'sanctum')
            ->postJson("/api/yoshlar/youth/{$youth->id}/reveal-pii")
            ->assertStatus(403);
    }

    public function test_sector_boshqarma_cannot_reveal(): void
    {
        $youth = $this->youthWithPinfl();

        $this->actingAs($this->makeUser('sektor_boshqarma'), 'sanctum')
            ->postJson("/api/yoshlar/youth/{$youth->id}/reveal-pii")
            ->assertStatus(403);
    }

    public function test_show_endpoint_never_returns_pii(): void
    {
        $youth = $this->youthWithPinfl();

        $body = $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->getJson("/api/yoshlar/youth/{$youth->id}")
            ->assertOk()->getContent();

        $this->assertStringNotContainsString($youth->pinfl, $body);
    }

    public function test_audit_log_does_not_store_pii_values(): void
    {
        $youth = $this->youthWithPinfl();
        $user = $this->makeUser('yoshlar_admin');

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/yoshlar/youth/{$youth->id}", ['pinfl' => '31111111111111'])
            ->assertOk();

        $changes = DB::connection('yoshlar')->table('audit_log')
            ->where('entity_id', $youth->id)->where('action', 'youth.update')
            ->value('changes');

        $this->assertStringNotContainsString('31111111111111', (string) $changes);
    }

    private function youthWithPinfl(): Youth
    {
        $districtId = $this->someDistrictId();

        return Youth::query()->create([
            'last_name' => 'Maxfiy',
            'first_name' => 'Yosh',
            'birth_date' => now()->subYears(22)->toDateString(),
            'gender' => 'erkak',
            'district_id' => $districtId,
            'mahalla_id' => $this->someMahallaId($districtId),
            'pinfl' => '3'.random_int(1000000000000, 9999999999999),
        ]);
    }
}
