<?php

declare(strict_types=1);

namespace Tests\Feature\Qurilish;

use App\Domains\Qurilish\Models\Organization;
use App\Domains\Qurilish\Support\DeadlineParser;
use App\Domains\Qurilish\Support\OrgRegistry;
use App\Domains\Qurilish\Support\SectorClassifier;
use App\Domains\Qurilish\Support\SoatoResolver;
use App\Domains\Qurilish\Support\StageMapper;
use App\Domains\Qurilish\Support\Translit;
use App\Domains\Qurilish\Support\WorkTypeClassifier;
use Illuminate\Support\Facades\DB;

/**
 * ETL normalizatsiya qoidalari — manba xlsx'dagi HAQIQIY qiymatlar ustida.
 *
 * Bu qoidalar Python ekstraktorida EMAS, PHP'da yashaydi — aynan shuning
 * uchun ular shu yerda test bilan qoplanadi.
 */
class QurilishNormalizerTest extends QurilishTestCase
{
    // ---------- Translit ----------

    public function test_translit_converts_cyrillic_and_leaves_latin(): void
    {
        $this->assertSame("Xiva shahri", Translit::toLatin('Хива шаҳри'));
        $this->assertSame('MONOLIT LOYIHA QURILISH MCHJ', Translit::toLatin('MONOLIT LOYIHA QURILISH MCHJ'));
        $this->assertNull(Translit::toLatin(null));
    }

    // ---------- SoatoResolver ----------

    public function test_soato_resolver_derives_district_from_external_id(): void
    {
        $resolver = app(SoatoResolver::class);

        // 2601334060102001 -> [4:9] = '33406' -> '1733406' = Xiva shahri
        $id = $resolver->districtIdFor('2601334060102001', null);
        $this->assertNotNull($id);
        $this->assertSame('1733406', $this->soatoOf($id));

        // 2622334010717007 -> '33401' -> '1733401' = Urganch shahri
        $this->assertSame('1733401', $this->soatoOf($resolver->districtIdFor('2622334010717007', null)));
    }

    public function test_soato_resolver_falls_back_to_name_when_id_missing(): void
    {
        $resolver = app(SoatoResolver::class);

        $this->assertSame('1733230', $this->soatoOf($resolver->districtIdFor(null, 'Шовот тумани')));
        $this->assertSame('1733401', $this->soatoOf($resolver->districtIdFor('', 'Урганч шаҳар')));
        // ПҚ-393 dagi qisqartma shakl.
        $this->assertSame('1733226', $this->soatoOf($resolver->districtIdFor(null, 'Хива.т')));
        $this->assertSame('1733406', $this->soatoOf($resolver->districtIdFor(null, 'Хива.ш')));
        // Imlo varianti.
        $this->assertSame('1733221', $this->soatoOf($resolver->districtIdFor(null, 'Тупроққальа')));
    }

    public function test_soato_resolver_returns_null_for_interdistrict(): void
    {
        $resolver = app(SoatoResolver::class);

        // 992000 — «туманлараро» maxsus kodi.
        $this->assertNull($resolver->districtIdFor('2601992000123456', 'Туманлараро'));
        $this->assertNull($resolver->districtIdFor(null, null));
    }

    public function test_soato_resolver_prefers_external_id_over_name(): void
    {
        // Manbada 12 ta obyektda C ustuni xato; ID hokim bo'lishi SHART.
        $resolver = app(SoatoResolver::class);

        $this->assertSame(
            '1733406',
            $this->soatoOf($resolver->districtIdFor('2601334060102001', 'Урганч.т')),
        );
    }

    // ---------- DeadlineParser ----------

    public function test_deadline_parser_handles_all_four_formats(): void
    {
        $p = new DeadlineParser;

        $this->assertSame(['date' => '2026-08-15', 'year' => 2026], $p->parse('15.08.26 й'));
        $this->assertSame(['date' => '2026-12-31', 'year' => 2026], $p->parse('2026 йил'));
        $this->assertSame(['date' => '2026-12-31', 'year' => 2026], $p->parse('2026 й'));
        $this->assertSame(['date' => '2026-12-31', 'year' => 2026], $p->parse('2025-2026 йй'));
        $this->assertSame(['date' => '2027-12-31', 'year' => 2027], $p->parse('2026-2027 йй'));
        $this->assertSame(['date' => null, 'year' => null], $p->parse(null));
        $this->assertSame(['date' => null, 'year' => null], $p->parse('   '));
    }

    public function test_deadline_parser_rejects_impossible_dates(): void
    {
        $p = new DeadlineParser;

        $this->assertSame(['date' => null, 'year' => null], $p->parse('32.13.26 й'));
    }

    // ---------- OrgRegistry ----------

    public function test_org_registry_merges_quote_and_legal_form_variants(): void
    {
        $reg = app(OrgRegistry::class);

        $a = $reg->resolve('"XORAZM SUV LOYIHA" MCHJ', 'is_designer');
        $b = $reg->resolve('XORAZM SUV LOYIHA MCHJ', 'is_designer');
        $c = $reg->resolve('"XORAZM SUV LOYIHA" mas’uliyati cheklangan jamiyati', 'is_designer');

        $this->assertSame($a, $b);
        $this->assertSame($a, $c);
    }

    public function test_org_registry_merges_known_customer_typo(): void
    {
        $reg = app(OrgRegistry::class);

        // Manbada 205 obyektda «буюрмачи» (т yo'q), 124 tasida to'g'ri yozilgan.
        $wrong = $reg->resolve('"Ягона буюрмачи хизмати" ДМ', 'is_customer');
        $right = $reg->resolve('"Ягона буюртмачи хизмати" ДМ', 'is_customer');

        $this->assertSame($right, $wrong);
    }

    public function test_org_registry_merges_regional_roads_variants(): void
    {
        $reg = app(OrgRegistry::class);

        $a = $reg->resolve('"Хоразм минтақавий йўлларга буюртмачи хизмати" ДМ', 'is_customer');
        $b = $reg->resolve('"Минтақавий йўлларга буюртмачи хизмати" ДМ', 'is_customer');
        $c = $reg->resolve('"Минтақавий йўлларга буюртмачи хизмати" ДУК', 'is_customer');

        $this->assertSame($a, $b);
        $this->assertSame($a, $c);
    }

    public function test_org_registry_sets_role_flags_cumulatively(): void
    {
        $reg = app(OrgRegistry::class);

        $id = $reg->resolve('"Ҳудудий электр тармоқлари" АЖ', 'is_customer');
        $reg->resolve('"Худудий электр тармоклари" АЖ', 'is_contractor');

        $org = Organization::query()->findOrFail($id);
        $this->assertTrue($org->is_customer);
        $this->assertTrue($org->is_contractor);
    }

    public function test_org_registry_returns_null_for_empty(): void
    {
        $reg = app(OrgRegistry::class);

        $this->assertNull($reg->resolve(null, 'is_customer'));
        $this->assertNull($reg->resolve('  ', 'is_customer'));
    }

    public function test_org_registry_stores_latin_name(): void
    {
        $reg = app(OrgRegistry::class);
        $id = $reg->resolve('"Хоразм давлат ўрмон хўжалиги" ДМ', 'is_customer');

        $org = Organization::query()->findOrFail($id);
        $this->assertStringNotContainsString('Хоразм', $org->name_lat);
        $this->assertStringContainsString('Xorazm', $org->name_lat);
    }

    // ---------- SectorClassifier ----------

    public function test_sector_classifier_maps_known_labels(): void
    {
        $c = app(SectorClassifier::class);

        $this->assertSame('umumtalim_maktab', $c->classify(null, 'Умумтаълим мактаблари', 'Мактаб қуриш'));
        $this->assertSame('mtt', $c->classify('МТТ', null, 'Боғча'));
        $this->assertSame('irrigatsiya', $c->classify('Ирригация', null, 'Канал'));
        $this->assertSame('ichki_yol', $c->classify('ички йўл', null, 'Кўча'));
        $this->assertSame('ichki_yol', $c->classify('Ички йўллар', null, 'Кўча'));
        $this->assertSame('sogliqni_saqlash', $c->classify('Тиббиёт', null, 'ФАП'));
        $this->assertSame('suv_kanalizatsiya', $c->classify('ичимлик сув', null, 'Қувур'));
        $this->assertSame('elektr', $c->classify('электр таъминоти', null, 'Тармоқ'));
    }

    public function test_sector_classifier_falls_back_to_name_keywords_for_boshqa(): void
    {
        $c = app(SectorClassifier::class);

        // Manbada 150 obyekt «Бошқа» — aslida 92 ichki yo'l, 29 ichimlik suv, 15 ko'cha.
        $this->assertSame('ichki_yol', $c->classify('Бошқа', null, 'Гурлан тумани ички йўлларини таъмирлаш'));
        $this->assertSame('suv_kanalizatsiya', $c->classify('Бошқа', null, 'Ичимлик сув таъминоти тизимини қуриш'));
        $this->assertSame('ichki_yol', $c->classify('Бошқа', null, '"Дўстлик" кўчасини таъмирлаш'));
        $this->assertSame('mtt', $c->classify('Бошқа', null, '100 ўринли мактабгача таълим ташкилоти қуриш'));
        $this->assertSame('umumtalim_maktab', $c->classify('Бошқа', null, '220 ўринли мактаб қуриш'));
        $this->assertSame('boshqa', $c->classify('Бошқа', null, 'Кенгашлар уйи биносини таъмирлаш'));
    }

    public function test_sector_classifier_prefers_mtt_over_maktab(): void
    {
        $c = app(SectorClassifier::class);

        // «мактабгача» «мактаб» dan OLDIN tekshirilishi shart.
        $this->assertSame('mtt', $c->classify(null, null, 'Мактабгача таълим ташкилоти қуриш'));
    }

    // ---------- WorkTypeClassifier ----------

    public function test_work_type_classifier(): void
    {
        $c = app(WorkTypeClassifier::class);

        $this->assertSame('yangi_qurish', $c->classify('Янги қуриш', 'X'));
        $this->assertSame('rekonstruksiya', $c->classify('Реконструкция', 'X'));
        $this->assertSame('mukammal_tamirlash', $c->classify('Мукаммал таъмирлаш', 'X'));
        $this->assertSame('rekonstruksiya', $c->classify(null, 'Мактабни реконструкция қилиш'));
        $this->assertSame('yangi_qurish', $c->classify(null, 'Янги мактаб қуриш'));
        $this->assertSame('mukammal_tamirlash', $c->classify(null, 'Йўлни мукаммал таъмирлаш'));
        $this->assertNull($c->classify(null, 'Ноаниқ иш'));
    }

    // ---------- StageMapper ----------

    public function test_stage_mapper_produces_eight_stages(): void
    {
        $map = (new StageMapper)->map([]);

        $this->assertCount(8, $map);
        $this->assertSame('boshlanmagan', $map['designer_selection']['status']);
    }

    public function test_stage_mapper_marks_completed_chain(): void
    {
        // Manbadagi haqiqiy qator (ПҚ-393 R8): to'liq bajarilgan obyekt.
        $map = (new StageMapper)->map([
            'f_L' => 1, 'f_N' => 1, 'f_P' => 1, 'f_S' => 1, 'f_T' => 1,
            'f_W' => 1, 'f_X' => 1, 'f_Z' => 1, 'f_AC' => 1, 'f_AD' => 1, 'f_AE' => 1,
            'contract_count' => 1, 'disbursed' => 12010, 'disbursed_pct' => 100,
            'handover_plan' => 1,
        ]);

        $this->assertSame('yakunlangan', $map['designer_selection']['status']);
        $this->assertSame('yakunlangan', $map['design_estimate']['status']);
        $this->assertSame('yakunlangan', $map['urban_planning']['status']);
        $this->assertSame('yakunlangan', $map['tender']['status']);
        $this->assertSame('yakunlangan', $map['contract']['status']);
        $this->assertSame('yakunlangan', $map['execution']['status']);
        $this->assertSame('boshlanmagan', $map['handover']['status']);
    }

    public function test_stage_mapper_marks_complex_expertise_not_required(): void
    {
        // W (талаб этилади) bo'sh -> bosqich umuman talab etilmaydi.
        $map = (new StageMapper)->map(['f_L' => 1, 'f_N' => 1]);

        $this->assertSame('talab_etilmaydi', $map['complex_expertise']['status']);
    }

    public function test_stage_mapper_marks_objection_returned(): void
    {
        $map = (new StageMapper)->map(['f_W' => 1, 'f_X' => 1, 'f_AA' => 1]);

        $this->assertSame('etiroz_bilan_qaytarilgan', $map['complex_expertise']['status']);
    }

    public function test_stage_mapper_marks_in_progress_states(): void
    {
        // Loyihachi e'longa berilgan, lekin aniqlanmagan.
        $map = (new StageMapper)->map(['f_L' => 1, 'f_O' => 1, 'f_R' => 1, 'f_U' => 1, 'f_AD' => 1, 'disbursed' => 500]);

        $this->assertSame('jarayonda', $map['designer_selection']['status']);
        $this->assertSame('jarayonda', $map['design_estimate']['status']);
        $this->assertSame('jarayonda', $map['urban_planning']['status']);
        $this->assertSame('jarayonda', $map['tender']['status']);
        $this->assertSame('jarayonda', $map['execution']['status']);
    }

    public function test_stage_mapper_handover_done(): void
    {
        $map = (new StageMapper)->map(['handover_plan' => 1, 'handover_actual' => 1]);

        $this->assertSame('yakunlangan', $map['handover']['status']);
    }

    private function soatoOf(?string $districtId): ?string
    {
        if ($districtId === null) {
            return null;
        }

        return (string) DB::connection('master')->table('districts')
            ->where('id', $districtId)->value('soato_code');
    }
}
