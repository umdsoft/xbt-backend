<?php

declare(strict_types=1);

namespace Tests\Feature\Yoshlar;

use App\Domains\Yoshlar\Models\Organization;
use App\Domains\Yoshlar\Models\Protocol;
use App\Domains\Yoshlar\Models\Task;

/**
 * TOPSHIRIQ = RASMIY HUJJAT BANDI.
 *
 * Bu yerdagi testlar hujjat TUZILISHINI qoʻriqlaydi: band raqami,
 * boʻlimlar tartibi, ikki muddat ustuni va oʻlchanadigan maqsad.
 * Ular buzilsa, tizimdagi topshiriqni qogʻozdagi band bilan
 * solishtirib boʻlmay qoladi.
 */
class YoshlarProtocolDocumentTest extends YoshlarTestCase
{
    private function makeProtocol(string $type = Protocol::TYPE_ROADMAP): Protocol
    {
        return Protocol::query()->create([
            'number' => 'TEST-'.substr(bin2hex(random_bytes(6)), 0, 10),
            'protocol_date' => '2026-08-01',
            'type' => $type,
            'topic' => 'Hokim va yoshlar uchrashuvi yoʻl xaritasi',
            'event_title' => 'Hokim va yoshlar uchrashuvi',
            'year' => 2026,
        ]);
    }

    private function executorOrg(): Organization
    {
        return $this->makeOrganization(Organization::TYPE_TUMAN_SEKTOR, [
            'district_id' => $this->someDistrictId(),
        ]);
    }

    /** @param array<string, mixed> $attrs */
    private function makeItem(Protocol $protocol, string $number, array $attrs = []): Task
    {
        return Task::query()->create(array_merge([
            'protocol_id' => $protocol->id,
            'item_number' => $number,
            'title' => "Band {$number}",
            'assigned_org_id' => $this->executorOrg()->id,
            'district_id' => $this->someDistrictId(),
            'deadline' => '2026-11-01',
            'priority' => 'orta',
            'status' => 'belgilandi',
            'progress' => 0,
        ], $attrs));
    }

    public function test_document_view_groups_items_into_sections_in_paper_order(): void
    {
        $protocol = $this->makeProtocol(Protocol::TYPE_ACTION_PLAN);

        // ATAYLAB teskari tartibda yaratamiz: `created_at` boʻyicha
        // saralansa javob «II → I» boʻlib chiqardi.
        $this->makeItem($protocol, '2.1', ['section_title' => 'II. Bandlik', 'sort_order' => 20]);
        $this->makeItem($protocol, '1.1', ['section_title' => 'I. Taʼlim', 'sort_order' => 10]);
        $this->makeItem($protocol, '1.2', ['section_title' => 'I. Taʼlim', 'sort_order' => 11]);

        $body = $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->getJson("/api/yoshlar/protocols/{$protocol->id}")
            ->assertOk()
            ->json();

        $this->assertSame(['I. Taʼlim', 'II. Bandlik'], array_column($body['sections'], 'title'));
        $this->assertSame(['1.1', '1.2'], array_column($body['sections'][0]['items'], 'item_number'));
        $this->assertSame(['2.1'], array_column($body['sections'][1]['items'], 'item_number'));
    }

    public function test_item_number_cannot_repeat_within_one_document(): void
    {
        $protocol = $this->makeProtocol();
        $this->makeItem($protocol, '6');

        // «6-band» ikki marta kiritilsa, ijro hisoboti qaysi biriga
        // tegishli ekani noaniq qolardi.
        $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->postJson('/api/yoshlar/tasks', [
                'protocol_id' => $protocol->id,
                'item_number' => '6',
                'title' => 'Takroriy band',
                'assigned_org_id' => $this->executorOrg()->id,
                'deadline' => '2026-11-01',
                'priority' => 'orta',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('item_number');
    }

    public function test_same_item_number_is_allowed_in_a_different_document(): void
    {
        // Raqam hujjat ICHIDA unikal, global emas: har bayonnomada
        // oʻzining «6-bandi» boʻladi.
        $this->makeItem($this->makeProtocol(), '6');

        $other = $this->makeProtocol();

        $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->postJson('/api/yoshlar/tasks', [
                'protocol_id' => $other->id,
                'item_number' => '6',
                'title' => 'Boshqa hujjatning 6-bandi',
                'assigned_org_id' => $this->executorOrg()->id,
                'deadline' => '2026-11-01',
                'priority' => 'orta',
            ])
            ->assertCreated();
    }

    public function test_document_fields_survive_a_round_trip(): void
    {
        $protocol = $this->makeProtocol();
        $org = $this->executorOrg();

        $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->postJson('/api/yoshlar/tasks', [
                'protocol_id' => $protocol->id,
                'item_number' => '1',
                'title' => '«Pay» toʻlov loyihasi boʻyicha taklif',
                'mechanism' => 'Toʻlov tashkilotlari bilan muzokaralar tashkil etish.',
                'steps' => [
                    ['no' => 1, 'text' => 'Rasmiy maʼlumotnoma tayyorlash', 'deadline' => null],
                    ['no' => 2, 'text' => 'Muzokaralar tashkil etish', 'deadline' => '2026-09-15'],
                ],
                // Hujjatda «1 oy muddat» deb yozilgan; sana esa hisoblangan.
                'deadline' => '2026-09-23',
                'deadline_text' => '1 oy muddat',
                'responsible_text' => 'Markaziy bankning viloyat bosh boshqarmasi (K.Kurbanov)',
                'applicant_name' => 'Jumanazarov Asilbek Sherali oʻgʻli',
                'assigned_org_id' => $org->id,
                'priority' => 'orta',
            ])
            ->assertCreated();

        $item = $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->getJson("/api/yoshlar/protocols/{$protocol->id}")
            ->assertOk()
            ->json('sections.0.items.0');

        $this->assertSame('1', $item['item_number']);
        $this->assertSame('1 oy muddat', $item['deadline_text']);
        $this->assertSame('2026-09-23', $item['deadline']);
        $this->assertStringContainsString('K.Kurbanov', $item['responsible_text']);
        $this->assertSame('Jumanazarov Asilbek Sherali oʻgʻli', $item['applicant']);
        $this->assertCount(2, $item['steps']);
        $this->assertSame('Muzokaralar tashkil etish', $item['steps'][1]['text']);
    }

    public function test_measurable_target_reports_progress_and_refuses_overshoot(): void
    {
        $protocol = $this->makeProtocol(Protocol::TYPE_BAYONNOMA);

        // «...kamida 10 ta ijtimoiy obyektda pilot tarzda joriy etish...»
        $task = $this->makeItem($protocol, '6', [
            'target_value' => 10,
            'target_unit' => 'ijtimoiy obyekt',
        ]);

        $admin = $this->makeUser('yoshlar_admin');

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/yoshlar/tasks/{$task->id}/target", ['target_done' => 7])
            ->assertOk()
            ->assertJsonPath('data.target_done', 7)
            ->assertJsonPath('data.target_percent', 70);

        // Rejadan ortiq ish alohida band boʻlishi kerak; shu bandning
        // sonini shishirsak umumiy foiz 100 dan oshib ketardi.
        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/yoshlar/tasks/{$task->id}/target", ['target_done' => 11])
            ->assertStatus(422)
            ->assertJsonValidationErrors('target_done');
    }

    public function test_target_percent_is_null_when_no_target_is_set(): void
    {
        // «Maqsad yoʻq» va «maqsad bor, lekin 0% bajarilgan» ekranda bir
        // xil koʻrinmasligi kerak.
        $task = $this->makeItem($this->makeProtocol(), '3');

        $this->assertNull($task->target_percent);
    }

    public function test_sort_order_is_assigned_after_the_last_item(): void
    {
        $protocol = $this->makeProtocol();
        $this->makeItem($protocol, '1', ['sort_order' => 5]);

        $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->postJson('/api/yoshlar/tasks', [
                'protocol_id' => $protocol->id,
                'item_number' => '2',
                'title' => 'Keyingi band',
                'assigned_org_id' => $this->executorOrg()->id,
                'deadline' => '2026-11-01',
                'priority' => 'orta',
            ])
            ->assertCreated();

        // Tartib nolga tushib qolmasligi kerak — aks holda barcha bandlar
        // bir xil ogʻirlikda boʻlib, ekranda tasodifiy joylashardi.
        $this->assertSame(6, (int) Task::query()
            ->where('protocol_id', $protocol->id)
            ->where('item_number', '2')
            ->value('sort_order'));
    }

    public function test_document_list_carries_execution_tally(): void
    {
        $protocol = $this->makeProtocol();
        $this->makeItem($protocol, '1', ['status' => 'tasdiqlandi']);
        $this->makeItem($protocol, '2', ['deadline' => '2020-01-01']);   // muddati oʻtgan

        $row = collect(
            $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
                ->getJson('/api/yoshlar/protocols')->assertOk()->json('data'),
        )->firstWhere('id', $protocol->id);

        $this->assertNotNull($row);
        $this->assertSame(2, $row['stats']['total']);
        $this->assertSame(1, $row['stats']['done']);
        $this->assertSame(1, $row['stats']['overdue']);
        $this->assertSame(50, $row['stats']['done_percent']);
    }

    public function test_roadmap_is_flagged_to_show_the_applicant_column(): void
    {
        // Ustunlar toʻplami hujjat TURIDAN kelib chiqadi: bayonnomada
        // murojaatchi ustuni boʻsh turib, jadvalni kengaytirardi.
        $roadmap = $this->makeProtocol(Protocol::TYPE_ROADMAP);
        $minutes = $this->makeProtocol(Protocol::TYPE_BAYONNOMA);

        $user = $this->makeUser('yoshlar_admin');

        $this->assertTrue(
            $this->actingAs($user, 'sanctum')
                ->getJson("/api/yoshlar/protocols/{$roadmap->id}")->json('document.shows_applicant'),
        );

        $this->assertFalse(
            $this->actingAs($user, 'sanctum')
                ->getJson("/api/yoshlar/protocols/{$minutes->id}")->json('document.shows_applicant'),
        );
    }
}
