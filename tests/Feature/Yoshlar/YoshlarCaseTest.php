<?php

declare(strict_types=1);

namespace Tests\Feature\Yoshlar;

use App\Domains\Yoshlar\Models\Organization;
use App\Domains\Yoshlar\Models\Patronage;
use App\Domains\Yoshlar\Models\Sector;
use App\Domains\Yoshlar\Models\Staff;
use App\Domains\Yoshlar\Models\Youth;
use App\Domains\Yoshlar\Models\YouthCase;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * F4 — muammolar va otaliq.
 *
 * Eng muhim tekshiruvlar: «hal etildi» yechim izohisiz qo'yilmaydi,
 * otaliqni faqat huquqi bor xodim yuritadi, faollik KPI'si jurnal
 * asosida hisoblanadi.
 */
class YoshlarCaseTest extends YoshlarTestCase
{
    private Organization $org;

    private string $districtId;

    protected function setUp(): void
    {
        parent::setUp();

        $sector = Sector::query()->firstOrCreate(
            ['code' => 'mahalla_oila'],
            ['name_cyr' => 'Маҳалла ва оила', 'name_lat' => 'Mahalla va oila'],
        );

        $this->districtId = $this->someDistrictId();
        $province = $this->makeOrganization(Organization::TYPE_VILOYAT_SEKTOR, ['sector_id' => $sector->id]);
        $this->org = $this->makeOrganization(Organization::TYPE_TUMAN_SEKTOR, [
            'sector_id' => $sector->id,
            'parent_id' => $province->id,
            'district_id' => $this->districtId,
        ]);
    }

    public function test_case_gets_default_sla_by_category(): void
    {
        $youth = $this->makeYouth();

        $id = $this->actingAs($this->officer(), 'sanctum')
            ->postJson('/api/yoshlar/cases', [
                'youth_id' => $youth->id,
                'category' => 'sogliq',   // shoshilinch toifa — 3 kun
                'source' => 'yosh',
                'title' => 'Shifokor koʻrigi kerak',
            ])
            ->assertCreated()->json('data.id');

        $case = YouthCase::query()->findOrFail($id);

        $this->assertNotNull($case->sla_deadline);
        $this->assertLessThanOrEqual(3, $case->days_left);
    }

    public function test_open_case_sets_registry_flag(): void
    {
        $youth = $this->makeYouth();

        $this->actingAs($this->officer(), 'sanctum')
            ->postJson('/api/yoshlar/cases', $this->casePayload($youth))
            ->assertCreated();

        $this->assertTrue($youth->fresh()->has_open_case);
    }

    public function test_resolution_requires_note(): void
    {
        $case = $this->makeCase();
        $officer = $this->officer();

        $this->actingAs($officer, 'sanctum')
            ->patchJson("/api/yoshlar/cases/{$case->id}", ['status' => 'hal_etildi'])
            ->assertStatus(422);

        $this->actingAs($officer, 'sanctum')
            ->patchJson("/api/yoshlar/cases/{$case->id}", [
                'status' => 'hal_etildi',
                'resolution_note' => 'Ishga joylashtirildi',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'hal_etildi');

        $this->assertNotNull($case->fresh()->resolved_at);
    }

    public function test_resolving_last_case_clears_registry_flag(): void
    {
        $youth = $this->makeYouth();
        $case = $this->makeCase($youth);
        $youth->update(['has_open_case' => true]);

        $this->actingAs($this->officer(), 'sanctum')
            ->patchJson("/api/yoshlar/cases/{$case->id}", [
                'status' => 'hal_etildi',
                'resolution_note' => 'Hal qilindi',
            ])
            ->assertOk();

        $this->assertFalse($youth->fresh()->has_open_case);
    }

    public function test_case_from_other_district_is_hidden(): void
    {
        $foreignYouth = $this->makeYouth(['district_id' => $this->otherDistrictId($this->districtId)]);
        $foreignCase = $this->makeCase($foreignYouth);

        $this->actingAs($this->officer(), 'sanctum')
            ->getJson("/api/yoshlar/cases/{$foreignCase->id}")
            ->assertStatus(404);
    }

    public function test_sla_state_is_computed(): void
    {
        $overdue = $this->makeCase(null, ['sla_deadline' => now()->subDays(2)->toDateString()]);
        $fresh = $this->makeCase(null, ['sla_deadline' => now()->addDays(10)->toDateString()]);

        $this->assertSame('red', $overdue->sla_state);
        $this->assertSame('green', $fresh->sla_state);
    }

    // ---------------- Otaliq ----------------

    public function test_patronage_requires_mentor_permission(): void
    {
        $youth = $this->makeYouth();
        $plainStaff = $this->staffWithPatronage(false);

        $this->actingAs($this->officer(), 'sanctum')
            ->postJson('/api/yoshlar/patronage', [
                'youth_id' => $youth->id,
                'mentor_staff_id' => $plainStaff->id,
            ])
            ->assertStatus(422);
    }

    public function test_patronage_assignment_sets_flag(): void
    {
        $youth = $this->makeYouth();
        $mentor = $this->staffWithPatronage(true);

        $this->actingAs($this->officer(), 'sanctum')
            ->postJson('/api/yoshlar/patronage', [
                'youth_id' => $youth->id,
                'mentor_staff_id' => $mentor->id,
            ])
            ->assertCreated();

        $this->assertTrue($youth->fresh()->in_patronage);
    }

    public function test_youth_cannot_have_two_active_mentors(): void
    {
        $youth = $this->makeYouth();
        $first = $this->staffWithPatronage(true);
        $second = $this->staffWithPatronage(true);
        $officer = $this->officer();

        $this->actingAs($officer, 'sanctum')
            ->postJson('/api/yoshlar/patronage', ['youth_id' => $youth->id, 'mentor_staff_id' => $first->id])
            ->assertCreated();

        $this->actingAs($officer, 'sanctum')
            ->postJson('/api/yoshlar/patronage', ['youth_id' => $youth->id, 'mentor_staff_id' => $second->id])
            ->assertStatus(422);
    }

    public function test_ending_patronage_clears_flag_and_allows_reassignment(): void
    {
        $youth = $this->makeYouth();
        $mentor = $this->staffWithPatronage(true);
        $officer = $this->officer();

        $id = $this->actingAs($officer, 'sanctum')
            ->postJson('/api/yoshlar/patronage', ['youth_id' => $youth->id, 'mentor_staff_id' => $mentor->id])
            ->assertCreated()->json('data.id');

        $this->actingAs($officer, 'sanctum')
            ->postJson("/api/yoshlar/patronage/{$id}/end", ['note' => 'Yakunlandi'])
            ->assertOk();

        $this->assertFalse($youth->fresh()->in_patronage);

        // Endi qayta biriktirish mumkin.
        $this->actingAs($officer, 'sanctum')
            ->postJson('/api/yoshlar/patronage', ['youth_id' => $youth->id, 'mentor_staff_id' => $mentor->id])
            ->assertCreated();
    }

    public function test_activity_rate_counts_only_recent_logs(): void
    {
        $officer = $this->officer();
        $mentor = $this->staffWithPatronage(true);
        $youth = $this->makeYouth();

        $id = $this->actingAs($officer, 'sanctum')
            ->postJson('/api/yoshlar/patronage', ['youth_id' => $youth->id, 'mentor_staff_id' => $mentor->id])
            ->assertCreated()->json('data.id');

        // Jurnalsiz — biriktirilgan, lekin faol emas.
        $before = $this->actingAs($officer, 'sanctum')
            ->getJson('/api/yoshlar/patronage/stats')->assertOk()->json();

        $this->actingAs($officer, 'sanctum')
            ->postJson("/api/yoshlar/patronage/{$id}/logs", [
                'kind' => 'uchrashuv',
                'note' => 'Uchrashuv oʻtkazildi',
            ])
            ->assertCreated();

        $after = $this->actingAs($officer, 'sanctum')
            ->getJson('/api/yoshlar/patronage/stats')->assertOk()->json();

        $this->assertGreaterThan(
            $before['active_with_recent_log'],
            $after['active_with_recent_log'],
            'Jurnal yozuvi faollikni oshirmadi',
        );
    }

    public function test_sector_boshqarma_cannot_manage_patronage(): void
    {
        $youth = $this->makeYouth();
        $mentor = $this->staffWithPatronage(true);

        $province = Organization::query()->where('type', Organization::TYPE_VILOYAT_SEKTOR)->firstOrFail();

        $this->actingAs($this->makeUser('sektor_boshqarma', $province->id), 'sanctum')
            ->postJson('/api/yoshlar/patronage', ['youth_id' => $youth->id, 'mentor_staff_id' => $mentor->id])
            ->assertStatus(403);
    }

    private function officer(): User
    {
        return $this->makeUser('sektor_bolim', $this->org->id, true);
    }

    private function staffWithPatronage(bool $canPatronage): Staff
    {
        $user = $this->makeUser('sektor_bolim');

        return Staff::query()->create([
            'user_id' => $user->id,
            'org_id' => $this->org->id,
            'position' => 'Mutaxassis',
            'can_patronage' => $canPatronage,
            'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $attrs */
    private function makeYouth(array $attrs = []): Youth
    {
        $districtId = $attrs['district_id'] ?? $this->districtId;

        return Youth::query()->create(array_merge([
            'last_name' => 'Muammoli',
            'first_name' => 'Yosh'.Str::random(4),
            'birth_date' => now()->subYears(19)->toDateString(),
            'gender' => 'ayol',
            'district_id' => $districtId,
            'mahalla_id' => $this->someMahallaId($districtId),
            'verification_status' => 'verified',
        ], $attrs));
    }

    /** @param array<string, mixed> $attrs */
    private function makeCase(?Youth $youth = null, array $attrs = []): YouthCase
    {
        $youth ??= $this->makeYouth();

        return YouthCase::query()->create(array_merge([
            'youth_id' => $youth->id,
            'district_id' => $youth->district_id,
            'category' => 'bandlik',
            'source' => 'mahalla',
            'title' => 'TEST-'.Str::random(6),
            'status' => 'royxatda',
            'sla_deadline' => now()->addDays(14)->toDateString(),
        ], $attrs));
    }

    /** @return array<string, mixed> */
    private function casePayload(Youth $youth): array
    {
        return [
            'youth_id' => $youth->id,
            'category' => 'bandlik',
            'source' => 'mahalla',
            'title' => 'Ish topa olmayapti',
        ];
    }
}
