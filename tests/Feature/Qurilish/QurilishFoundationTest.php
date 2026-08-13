<?php

declare(strict_types=1);

namespace Tests\Feature\Qurilish;

use App\Domains\Qurilish\Models\ConstructionObject;
use App\Domains\Qurilish\Models\ObjectStage;
use App\Domains\Qurilish\Models\Organization;
use App\Domains\Qurilish\Models\Program;
use App\Domains\Qurilish\Models\Sector;
use App\Domains\Qurilish\Support\QurilishAccess;
use App\Domains\Qurilish\Support\QurilishScope;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * F1 poydevori: schema, spravochniklar, modellar, scope (IDOR) va
 * hisoblanadigan `is_overdue` xossasi.
 */
class QurilishFoundationTest extends QurilishTestCase
{
    public function test_schema_has_all_tables(): void
    {
        $tables = DB::connection('qurilish')->table('information_schema.tables')
            ->where('table_schema', 'qurilish')->pluck('table_name')->all();

        foreach ([
            'programs', 'sectors', 'organizations', 'organization_aliases',
            'objects', 'object_stages', 'object_monthly_plan', 'object_documents',
            'object_audit_log', 'repair_needs', 'repair_need_files',
            'profiles', 'import_sessions',
        ] as $table) {
            $this->assertContains($table, $tables, "Jadval yo'q: {$table}");
        }
    }

    public function test_reference_data_is_seeded(): void
    {
        $this->assertGreaterThanOrEqual(8, Program::query()->count());
        $this->assertGreaterThanOrEqual(20, Sector::query()->count());
        $this->assertGreaterThanOrEqual(12, Organization::query()->where('is_department', true)->count());
    }

    public function test_sector_resolves_its_default_department(): void
    {
        $sector = Sector::query()->where('code', 'umumtalim_maktab')->firstOrFail();

        $this->assertNotNull($sector->default_department_org_id);
        $this->assertTrue($sector->defaultDepartment->is_department);
    }

    public function test_object_is_overdue_is_computed_not_stored(): void
    {
        $object = ConstructionObject::query()->create([
            'name' => 'Синов объекти',
            'deadline_date' => now()->subDay()->toDateString(),
            'handover_done' => false,
        ]);

        $this->assertTrue($object->is_overdue);

        $object->update(['handover_done' => true]);
        $this->assertFalse($object->fresh()->is_overdue);

        // Ustun sifatida SAQLANMAYDI — jonli hisoblanadi.
        $columns = DB::connection('qurilish')->getSchemaBuilder()->getColumnListing('objects');
        $this->assertNotContains('is_overdue', $columns);
    }

    public function test_object_without_deadline_is_not_overdue(): void
    {
        $object = ConstructionObject::query()->create(['name' => 'Муддатсиз']);

        $this->assertFalse($object->is_overdue);
    }

    public function test_stage_is_unique_per_object(): void
    {
        $object = ConstructionObject::query()->create(['name' => 'Синов']);
        ObjectStage::query()->create(['object_id' => $object->id, 'stage_code' => 'tender']);

        $this->expectException(UniqueConstraintViolationException::class);
        ObjectStage::query()->create(['object_id' => $object->id, 'stage_code' => 'tender']);
    }

    public function test_scope_isolates_buyurtmachi_from_other_orgs(): void
    {
        $orgA = $this->makeOrganization('Ташкилот А', ['is_customer' => true]);
        $orgB = $this->makeOrganization('Ташкилот Б', ['is_customer' => true]);

        ConstructionObject::query()->create(['name' => 'A объекти', 'customer_org_id' => $orgA]);
        ConstructionObject::query()->create(['name' => 'Б объекти', 'customer_org_id' => $orgB]);

        $visible = app(QurilishScope::class)
            ->apply(ConstructionObject::query(), $this->makeUser('qurilish_buyurtmachi', $orgA))
            ->pluck('name')->all();

        $this->assertContains('A объекти', $visible);
        $this->assertNotContains('Б объекти', $visible);
    }

    public function test_scope_isolates_boshqarma_by_department(): void
    {
        $deptA = $this->makeOrganization('Бошқарма А', ['is_department' => true]);
        $deptB = $this->makeOrganization('Бошқарма Б', ['is_department' => true]);

        ConstructionObject::query()->create(['name' => 'A бошқарма объекти', 'department_org_id' => $deptA]);
        ConstructionObject::query()->create(['name' => 'Б бошқарма объекти', 'department_org_id' => $deptB]);

        $visible = app(QurilishScope::class)
            ->apply(ConstructionObject::query(), $this->makeUser('qurilish_boshqarma', $deptA))
            ->pluck('name')->all();

        $this->assertContains('A бошқарма объекти', $visible);
        $this->assertNotContains('Б бошқарма объекти', $visible);
    }

    public function test_scope_without_profile_org_returns_nothing(): void
    {
        ConstructionObject::query()->create(['name' => 'Кўринмаслиги керак']);

        // Tashkilotsiz buyurtmachi — fail-closed (hammasi emas, HECH NARSA).
        $count = app(QurilishScope::class)
            ->apply(ConstructionObject::query(), $this->makeUser('qurilish_buyurtmachi', null))
            ->count();

        $this->assertSame(0, $count);
    }

    public function test_viewer_and_admin_see_everything(): void
    {
        ConstructionObject::query()->create(['name' => 'Ҳар ким кўради']);

        foreach (['qurilish_hokimlik', 'qurilish_prokuratura', 'qurilish_admin'] as $role) {
            $user = $this->makeUser($role);

            $this->assertTrue(app(QurilishAccess::class)->seesEverything($user), $role);
            $this->assertGreaterThan(
                0,
                app(QurilishScope::class)->apply(ConstructionObject::query(), $user)->count(),
                $role,
            );
        }
    }

    public function test_owns_check_matches_scope(): void
    {
        $orgA = $this->makeOrganization('Ташкилот А', ['is_customer' => true]);
        $orgB = $this->makeOrganization('Ташкилот Б', ['is_customer' => true]);
        $scope = app(QurilishScope::class);
        $user = $this->makeUser('qurilish_buyurtmachi', $orgA);

        $this->assertTrue($scope->owns($user, $orgA, null));
        $this->assertFalse($scope->owns($user, $orgB, null));
        $this->assertTrue($scope->owns($this->makeUser('qurilish_hokimlik'), $orgB, null));
    }
}
