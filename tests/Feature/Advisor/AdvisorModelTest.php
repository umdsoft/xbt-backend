<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor;

use App\Domains\Advisor\Models\Advisor;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Advisor modeli + district() relation (master.districts) ishlashini tekshiradi.
 */
class AdvisorModelTest extends TestCase
{
    use DatabaseTransactions;

    /** Ko'p sxemali: har ulanish alohida rollback (dev bazaga sizmasin). */
    protected array $connectionsToTransact = ['pgsql', 'auth', 'master', 'advisor'];

    public function test_tuman_advisor_resolves_district_relation(): void
    {
        $district = DB::connection('master')->table('districts')
            ->whereNotNull('soato_code')->orderBy('sort_order')
            ->first(['id', 'name_cyr']);

        $this->assertNotNull($district, 'master.districts bo\'sh — geo fikstura kerak.');

        $advisor = Advisor::create([
            'user_id' => (string) Str::uuid(),
            'level' => 'tuman',
            'district_id' => $district->id,
            'position' => 'Тест тумани маслаҳатчиси',
            'active' => true,
        ]);

        $fresh = Advisor::query()->with('district')->findOrFail($advisor->id);

        $this->assertSame('tuman', $fresh->level);
        $this->assertTrue($fresh->active);
        $this->assertNotNull($fresh->district);
        $this->assertSame((string) $district->id, (string) $fresh->district->id);
        $this->assertSame($district->name_cyr, $fresh->district->name_cyr);
    }

    public function test_viloyat_advisor_has_null_district(): void
    {
        $advisor = Advisor::create([
            'user_id' => (string) Str::uuid(),
            'level' => 'viloyat',
            'district_id' => null,
            'active' => true,
        ]);

        $fresh = Advisor::query()->with('district')->findOrFail($advisor->id);

        $this->assertSame('viloyat', $fresh->level);
        $this->assertNull($fresh->district);
    }
}
