<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor;

use Illuminate\Support\Facades\DB;

/**
 * Dashboard (spec §10) — rolга qarab agregat kartalar/ogohlantirish/reyting/
 * so'nggi faoliyat. Viloyat/bo'linma/tuman uchun mos tarkib.
 */
class DashboardTest extends AdvisorTestCase
{
    /** key bo'yicha karta qiymatini topadi. */
    private function cardValue(array $cards, string $key)
    {
        return collect($cards)->firstWhere('key', $key)['value'] ?? null;
    }

    public function test_viloyat_dashboard_has_cards_alerts_ranking_and_activity(): void
    {
        $district = $this->someDistrictId();
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');

        // Muddati o'tган topshiriq (deadline o'tmishda -> overdue nishon + task_created).
        $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/tasks', [
            'title' => 'Кечиккан топшириқ',
            'deadline' => '2020-01-01',
            'district_ids' => [$district],
        ])->assertCreated();

        $body = $this->actingAs($viloyat, 'sanctum')->getJson('/api/advisor/dashboard')
            ->assertOk()
            ->assertJsonStructure([
                'cards' => [['key', 'label', 'value']],
                'alerts',
                'ranking',
                'recent_activity' => [['time', 'type', 'summary']],
            ])
            ->json();

        // Kartalar: ochiq topshiriq va muddati o'tган.
        $this->assertGreaterThanOrEqual(1, (int) $this->cardValue($body['cards'], 'open_tasks'));
        $this->assertGreaterThanOrEqual(1, (int) $this->cardValue($body['cards'], 'overdue'));

        // Ogohlantirish: overdue.
        $this->assertNotEmpty($body['alerts']);
        $this->assertSame('overdue', $body['alerts'][0]['type']);

        // So'nggi faoliyat: task_created.
        $types = collect($body['recent_activity'])->pluck('type')->all();
        $this->assertContains('task_created', $types);
    }

    public function test_tuman_dashboard_is_self_scoped_without_ranking_table(): void
    {
        $district = $this->someDistrictId();
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);

        $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/tasks', [
            'title' => 'Туман топшириғи',
            'district_ids' => [$district],
        ])->assertCreated();

        $body = $this->actingAs($tuman, 'sanctum')->getJson('/api/advisor/dashboard')
            ->assertOk()
            ->assertJsonStructure(['cards' => [['key', 'label', 'value']], 'alerts', 'recent_activity'])
            ->json();

        $keys = collect($body['cards'])->pluck('key')->all();
        $this->assertContains('my_open_tasks', $keys);
        $this->assertContains('my_rank', $keys);

        // Tuman uchun reyting JADVALI qaytarilmaydi (o'rin — karta).
        $this->assertArrayNotHasKey('ranking', $body);
        $this->assertGreaterThanOrEqual(1, (int) $this->cardValue($body['cards'], 'my_open_tasks'));
    }

    public function test_bolinma_dashboard_shows_qa_queue(): void
    {
        $district = $this->someDistrictId();
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);
        $bolinma = $this->makeAdvisor('advisor_bolinma', 'bolinma');

        $taskId = $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/tasks', [
            'title' => 'QA учун топшириқ',
            'district_ids' => [$district],
        ])->json('id');
        $targetId = DB::connection('advisor')->table('task_targets')
            ->where('task_id', $taskId)->where('district_id', $district)->value('id');

        // Tuman hisobot yuboradi -> QA navbatida (pending).
        $this->actingAs($tuman, 'sanctum')->postJson('/api/advisor/tasks/'.$taskId.'/report', [
            'target_id' => $targetId, 'body' => 'Бажарилди',
        ])->assertCreated();

        $body = $this->actingAs($bolinma, 'sanctum')->getJson('/api/advisor/dashboard')
            ->assertOk()
            ->assertJsonStructure(['cards' => [['key', 'label', 'value']], 'ranking', 'recent_activity'])
            ->json();

        $keys = collect($body['cards'])->pluck('key')->all();
        $this->assertContains('qa_queue', $keys);
        $this->assertSame(1, (int) $this->cardValue($body['cards'], 'qa_queue'));
    }
}
