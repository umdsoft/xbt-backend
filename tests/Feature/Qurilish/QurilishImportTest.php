<?php

declare(strict_types=1);

namespace Tests\Feature\Qurilish;

use App\Domains\Qurilish\Models\ConstructionObject;
use App\Domains\Qurilish\Models\ObjectMonthlyPlan;
use App\Domains\Qurilish\Models\ObjectStage;
use App\Domains\Qurilish\Models\Organization;
use App\Domains\Qurilish\Models\Sector;
use App\Domains\Qurilish\Services\ObjectImporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ETL importi: obyekt/bosqich/oylik yozilishi, boshqarma biriktirilishi,
 * sun'iy kalit, idempotentlik.
 *
 * Fikstura xlsx'dagi HAQIQIY qatorlardan olingan (ПҚ-393 R8), lekin
 * `external_id` ataylab `99` yili bilan boshlanadi: testlar UMUMIY dev
 * bazasida yuradi va u yerda haqiqiy import ma'lumoti (611 obyekt) turishi
 * mumkin. `99` prefiksi to'qnashuvni imkonsiz qiladi, SOATO derivatsiyasi esa
 * [4:9] pozitsiyasiga tayangani uchun o'zgarmaydi.
 *
 * SHU SABABLI: barcha sanoq assertion'lari `scoped()` orqali FAQAT shu
 * testning obyektlarini hisoblaydi — `count()` ni to'g'ridan-to'g'ri chaqirish
 * «jadval bo'sh» degan noto'g'ri taxmindir.
 */
class QurilishImportTest extends QurilishTestCase
{
    private const ID_A = '9901334060102001';   // -> 1733406 Xiva shahri

    private const ID_B = '9901332170101010';   // -> 1733217 Urganch tumani

    public function test_import_creates_object_with_all_derived_fields(): void
    {
        $report = $this->import([$this->row()]);

        $this->assertSame(1, $report['objects']);
        $this->assertSame(8, $report['stages']);

        $object = $this->find(self::ID_A);

        $this->assertSame('pq393', $object->program->code);
        $this->assertSame('umumtalim_maktab', $object->sector->code);
        $this->assertSame('yangi_qurish', $object->work_type);
        $this->assertSame('12000.000', $object->limit_amount);
        $this->assertSame('12010.000', $object->contract_amount);
        $this->assertSame('2026-08-15', $object->deadline_date->toDateString());
        $this->assertSame(2026, $object->deadline_year);
        $this->assertSame('import', $object->source);
        $this->assertNotNull($object->customer_org_id);
        $this->assertNotNull($object->designer_org_id);
        $this->assertNotNull($object->contractor_org_id);
    }

    public function test_import_resolves_district_from_object_id_not_column(): void
    {
        // c_value ataylab XATO ('Урганч.т'), ID esa Xiva shahrini ko'rsatadi.
        // Manbada aynan shunday 12 ta obyekt bor — ID hokim bo'lishi SHART.
        $this->import([$this->row(['c_value' => 'Урганч.т'])]);

        $soato = DB::connection('master')->table('districts')
            ->where('id', $this->find(self::ID_A)->district_id)->value('soato_code');

        $this->assertSame('1733406', (string) $soato);
    }

    public function test_import_attaches_department_from_sector(): void
    {
        $this->import([$this->row()]);

        $object = $this->find(self::ID_A);
        $sector = Sector::query()->where('code', 'umumtalim_maktab')->firstOrFail();

        $this->assertNotNull($object->department_org_id);
        $this->assertSame($sector->default_department_org_id, $object->department_org_id);
        $this->assertTrue(Organization::query()->findOrFail($object->department_org_id)->is_department);
    }

    public function test_import_writes_all_eight_stages_with_mapped_statuses(): void
    {
        $this->import([$this->row()]);

        $stages = ObjectStage::query()
            ->where('object_id', $this->find(self::ID_A)->id)
            ->pluck('status', 'stage_code');

        $this->assertCount(8, $stages);
        $this->assertSame('yakunlangan', $stages['designer_selection']);
        $this->assertSame('yakunlangan', $stages['design_estimate']);
        $this->assertSame('yakunlangan', $stages['urban_planning']);
        $this->assertSame('yakunlangan', $stages['complex_expertise']);
        $this->assertSame('yakunlangan', $stages['tender']);
        $this->assertSame('yakunlangan', $stages['contract']);
        $this->assertSame('yakunlangan', $stages['execution']);
        $this->assertSame('boshlanmagan', $stages['handover']);
    }

    public function test_import_uses_synthetic_key_when_object_id_missing(): void
    {
        // 33 DXSh varag'ida Объект ID umuman yo'q — kalit {dastur}:{qator}.
        $this->import([$this->row([
            'external_id' => '', 'program_code' => 'dxsh', 'row' => '9907',
            'group_label' => 'Урганч шаҳар', 'c_value' => 'МТТ',
        ])]);

        $object = $this->find('dxsh:9907');

        $this->assertSame('mtt', $object->sector->code);
        // Tuman guruh-qatordan olinadi (ID yo'q).
        $this->assertNotNull($object->district_id);
    }

    public function test_import_is_idempotent(): void
    {
        $rows = [$this->row(), $this->row(['external_id' => self::ID_B, 'name' => 'Иккинчи объект'])];

        $this->import($rows);
        $first = $this->snapshot();

        $this->import($rows);

        $this->assertSame($first, $this->snapshot());
        $this->assertSame(2, $first['objects']);
        $this->assertSame(16, $first['stages']);
    }

    public function test_duplicate_registry_id_keeps_both_objects(): void
    {
        // Manbada 2505334010717007 ID si ikki BOSHQA obyektga berilgan (manba xatosi):
        // «Урганч давлат тиббиёт институти...» 12 750 mln va «Хоразм академик
        // лицейи...» 1 310 mln. Hech biri yo'qolmasligi kerak.
        $report = $this->import([
            $this->row(['name' => 'Биринчи объект', 'limit_amount' => '12750.0']),
            $this->row(['row' => '219', 'name' => 'Иккинчи объект', 'limit_amount' => '1310.0']),
        ]);

        $this->assertSame(1, $report['duplicates']);
        $this->assertSame(2, $this->scoped()->count());
        $this->assertSame(14060.0, (float) $this->scoped()->sum('limit_amount'));

        $this->assertSame('Биринчи объект', $this->find(self::ID_A)->name);
        $this->assertSame('Иккинчи объект', $this->find(self::ID_A.'#219')->name);
    }

    public function test_import_loads_monthly_plan(): void
    {
        $this->import(
            [$this->row()],
            [
                ['external_key' => self::ID_A, 'month' => '5', 'planned_amount' => '3000.0'],
                ['external_key' => self::ID_A, 'month' => '6', 'planned_amount' => '3000.0'],
            ],
        );

        $rows = ObjectMonthlyPlan::query()->where('object_id', $this->find(self::ID_A)->id)->get();

        $this->assertCount(2, $rows);
        $this->assertSame('3000.000', $rows->firstWhere('month', 5)->planned_amount);
        $this->assertSame(2026, $rows->firstWhere('month', 5)->year);
    }

    public function test_import_merges_customer_typo_variants(): void
    {
        // 205 obyektda «буюрмачи» (т yo'q), 124 tasida to'g'ri — bitta tashkilot.
        $this->import([
            $this->row(['customer_raw' => '"Ягона буюрмачи хизмати" ДМ']),
            $this->row(['external_id' => self::ID_B, 'customer_raw' => '"Ягона буюртмачи хизмати" ДМ']),
        ]);

        $this->assertCount(1, $this->scoped()->pluck('customer_org_id')->unique());
    }

    public function test_import_sets_lifecycle_from_progress(): void
    {
        $this->import([
            $this->row(['external_id' => '9901332170101011', 'disbursed' => '', 'disbursed_pct' => '', 'handover_actual' => '']),
            $this->row(['external_id' => '9901332170101012', 'disbursed' => '500', 'disbursed_pct' => '4', 'handover_actual' => '']),
            $this->row(['external_id' => '9901332170101013', 'handover_actual' => '1']),
        ]);

        $this->assertSame('reja', $this->find('9901332170101011')->lifecycle);
        $this->assertSame('jarayonda', $this->find('9901332170101012')->lifecycle);
        $this->assertSame('tugallangan', $this->find('9901332170101013')->lifecycle);
    }

    public function test_import_records_objects_without_district(): void
    {
        // 992000 — «туманлараро» maxsus kodi; tuman biriktirilmaydi.
        $report = $this->import([$this->row([
            'external_id' => '9901992000123456', 'c_value' => 'Туманлараро', 'group_label' => 'Туманлараро',
        ])]);

        $this->assertSame(1, $report['no_district']);
        $this->assertNull($this->find('9901992000123456')->district_id);
    }

    // ---------- yordamchilar ----------

    /** FAQAT shu test yaratgan obyektlar (umumiy dev bazasi tufayli shart). */
    private function scoped(): Builder
    {
        return ConstructionObject::query()
            ->where(fn (Builder $q) => $q->where('external_id', 'like', '99%')
                ->orWhere('external_id', 'like', 'dxsh:99%'));
    }

    private function find(string $externalId): ConstructionObject
    {
        return ConstructionObject::query()->where('external_id', $externalId)->firstOrFail();
    }

    /** @return array{objects: int, stages: int, limit: float} */
    private function snapshot(): array
    {
        $ids = $this->scoped()->pluck('id');

        return [
            'objects' => $ids->count(),
            'stages' => ObjectStage::query()->whereIn('object_id', $ids)->count(),
            'limit' => (float) $this->scoped()->sum('limit_amount'),
        ];
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     * @param  array<int, array<string, string>>  $monthly
     * @return array<string, mixed>
     */
    private function import(array $rows, array $monthly = []): array
    {
        return app(ObjectImporter::class)->import($rows, $monthly);
    }

    /**
     * ПҚ-393 varag'ining 8-qatori — to'liq bajarilgan obyekt.
     *
     * @param  array<string, string>  $over
     * @return array<string, string>
     */
    private function row(array $over = []): array
    {
        return array_merge([
            'sheet' => 'ПҚ-393', 'row' => '8', 'program_code' => 'pq393', 'seq' => '1',
            'external_id' => self::ID_A, 'c_value' => 'Хива.ш',
            'designer_raw' => '"QISHLOQ QURILISH LOYIHA" MCHJ',
            'customer_raw' => '"Ягона буюртмачи хизмати" ДМ',
            'name' => 'Хива шаҳридаги 1-сон ихтисослаштирилган мактаб ҳудудида ётоқхона қуриш',
            'deadline_raw' => '15.08.26 й',
            'limit_amount' => '12000.0', 'carryover' => '', 'new_start' => '1.0',
            'f_L' => '1.0', 'f_M' => '0.0', 'f_N' => '1.0', 'f_O' => '0.0',
            'f_P' => '1.0', 'f_Q' => '0.0', 'f_R' => '0.0',
            'f_S' => '1.0', 'f_T' => '1.0', 'f_U' => '0.0', 'f_V' => '0.0',
            'f_W' => '1.0', 'f_X' => '1.0', 'f_Y' => '1.0', 'f_Z' => '0.0', 'f_AA' => '0.0', 'f_AB' => '0.0',
            'f_AC' => '1.0', 'f_AD' => '1.0', 'f_AE' => '1.0', 'f_AF' => '0.0',
            'tender_amount' => '11500.0', 'f_AH' => '0.0',
            'contract_count' => '1.0', 'contract_amount' => '12010.0',
            'disbursed' => '12010.0', 'disbursed_pct' => '100.0',
            'financed' => '11401.0', 'financed_pct' => '94.9',
            'handover_plan' => '1.0', 'handover_actual' => '', 'handover_left' => '1.0', 'overdue_flag' => '',
            'contractor_raw' => 'САЯТ ҚУРИЛИШ МЧЖ', 'note' => 'Тест изоҳи',
            'group_label' => 'Умумтаълим мактаблари', 'work_type_label' => 'Янги қуриш',
        ], $over);
    }
}
