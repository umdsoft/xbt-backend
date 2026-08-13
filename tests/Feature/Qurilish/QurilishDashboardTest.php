<?php

declare(strict_types=1);

namespace Tests\Feature\Qurilish;

use App\Domains\Qurilish\Models\Program;
use App\Domains\Qurilish\Models\Sector;
use App\Models\User;

/**
 * Dashboard agregatsiyalari — manbadagi 22 СВОД pivotining jonli o'rnini bosuvchi.
 *
 * SINOV USULI: agregatlar determinik bo'lishi uchun barcha testlar YANGI
 * tashkilotga biriktirilgan `buyurtmachi` roli ostida yuradi. Shunda
 * `QurilishScope` jamlanmani faqat shu testning obyektlariga cheklaydi —
 * umumiy dev bazasidagi 611 ta import qilingan obyekt aralashmaydi.
 */
class QurilishDashboardTest extends QurilishObjectTestCase
{
    private string $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = $this->makeOrganization('Даш-буюртмачи '.$this->prefix, ['is_customer' => true]);
        $this->user = $this->makeUser('qurilish_buyurtmachi', $this->org);
    }

    public function test_summary_aggregates_finance_and_counts(): void
    {
        $this->obj(['limit_amount' => 10000, 'contract_amount' => 9000, 'disbursed_amount' => 4500, 'financed_amount' => 3000]);
        $this->obj(['limit_amount' => 5000, 'contract_amount' => 5000, 'disbursed_amount' => 5000, 'financed_amount' => 5000, 'handover_planned' => true, 'handover_done' => true]);

        $s = $this->api('/api/qurilish/dashboard')->assertOk()->json('summary');

        $this->assertSame(2, $s['objects']);
        $this->assertEqualsWithDelta(15000, $s['limit_total'], 0.001);
        $this->assertEqualsWithDelta(14000, $s['contract_total'], 0.001);
        $this->assertEqualsWithDelta(9500, $s['disbursed_total'], 0.001);
        // 9500 / 14000 = 67,9 %
        $this->assertEqualsWithDelta(67.9, $s['disbursed_pct'], 0.05);
        $this->assertSame(1, $s['handover_done']);
    }

    public function test_handover_counts_are_not_collapsed_by_model_casts(): void
    {
        // NEGA BU TEST BOR: `handover_done` modelda boolean cast qilingan.
        // Agar agregat ustuni aynan shu nom bilan tanlansa, Eloquent uni
        // boolean'ga o'giradi va 125 -> true -> 1 bo'lib qoladi. Bir dona
        // obyektli fikstura buni KO'RSATMAYDI (to'g'ri javob ham 1), shuning
        // uchun bu yerda ataylab 1 dan KO'P obyekt bor.
        for ($i = 0; $i < 4; $i++) {
            $this->obj(['handover_planned' => true, 'handover_done' => true]);
        }
        $this->obj(['handover_planned' => true, 'handover_done' => false]);
        $this->obj(['handover_planned' => false, 'handover_done' => false]);

        $s = $this->api('/api/qurilish/dashboard')->assertOk()->json('summary');

        $this->assertSame(5, $s['handover_planned']);
        $this->assertSame(4, $s['handover_done']);
        $this->assertSame(1, $s['handover_left']);

        // Kesimda ham xuddi shu to'qnashuv bor edi.
        $rows = $this->api('/api/qurilish/dashboard/svod/buyurtmachi')->assertOk()->json('data');
        $this->assertSame(4, collect($rows)->firstWhere('key', $this->org)['handover_done']);
    }

    public function test_summary_counts_overdue_objects(): void
    {
        $this->obj(['deadline_date' => now()->subDays(10), 'handover_done' => false]);
        $this->obj(['deadline_date' => now()->subDays(10), 'handover_done' => true]);
        $this->obj(['deadline_date' => now()->addDays(10)]);

        $this->assertSame(1, $this->api('/api/qurilish/dashboard')->assertOk()->json('summary.overdue'));
    }

    public function test_drafts_are_excluded_from_aggregation(): void
    {
        $this->obj(['limit_amount' => 1000]);
        $this->obj(['limit_amount' => 9999, 'lifecycle' => 'qoralama']);

        $s = $this->api('/api/qurilish/dashboard')->assertOk()->json('summary');

        // Qoralama hali dasturda yo'q — jamlanmani buzmasligi kerak.
        $this->assertSame(1, $s['objects']);
        $this->assertEqualsWithDelta(1000, $s['limit_total'], 0.001);
        $this->assertSame(1, $s['drafts']);
    }

    public function test_tender_over_limit_is_flagged_for_oversight(): void
    {
        // Manbada 69 obyektda shartnoma yillik limitdan oshadi (ko'p yillik loyiha).
        // Bu «xato» emas, lekin nazorat organi ko'rishi kerak bo'lgan signal.
        $this->obj(['limit_amount' => 10000, 'tender_amount' => 8500]);   // normal
        $this->obj(['limit_amount' => 20000, 'tender_amount' => 70000]);  // limitdan oshgan
        $this->obj(['limit_amount' => 5000, 'tender_amount' => 0]);       // tendersiz

        $this->assertSame(
            1,
            $this->api('/api/qurilish/dashboard')->assertOk()->json('summary.tender_over_limit'),
        );
    }

    public function test_svod_by_program(): void
    {
        $pq393 = Program::query()->where('code', 'pq393')->value('id');
        $drayver = Program::query()->where('code', 'drayver')->value('id');

        $this->obj(['program_id' => $pq393, 'limit_amount' => 3000]);
        $this->obj(['program_id' => $pq393, 'limit_amount' => 2000]);
        $this->obj(['program_id' => $drayver, 'limit_amount' => 1000]);

        $rows = collect($this->api('/api/qurilish/dashboard/svod/dastur')->assertOk()->json('data'));

        $this->assertCount(2, $rows);
        $top = $rows->firstWhere('key', $pq393);
        $this->assertSame(2, $top['objects']);
        $this->assertEqualsWithDelta(5000, $top['limit_total'], 0.001);
    }

    public function test_svod_by_sector_and_district(): void
    {
        $mtt = Sector::query()->where('code', 'mtt')->value('id');
        $district = $this->someDistrictId();

        $this->obj(['sector_id' => $mtt, 'district_id' => $district]);
        $this->obj(['sector_id' => $mtt, 'district_id' => $district]);

        $sectors = collect($this->api('/api/qurilish/dashboard/svod/soha')->assertOk()->json('data'));
        $this->assertSame(2, $sectors->firstWhere('key', $mtt)['objects']);

        $districts = collect($this->api('/api/qurilish/dashboard/svod/tuman')->assertOk()->json('data'));
        $this->assertSame(2, $districts->firstWhere('key', $district)['objects']);
    }

    public function test_svod_labels_missing_dimension_value(): void
    {
        $this->obj(['sector_id' => null]);

        $rows = $this->api('/api/qurilish/dashboard/svod/soha')->assertOk()->json('data');

        $this->assertSame('Аниқланмаган', $rows[0]['name']);
    }

    public function test_unknown_svod_dimension_is_404(): void
    {
        $this->api('/api/qurilish/dashboard/svod/allaqanday')->assertStatus(404);
    }

    public function test_funnel_returns_eight_ordered_stages(): void
    {
        $object = $this->obj();
        $this->setStage($object, 'designer_selection', 'tasdiqlangan');
        $this->setStage($object, 'complex_expertise', 'talab_etilmaydi');

        $funnel = $this->api('/api/qurilish/dashboard/funnel')->assertOk()->json('data');

        $this->assertCount(8, $funnel);
        $this->assertSame('designer_selection', $funnel[0]['stage_code']);
        $this->assertSame(1, $funnel[0]['tasdiqlangan']);
        $this->assertSame(1, $funnel[3]['talab_etilmaydi']);
        // «Talab etilmaydi» ham tugallangan hisoblanadi (voronka uzilmasin).
        $this->assertSame(1, $funnel[3]['done_total']);
        $this->assertSame('handover', $funnel[7]['stage_code']);
    }

    public function test_execution_buckets(): void
    {
        $this->obj(['contract_amount' => 1000, 'disbursed_amount' => 1000]);   // 100
        $this->obj(['contract_amount' => 1000, 'disbursed_amount' => 600]);    // 50-74
        $this->obj(['contract_amount' => 1000, 'disbursed_amount' => 0]);      // 0
        $this->obj(['contract_amount' => 0, 'disbursed_amount' => 0]);         // shartnomasiz

        $b = collect($this->api('/api/qurilish/dashboard')->assertOk()->json('execution_buckets'))
            ->pluck('objects', 'bucket');

        $this->assertSame(1, $b['100']);
        $this->assertSame(1, $b['50-74']);
        $this->assertSame(1, $b['0']);
        $this->assertSame(1, $b['shartnomasiz']);
    }

    public function test_monthly_rollup_returns_twelve_months(): void
    {
        $object = $this->obj();
        \App\Domains\Qurilish\Models\ObjectMonthlyPlan::query()->create([
            'object_id' => $object->id, 'year' => 2026, 'month' => 3,
            'planned_amount' => 1500, 'actual_amount' => 1200,
        ]);

        $months = $this->api('/api/qurilish/dashboard?year=2026')->assertOk()->json('monthly');

        $this->assertCount(12, $months);
        $this->assertEqualsWithDelta(1500, $months[2]['planned_amount'], 0.001);
        $this->assertEqualsWithDelta(1200, $months[2]['actual_amount'], 0.001);
        $this->assertEqualsWithDelta(0, $months[0]['planned_amount'], 0.001);
    }

    public function test_dashboard_is_scoped_per_organization(): void
    {
        $this->obj(['limit_amount' => 1000]);

        $otherOrg = $this->makeOrganization('Бегона '.$this->prefix, ['is_customer' => true]);
        $this->makeObject(['name' => $this->tag('OTHER'), 'customer_org_id' => $otherOrg, 'limit_amount' => 999999]);

        $s = $this->api('/api/qurilish/dashboard')->assertOk()->json('summary');

        $this->assertSame(1, $s['objects']);
        $this->assertEqualsWithDelta(1000, $s['limit_total'], 0.001);
    }

    public function test_viewer_sees_whole_region(): void
    {
        // Hokimlik scope'siz — bazadagi barcha obyektni ko'radi.
        $s = $this->actingAs($this->makeUser('qurilish_hokimlik'), 'sanctum')
            ->getJson('/api/qurilish/dashboard')->assertOk()->json('summary');

        $this->assertGreaterThanOrEqual(1, $s['objects']);
    }

    public function test_export_svod_returns_xlsx(): void
    {
        $this->obj(['limit_amount' => 1000]);

        $res = $this->actingAs($this->user, 'sanctum')
            ->get('/api/qurilish/export/svod/dastur')->assertOk();

        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $res->headers->get('Content-Type'),
        );
        // XLSX = ZIP: birinchi ikki bayt "PK".
        $this->assertStringStartsWith('PK', $res->getContent());
    }

    public function test_export_objects_returns_xlsx_without_silent_truncation(): void
    {
        $this->obj(['limit_amount' => 1000]);

        $res = $this->actingAs($this->user, 'sanctum')
            ->get('/api/qurilish/export/objects')->assertOk();

        $this->assertStringStartsWith('PK', $res->getContent());
        // Chegara oshmagan — ogohlantirish header'i bo'lmasligi kerak.
        $this->assertNull($res->headers->get('X-Qurilish-Truncated-At'));
    }

    // ---------- rahbariyat paneli ----------

    public function test_executive_returns_all_blocks(): void
    {
        $this->obj(['limit_amount' => 10000, 'contract_amount' => 9000, 'disbursed_amount' => 4500, 'is_carryover' => false]);
        $this->obj(['limit_amount' => 5000, 'contract_amount' => 5000, 'disbursed_amount' => 5000, 'is_carryover' => true]);

        $d = $this->api('/api/qurilish/dashboard/executive')->assertOk()->json();

        foreach ([
            'overview', 'savings', 'pending_tender', 'tender_status',
            'handover', 'programs', 'sectors', 'districts', 'readiness', 'contracts',
        ] as $block) {
            $this->assertArrayHasKey($block, $d, $block);
        }

        $this->assertSame(2, $d['overview']['objects']);
        $this->assertSame(1, $d['overview']['fresh_objects']);
        $this->assertSame(1, $d['overview']['carryover_objects']);
        $this->assertEqualsWithDelta(5000, $d['overview']['carryover_amount'], 0.001);
        $this->assertCount(5, $d['readiness']);
    }

    public function test_executive_savings_counts_only_real_savings(): void
    {
        // Tender limitdan ARZON — tejamkorlik bor.
        $this->obj(['limit_amount' => 10000, 'tender_amount' => 8500]);
        // Tender limitdan QIMMAT (ko'p yillik loyiha) — tejamkorlik emas,
        // aks holda jamlanma manfiyga tortilardi.
        $this->obj(['limit_amount' => 20000, 'tender_amount' => 70000]);
        // Tendersiz.
        $this->obj(['limit_amount' => 5000, 'tender_amount' => 0]);

        $s = $this->api('/api/qurilish/dashboard/executive')->assertOk()->json('savings');

        $this->assertSame(1, $s['total_objects']);
        $this->assertEqualsWithDelta(1500, $s['total_amount'], 0.001);
    }

    public function test_executive_tender_status_buckets_cover_every_object(): void
    {
        $a = $this->obj();
        $b = $this->obj();
        $c = $this->obj();
        $this->setStage($a, 'tender', 'tasdiqlangan');
        $this->setStage($b, 'tender', 'tasdiqlash_kutilmoqda');
        $this->setStage($c, 'tender', 'kutilmoqda');

        $t = $this->api('/api/qurilish/dashboard/executive')->assertOk()->json('tender_status');

        $this->assertSame(1, $t['done']['objects']);
        $this->assertSame(1, $t['process']['objects']);
        $this->assertSame(1, $t['not_announced']['objects']);
        $this->assertSame(3, $t['total']);
    }

    public function test_executive_handover_counts_are_not_collapsed_by_casts(): void
    {
        // Model `handover_done` ni boolean cast qiladi — agregat ustuni shu nom
        // bilan tanlansa 4 -> true -> 1 bo'lib qolardi.
        for ($i = 0; $i < 4; $i++) {
            $this->obj(['handover_planned' => true, 'handover_done' => true]);
        }
        $this->obj(['handover_planned' => true, 'handover_done' => false]);

        $h = $this->api('/api/qurilish/dashboard/executive')->assertOk()->json('handover');

        $this->assertSame(5, $h['plan_objects']);
        $this->assertSame(4, $h['done_objects']);
        $this->assertSame(1, $h['left_objects']);
        $this->assertEqualsWithDelta(80, $h['done_pct'], 0.1);
    }

    public function test_executive_is_scoped(): void
    {
        $this->obj(['limit_amount' => 1000]);

        $otherOrg = $this->makeOrganization('Бегона exec '.$this->prefix, ['is_customer' => true]);
        $this->makeObject(['name' => $this->tag('X'), 'customer_org_id' => $otherOrg, 'limit_amount' => 999999]);

        $o = $this->api('/api/qurilish/dashboard/executive')->assertOk()->json('overview');

        $this->assertSame(1, $o['objects']);
        $this->assertEqualsWithDelta(1000, $o['limit_total'], 0.001);
    }

    public function test_outsider_cannot_reach_dashboard(): void
    {
        $this->actingAs($this->makeOutsider(), 'sanctum')
            ->getJson('/api/qurilish/dashboard')->assertStatus(403);
    }

    // ---------- yordamchilar ----------

    /** @param array<string, mixed> $attrs */
    private function obj(array $attrs = []): \App\Domains\Qurilish\Models\ConstructionObject
    {
        return $this->makeObject(array_merge([
            'name' => $this->tag('D'.random_int(1000, 9999)),
            'customer_org_id' => $this->org,
        ], $attrs));
    }

    private function api(string $url): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user, 'sanctum')->getJson($url);
    }
}
