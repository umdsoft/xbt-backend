<?php

declare(strict_types=1);

namespace Tests\Feature\Yoshlar;

use App\Domains\Yoshlar\Models\EmploymentCase;
use App\Domains\Yoshlar\Models\Organization;
use App\Domains\Yoshlar\Models\Sector;
use App\Domains\Yoshlar\Models\Youth;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * F3 — bandlik: 3 tomonlama tasdiqlash zanjiri.
 *
 * Eng muhim tekshiruv: zanjir ROLGA emas, rol + SEKTOR juftligiga bog'langan —
 * bandlik bo'limi o'z arizasini o'zi tasdiqlay olmaydi.
 */
class YoshlarEmploymentTest extends YoshlarTestCase
{
    private Sector $taxSector;

    private Sector $bandlikSector;

    private Organization $taxProvince;

    private Organization $taxDistrict;

    private Organization $bandlikDistrict;

    private string $districtId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->districtId = $this->someDistrictId();

        $this->taxSector = Sector::query()->firstOrCreate(
            ['code' => 'soliq'],
            ['name_cyr' => 'Солиқ', 'name_lat' => 'Soliq'],
        );
        $this->bandlikSector = Sector::query()->firstOrCreate(
            ['code' => 'bandlik'],
            ['name_cyr' => 'Бандлик', 'name_lat' => 'Bandlik'],
        );

        $this->taxProvince = $this->makeOrganization(Organization::TYPE_VILOYAT_SEKTOR, ['sector_id' => $this->taxSector->id]);
        $this->taxDistrict = $this->makeOrganization(Organization::TYPE_TUMAN_SEKTOR, [
            'sector_id' => $this->taxSector->id,
            'parent_id' => $this->taxProvince->id,
            'district_id' => $this->districtId,
        ]);

        $bandlikProvince = $this->makeOrganization(Organization::TYPE_VILOYAT_SEKTOR, ['sector_id' => $this->bandlikSector->id]);
        $this->bandlikDistrict = $this->makeOrganization(Organization::TYPE_TUMAN_SEKTOR, [
            'sector_id' => $this->bandlikSector->id,
            'parent_id' => $bandlikProvince->id,
            'district_id' => $this->districtId,
        ]);
    }

    public function test_employment_office_creates_case(): void
    {
        $youth = $this->makeYouth();

        $this->actingAs($this->employmentOfficer(), 'sanctum')
            ->postJson('/api/yoshlar/employment', $this->payload($youth))
            ->assertCreated()
            ->assertJsonPath('data.status', EmploymentCase::STATUS_SUBMITTED);
    }

    public function test_employment_office_cannot_confirm_its_own_case(): void
    {
        $case = $this->makeCase();

        // Bandlik bo'limi `review.district` ruxsatiga EGA (rol bo'yicha),
        // lekin sektori `soliq` emas — shuning uchun tasdiqlay olmaydi.
        $this->actingAs($this->employmentOfficer(), 'sanctum')
            ->postJson("/api/yoshlar/employment/{$case->id}/review", ['approve' => true])
            ->assertStatus(403);
    }

    public function test_full_tax_chain_marks_youth_employed(): void
    {
        $youth = $this->makeYouth(['employment_status' => 'band_emas', 'is_neet' => true]);
        $case = $this->makeCase($youth);

        // 1-bosqich: tuman soliq.
        $this->actingAs($this->taxDistrictOfficer(), 'sanctum')
            ->postJson("/api/yoshlar/employment/{$case->id}/review", ['approve' => true])
            ->assertOk()
            ->assertJsonPath('data.status', EmploymentCase::STATUS_TAX_DISTRICT);

        // Hali «rasman band» emas — reyestr o'zgarmagan.
        $this->assertSame('band_emas', $youth->fresh()->employment_status);

        // 2-bosqich: viloyat soliq — yakuniy.
        $this->actingAs($this->taxProvinceOfficer(), 'sanctum')
            ->postJson("/api/yoshlar/employment/{$case->id}/review", ['approve' => true])
            ->assertOk()
            ->assertJsonPath('data.status', EmploymentCase::STATUS_CONFIRMED);

        // REYESTR YANGILANDI: bir haqiqat, ikki joyda emas.
        $fresh = $youth->fresh();
        $this->assertSame('band', $fresh->employment_status);
        $this->assertFalse($fresh->is_neet);
        $this->assertNotNull($fresh->workplace);
    }

    public function test_province_tax_cannot_skip_district_stage(): void
    {
        $case = $this->makeCase();

        // Ariza hali `yuborildi` — navbatdagi bosqich `tax_district`.
        // Viloyat soliqchisi uni tasdiqlay olmaydi.
        $this->actingAs($this->taxProvinceOfficer(), 'sanctum')
            ->postJson("/api/yoshlar/employment/{$case->id}/review", ['approve' => true])
            ->assertStatus(403);
    }

    public function test_tax_district_from_other_district_is_blocked(): void
    {
        $case = $this->makeCase();

        $otherDistrict = $this->otherDistrictId($this->districtId);
        $otherTaxOrg = $this->makeOrganization(Organization::TYPE_TUMAN_SEKTOR, [
            'sector_id' => $this->taxSector->id,
            'parent_id' => $this->taxProvince->id,
            'district_id' => $otherDistrict,
        ]);

        $this->actingAs($this->makeUser('sektor_bolim', $otherTaxOrg->id), 'sanctum')
            ->postJson("/api/yoshlar/employment/{$case->id}/review", ['approve' => true])
            ->assertStatus(404);
    }

    public function test_return_requires_reason_and_stores_it(): void
    {
        $case = $this->makeCase();
        $officer = $this->taxDistrictOfficer();

        $this->actingAs($officer, 'sanctum')
            ->postJson("/api/yoshlar/employment/{$case->id}/review", ['approve' => false])
            ->assertStatus(422);

        $this->actingAs($officer, 'sanctum')
            ->postJson("/api/yoshlar/employment/{$case->id}/review", ['approve' => false, 'comment' => 'Shartnoma yoʻq'])
            ->assertOk()
            ->assertJsonPath('data.status', EmploymentCase::STATUS_RETURNED);

        $this->assertSame('Shartnoma yoʻq', $case->fresh()->reject_reason);
    }

    public function test_second_open_case_for_same_youth_is_rejected(): void
    {
        $youth = $this->makeYouth();
        $officer = $this->employmentOfficer();

        $this->actingAs($officer, 'sanctum')
            ->postJson('/api/yoshlar/employment', $this->payload($youth))
            ->assertCreated();

        $this->actingAs($officer, 'sanctum')
            ->postJson('/api/yoshlar/employment', $this->payload($youth))
            ->assertStatus(422);
    }

    public function test_unverified_youth_cannot_be_employed(): void
    {
        $youth = $this->makeYouth(['verification_status' => 'pending']);

        $this->actingAs($this->employmentOfficer(), 'sanctum')
            ->postJson('/api/yoshlar/employment', $this->payload($youth))
            ->assertStatus(422);
    }

    public function test_queue_shows_only_own_tax_stage(): void
    {
        $case = $this->makeCase();

        $districtQueue = $this->actingAs($this->taxDistrictOfficer(), 'sanctum')
            ->getJson('/api/yoshlar/employment/queue')->assertOk()->json('data.*.id');
        $this->assertContains($case->id, $districtQueue);

        $provinceQueue = $this->actingAs($this->taxProvinceOfficer(), 'sanctum')
            ->getJson('/api/yoshlar/employment/queue')->assertOk()->json('data.*.id');
        $this->assertNotContains($case->id, $provinceQueue);
    }

    public function test_non_tax_sector_has_empty_queue(): void
    {
        $this->makeCase();

        $queue = $this->actingAs($this->employmentOfficer(), 'sanctum')
            ->getJson('/api/yoshlar/employment/queue')->assertOk()->json('data');

        $this->assertSame([], $queue);
    }

    public function test_stats_count_confirmed_only(): void
    {
        $this->makeCase();

        $stats = $this->actingAs($this->makeUser('yoshlar_hokim_orinbosari'), 'sanctum')
            ->getJson('/api/yoshlar/employment/stats')->assertOk()->json();

        $this->assertArrayHasKey('confirmed', $stats);
        $this->assertGreaterThanOrEqual(1, $stats['in_review']);
    }

    private function employmentOfficer(): User
    {
        return $this->makeUser('sektor_bolim', $this->bandlikDistrict->id);
    }

    private function taxDistrictOfficer(): User
    {
        return $this->makeUser('sektor_bolim', $this->taxDistrict->id);
    }

    private function taxProvinceOfficer(): User
    {
        return $this->makeUser('sektor_boshqarma', $this->taxProvince->id);
    }

    /** @param array<string, mixed> $attrs */
    private function makeYouth(array $attrs = []): Youth
    {
        return Youth::query()->create(array_merge([
            'last_name' => 'Ishchi',
            'first_name' => 'Yosh'.Str::random(4),
            'birth_date' => now()->subYears(21)->toDateString(),
            'gender' => 'erkak',
            'district_id' => $this->districtId,
            'mahalla_id' => $this->someMahallaId($this->districtId),
            'verification_status' => 'verified',
        ], $attrs));
    }

    private function makeCase(?Youth $youth = null): EmploymentCase
    {
        $youth ??= $this->makeYouth();

        return EmploymentCase::query()->create([
            'youth_id' => $youth->id,
            'district_id' => $youth->district_id,
            'employer_name' => 'MChJ "Sinov"',
            'start_date' => now()->toDateString(),
            'status' => EmploymentCase::STATUS_SUBMITTED,
            'submitted_by' => $this->employmentOfficer()->id,
            'submitted_org_id' => $this->bandlikDistrict->id,
            'submitted_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(Youth $youth): array
    {
        return [
            'youth_id' => $youth->id,
            'employer_name' => 'MChJ "Yangi ish"',
            'employer_inn' => '123456789',
            'position' => 'Operator',
            'start_date' => now()->toDateString(),
        ];
    }
}
