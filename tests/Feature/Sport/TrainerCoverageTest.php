<?php

declare(strict_types=1);

namespace Tests\Feature\Sport;

use App\Domains\Sport\Models\Trainer;
use App\Domains\Sport\Models\TrainerMahalla;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sport trenerlar qamrovi — OCHIQ (loginsiz) API testlari.
 *
 * Geo (district/mahalla) real kadastr — commit qilingan qatorlar; sport
 * jadvallariga test ichida yoziladi va tranzaksiya bilan qaytariladi.
 * ASOSIY KAFOLAT: telefon/PII public javobda hech qachon chiqmaydi.
 */
class TrainerCoverageTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, string> */
    protected array $connectionsToTransact = ['master', 'sport'];

    private const PHONE = '998901234567';

    /** @return array{0: string, 1: string} [districtId, mahallaName] */
    private function seedTrainer(): array
    {
        $district = DB::connection('master')->table('districts')->first();
        $mahalla = DB::connection('master')->table('mahallas')
            ->where('district_id', $district->id)->where('is_active', true)->first();

        $trainer = Trainer::create([
            'district_id' => $district->id,
            'full_name' => 'Тест Тренеров Тест ўғли',
            'phone' => self::PHONE,
            'sport_type' => 'Кураш',
            'workplace' => 'Тест спорт мактаб',
            'age' => 30,
            'uniform_size' => 'XL',
        ]);
        TrainerMahalla::create([
            'trainer_id' => $trainer->id,
            'mahalla_id' => $mahalla->id,
            'mahalla_name_raw' => $mahalla->name_cyr,
            'district_id' => $district->id,
            'youth_7_30' => 100,
            'sport_objects_count' => 2,
        ]);

        return [$district->id, $mahalla->name_cyr];
    }

    public function test_overview_is_public_and_structured(): void
    {
        $this->seedTrainer();

        $res = $this->getJson('/api/sport/public/trainer-coverage');

        $res->assertOk()
            ->assertJsonStructure([
                'trainers_total', 'assignments_total', 'mahallas_matched', 'mahallas_total',
                'coverage_percent', 'avg_mahallas_per_trainer', 'youth_total', 'sport_objects_total',
                'by_district' => [['district_id', 'name', 'trainers', 'mahallas_matched', 'mahallas_total', 'coverage_percent', 'youth']],
                'sport_types' => [['type', 'count']],
                'districts' => [['id', 'name']],
            ]);
        $this->assertGreaterThanOrEqual(1, $res->json('trainers_total'));
    }

    public function test_district_detail_returns_trainers(): void
    {
        [$districtId] = $this->seedTrainer();

        $res = $this->getJson("/api/sport/public/trainer-coverage/{$districtId}");

        $res->assertOk()
            ->assertJsonStructure([
                'district' => ['id', 'name'],
                'summary' => ['trainers', 'mahallas_matched', 'mahallas_total', 'coverage_percent', 'youth_total', 'uncovered_count'],
                'trainers' => [['id', 'full_name', 'sport_type', 'mahalla_count', 'youth_reach', 'mahallas']],
                'uncovered_mahallas',
                'sport_types',
            ]);
        $this->assertGreaterThanOrEqual(1, $res->json('summary.trainers'));
    }

    /** ENG MUHIM: telefon/PII public javobda hech qanday joyda chiqmasligi kerak. */
    public function test_phone_pii_never_exposed(): void
    {
        [$districtId] = $this->seedTrainer();

        $this->getJson('/api/sport/public/trainer-coverage')->assertOk()->assertDontSee(self::PHONE);
        $district = $this->getJson("/api/sport/public/trainer-coverage/{$districtId}")->assertOk();
        $district->assertDontSee(self::PHONE);

        foreach ($district->json('trainers') as $t) {
            $this->assertArrayNotHasKey('phone', $t, 'Public trener javobida telefon bo\'lmasligi kerak');
        }
    }

    public function test_unknown_district_returns_404(): void
    {
        $this->getJson('/api/sport/public/trainer-coverage/00000000-0000-0000-0000-000000000000')
            ->assertNotFound();
    }
}
