<?php

declare(strict_types=1);

namespace Tests\Feature\Murojaat;

use App\Domains\Murojaat\Models\ImportSession;
use Illuminate\Support\Carbon;

/**
 * Import + tahlil yadrosi: import -> is_active sessiya + appeals; dashboard/appeals/
 * kpi/mahalla/statistika/sayyor/dynamics. Sayyor asosiy statistikadan ajratiladi.
 */
class MurojaatCoreTest extends MurojaatTestCase
{
    public function test_import_creates_active_session_and_appeals(): void
    {
        $d = $this->someDistrictId();
        $admin = $this->makeUser('murojaat_admin', 'tuman', $d);

        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/murojaat/import', [
            'file_name' => 'test.xlsx',
            'rows' => [
                $this->row(['natija_holat' => 'Ижобий ҳал этилди']),
                $this->row(['natija_holat' => '', 'kelgan_sana' => Carbon::now()->subDays(40)->format('d.m.Y')]),
                $this->row(['sayyor_tashkilot' => 'Урганч ҳокимлиги', 'natija_holat' => 'Ижрода']),
                ['familiya' => 'Raqamsiz'], // murojaat_raqami yo'q -> tashlanadi
            ],
        ])->assertCreated()->json();

        $this->assertSame(3, $res['count']);      // raqamsiz tashlandi
        $this->assertSame(1, $res['sayyor_count']);

        $session = ImportSession::find($res['session_id']);
        $this->assertTrue($session->is_active);
        $this->assertSame((string) $d, (string) $session->district_id);
    }

    public function test_reimport_deactivates_previous(): void
    {
        $d = $this->someDistrictId();
        $admin = $this->makeUser('murojaat_admin', 'tuman', $d);

        $first = $this->actingAs($admin, 'sanctum')->postJson('/api/murojaat/import', [
            'rows' => [$this->row()],
        ])->assertCreated()->json('session_id');

        $this->actingAs($admin, 'sanctum')->postJson('/api/murojaat/import', [
            'rows' => [$this->row(), $this->row()],
        ])->assertCreated();

        $this->assertFalse(ImportSession::find($first)->is_active);
        $this->assertSame(1, ImportSession::where('district_id', $d)->where('is_active', true)->count());
    }

    public function test_dashboard_counts_exclude_sayyor(): void
    {
        $d = $this->someDistrictId();
        $admin = $this->makeUser('murojaat_admin', 'tuman', $d);

        $this->actingAs($admin, 'sanctum')->postJson('/api/murojaat/import', [
            'rows' => [
                $this->row(['natija_holat' => 'Ижобий ҳал этилди']),
                $this->row(['natija_holat' => 'Ижобий ҳал этилди']),
                $this->row(['natija_holat' => '', 'kelgan_sana' => Carbon::now()->subDays(40)->format('d.m.Y')]),
                $this->row(['sayyor_tashkilot' => 'Ҳокимлик', 'natija_holat' => 'Ижрода']),
            ],
        ])->assertCreated();

        $dash = $this->actingAs($admin, 'sanctum')->getJson('/api/murojaat/dashboard')->assertOk()->json();
        $this->assertFalse($dash['empty']);
        $this->assertSame(3, $dash['kpi']['total']);   // sayyor chiqarildi
        $this->assertSame(2, $dash['kpi']['hal']);
        $this->assertSame(1, $dash['kpi']['kechikkan']);
        $this->assertSame(1, $dash['sayyor_count']);
    }

    public function test_analysis_endpoints_ok(): void
    {
        $d = $this->someDistrictId();
        $admin = $this->makeUser('murojaat_admin', 'tuman', $d);
        $this->actingAs($admin, 'sanctum')->postJson('/api/murojaat/import', [
            'rows' => [
                $this->row(['natija_holat' => 'Ижобий ҳал этилди', 'qaerdan' => 'Халқ қабулхонаси']),
                $this->row(['natija_holat' => 'Рад этилди', 'qaerdan' => 'Президент виртуал қабулхонаси']),
                $this->row(['sayyor_tashkilot' => 'Ҳокимлик']),
            ],
        ])->assertCreated();

        $this->actingAs($admin, 'sanctum')->getJson('/api/murojaat/appeals')->assertOk()
            ->assertJsonStructure(['data', 'total', 'page', 'per_page']);
        $this->actingAs($admin, 'sanctum')->getJson('/api/murojaat/kpi')->assertOk()->assertJsonStructure(['ranking']);
        $this->actingAs($admin, 'sanctum')->getJson('/api/murojaat/mahalla')->assertOk()->assertJsonStructure(['rows']);
        $this->actingAs($admin, 'sanctum')->getJson('/api/murojaat/statistika')->assertOk()->assertJsonStructure(['rows']);
        $this->actingAs($admin, 'sanctum')->getJson('/api/murojaat/sayyor')->assertOk()
            ->assertJsonPath('kpi.total', 1);
        $this->actingAs($admin, 'sanctum')->getJson('/api/murojaat/dynamics?period=oy')->assertOk()
            ->assertJsonStructure(['period', 'groups']);
    }
}
