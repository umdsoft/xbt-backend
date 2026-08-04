<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor;

use App\Domains\Advisor\Models\ActionPlan;
use App\Domains\Advisor\Models\ActionPlanItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * CHORA-TADBIRLAR — kirish/ruxsat: auth-siz 401, advisor bo'lmagan 403; 3 rol
 * ro'yxatни/tafsilotни ko'radi; reja/band FAQAT viloyat (tasdiqlovchi hujjат majburiy);
 * bo'linma bajarilishини KIRITA olmaydi (plan.progress yo'q).
 */
class ActionPlanAccessTest extends AdvisorTestCase
{
    private function makeItem(string $scope = 'all_districts'): ActionPlanItem
    {
        $plan = ActionPlan::create(['year' => 2099, 'title' => 'Синов режа', 'status' => 'active']);

        return ActionPlanItem::create([
            'plan_id' => $plan->id,
            'section_title' => 'I. Синов бўлими',
            'item_number' => '1',
            'title' => 'Синов банди',
            'scope' => $scope,
            'sort_order' => 10,
        ]);
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/advisor/action-plan')->assertUnauthorized();
    }

    public function test_outsider_forbidden(): void
    {
        $this->makeItem();
        $outsider = $this->makeOutsider();

        $this->actingAs($outsider, 'sanctum')
            ->getJson('/api/advisor/action-plan')->assertForbidden();
    }

    public function test_all_three_roles_can_list_and_open_detail(): void
    {
        $item = $this->makeItem();
        $planId = $item->plan_id;
        $district = $this->someDistrictId();

        foreach ([
            $this->makeAdvisor('advisor_viloyat', 'viloyat'),
            $this->makeAdvisor('advisor_bolinma', 'bolinma'),
            $this->makeAdvisor('advisor_tuman', 'tuman', $district),
        ] as $user) {
            // Ro'yxat (jadval) — reja bor.
            $plans = $this->actingAs($user, 'sanctum')
                ->getJson('/api/advisor/action-plan')
                ->assertOk()
                ->json('plans');
            $this->assertNotNull(collect($plans)->firstWhere('id', $planId));

            // Tafsilot — bo'limlar/bandlar.
            $this->actingAs($user, 'sanctum')
                ->getJson("/api/advisor/action-plan/{$planId}")
                ->assertOk()
                ->assertJsonPath('plan.id', $planId)
                ->assertJsonPath('plan.year', 2099);
        }
    }

    public function test_plan_creation_by_role(): void
    {
        Storage::fake('local');
        $district = $this->someDistrictId();
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);
        $bolinma = $this->makeAdvisor('advisor_bolinma', 'bolinma');

        $payload = ['title' => 'Янги режа', 'year' => 2099];
        $doc = UploadedFile::fake()->create('tasdiq.pdf', 120, 'application/pdf');

        // Tuman/bo'linma reja yarata olmaydi.
        // Tuman O'Z rejasini yarata oladi (hujjat ixtiyoriy) — district_id = o'z tumani.
        $tumanPlanId = $this->actingAs($tuman, 'sanctum')->postJson('/api/advisor/action-plan', $payload)
            ->assertCreated()->json('id');
        $this->assertDatabaseHas('action_plans', ['id' => $tumanPlanId, 'district_id' => $district], 'advisor');

        // Bo'linма reja yarата olmaydi.
        $this->actingAs($bolinma, 'sanctum')->postJson('/api/advisor/action-plan', $payload + ['document' => $doc])->assertForbidden();

        // Viloyat hujjатsiz — 422 (tasdiqlovchi hujjат majburiy).
        $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/action-plan', $payload)->assertStatus(422);

        // Viloyat hujjат bilan — 201.
        $this->actingAs($viloyat, 'sanctum')->postJson('/api/advisor/action-plan', $payload + [
            'document' => UploadedFile::fake()->create('tasdiq.pdf', 120, 'application/pdf'),
        ])->assertCreated()->assertJsonPath('ok', true);

        $this->assertDatabaseHas('action_plans', ['title' => 'Янги режа', 'year' => 2099], 'advisor');
    }

    public function test_only_viloyat_adds_item(): void
    {
        $item = $this->makeItem();
        $planId = $item->plan_id;
        $district = $this->someDistrictId();
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);

        $band = [
            'section_title' => 'II. Янги бўлим', 'item_number' => '2',
            'title' => 'Янги банд', 'scope' => 'all_districts',
        ];

        $this->actingAs($tuman, 'sanctum')->postJson("/api/advisor/action-plan/{$planId}/items", $band)->assertForbidden();
        $this->actingAs($viloyat, 'sanctum')->postJson("/api/advisor/action-plan/{$planId}/items", $band)
            ->assertCreated()->assertJsonPath('ok', true);
    }

    public function test_tuman_manages_own_plan_but_not_others(): void
    {
        $district = $this->someDistrictId();
        $other = $this->anotherDistrictId($district);
        $tuman = $this->makeAdvisor('advisor_tuman', 'tuman', $district);
        $otherTuman = $this->makeAdvisor('advisor_tuman', 'tuman', $other);
        $viloyat = $this->makeAdvisor('advisor_viloyat', 'viloyat');

        // Tuman o'z rejasini yaratadi.
        $planId = $this->actingAs($tuman, 'sanctum')->postJson('/api/advisor/action-plan', [
            'title' => 'Туман режаси', 'year' => 2099,
        ])->assertCreated()->json('id');

        // O'z rejasига band qo'sha oladi.
        $this->actingAs($tuman, 'sanctum')->postJson("/api/advisor/action-plan/{$planId}/items", [
            'section_title' => 'I. Бўлим', 'item_number' => '1', 'title' => 'Банд',
        ])->assertCreated();

        // Boshqa tuman bu rejани ko'ra OLMAЙДИ (403).
        $this->actingAs($otherTuman, 'sanctum')->getJson("/api/advisor/action-plan/{$planId}")->assertForbidden();

        // Viloyat ko'radi (monitoring), lekin band qo'sha OLMAЙДИ (tuman rejasi).
        $this->actingAs($viloyat, 'sanctum')->getJson("/api/advisor/action-plan/{$planId}")->assertOk();
        $this->actingAs($viloyat, 'sanctum')->postJson("/api/advisor/action-plan/{$planId}/items", [
            'section_title' => 'I. Бўлим', 'item_number' => '2', 'title' => 'Банд2',
        ])->assertForbidden();
    }

    public function test_bolinma_cannot_submit_progress(): void
    {
        $item = $this->makeItem();
        $bolinma = $this->makeAdvisor('advisor_bolinma', 'bolinma');

        $this->actingAs($bolinma, 'sanctum')
            ->postJson("/api/advisor/action-plan/items/{$item->id}/progress", [
                'district_id' => $this->someDistrictId(),
                'status' => 'in_progress',
            ])
            ->assertForbidden();
    }
}
