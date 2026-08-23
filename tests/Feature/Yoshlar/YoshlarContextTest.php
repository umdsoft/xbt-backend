<?php

declare(strict_types=1);

namespace Tests\Feature\Yoshlar;

use App\Domains\Yoshlar\Models\Organization;
use App\Domains\Yoshlar\Models\Youth;

class YoshlarContextTest extends YoshlarTestCase
{
    public function test_context_returns_reference_data(): void
    {
        $response = $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->getJson('/api/yoshlar/context')
            ->assertOk()
            ->assertJsonStructure([
                'reference' => [
                    'districts', 'mahallas', 'sectors', 'organizations',
                    'education_statuses', 'employment_statuses', 'roles',
                ],
                'badges' => ['pending_youth', 'task_queue', 'tasks_overdue', 'employment_queue'],
            ]);

        $this->assertNotEmpty($response->json('reference.districts'));
        $this->assertNotEmpty($response->json('reference.mahallas'));
    }

    public function test_pending_badge_counts_only_own_scope(): void
    {
        $own = $this->someDistrictId();
        $other = $this->otherDistrictId($own);

        $user = $this->makeUser('yoshlar_bolim', $this->makeOrganization(
            Organization::TYPE_TUMAN_YOSHLAR, ['district_id' => $own],
        )->id);

        $badge = fn (): int => $this->actingAs($user, 'sanctum')
            ->getJson('/api/yoshlar/context')->assertOk()->json('badges.pending_youth');

        // O'LCHOV — MUTLAQ SON EMAS, FARQ.
        //
        // Testlar dev bazasida `DatabaseTransactions` bilan ishlaydi, ya'ni
        // bazada boshqa yozuvlar ham turadi. `assertSame(1, ...)` demak
        // «bazada mendan boshqa hech kim yo'q» degan taxminga tayanardi va
        // reyestrga bir yozuv qo'shilishi bilan sinardi.
        //
        // Tekshirilayotgan HAQIQIY qoida: o'z tumaniga qo'shilgan yozuv
        // hisobga tushadi, begona tumaniki esa tushmaydi.
        $before = $badge();

        $this->pendingYouth($own);
        $this->assertSame($before + 1, $badge(), 'Oʻz tumanidagi yozuv hisobga tushmadi.');

        $this->pendingYouth($other);
        $this->assertSame($before + 1, $badge(), 'Begona tumandagi yozuv hisobga tushib ketdi.');
    }

    public function test_stats_endpoint_returns_district_breakdown(): void
    {
        $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->getJson('/api/yoshlar/youth/stats')
            ->assertOk()
            ->assertJsonStructure([
                'total', 'added_7d', 'growth_7d', 'neet', 'pending', 'by_district',
                'by_age' => ['14-17', '18-22', '23-26', '27-30'],
                'by_education', 'by_employment',
            ]);
    }

    public function test_growth_is_null_when_there_is_nothing_to_compare(): void
    {
        // Oʻsish foizi bosh maxrajga boʻlinmaydi. Reyestrdagi HAMMA yozuv
        // oxirgi 7 kunda qoʻshilgan boʻlsa, «oldin qancha edi» degan savol
        // maʼnosiz — `null` qaytadi, «0% oʻsish» EMAS.
        $stats = $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->getJson('/api/yoshlar/youth/stats')->assertOk()->json();

        $this->assertArrayHasKey('growth_7d', $stats);

        if ($stats['total'] === $stats['added_7d']) {
            $this->assertNull($stats['growth_7d'], 'Solishtirish asosi yoʻqda oʻsish null boʻlishi kerak.');
        } else {
            $this->assertIsNumeric($stats['growth_7d']);
        }
    }

    public function test_age_bands_cover_every_registry_record(): void
    {
        // Yosh oraliqlari sana chegaralari bilan hisoblanadi. Agar chegaralar
        // ustma-ust tushsa yozuv IKKI marta, orada bo'shliq qolsa esa umuman
        // sanalmasdi. Yig'indi = jami bo'lishi shuni ushlab turadi.
        $stats = $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->getJson('/api/yoshlar/youth/stats')->assertOk()->json();

        $this->assertSame(
            $stats['total'],
            array_sum($stats['by_age']),
            'Yosh oraliqlari yigʻindisi reyestr jamiga teng emas.',
        );
    }

    public function test_stats_route_is_not_swallowed_by_show_route(): void
    {
        // `/youth/stats` `{youth}` dan oldin turishi kerak — aks holda 404.
        $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->getJson('/api/yoshlar/youth/stats')
            ->assertOk()
            ->assertJsonMissingPath('data');
    }

    private function pendingYouth(string $districtId): Youth
    {
        return Youth::query()->create([
            'last_name' => 'Navbat', 'first_name' => 'Yosh',
            'birth_date' => now()->subYears(17)->toDateString(), 'gender' => 'erkak',
            'district_id' => $districtId, 'mahalla_id' => $this->someMahallaId($districtId),
            'verification_status' => 'pending',
        ]);
    }
}
