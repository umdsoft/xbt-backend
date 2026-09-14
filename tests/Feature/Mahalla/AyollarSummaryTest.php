<?php

declare(strict_types=1);

namespace Tests\Feature\Mahalla;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Mahalla\Concerns\ExecutiveScopeFixtures;
use Tests\TestCase;

/**
 * Аёллар жамланма эндпойнти — раҳбар (tuman/viloyat) учун АГРЕГАТ
 * кўприк (`GET /api/mahalla/executive/mahallas/{mahalla}/ayollar-summary`).
 *
 * Design: docs/superpowers/specs/2026-09-14-rahbar-kontekst-paneli-design.md §4.
 *
 * MUHIM: `ayollar` ulanishi `$connectionsToTransact` da YO'Q (global cheklov —
 * bu endpoint u yerdan faqat O'QIYDI, lekin biz test uchun qator qo'shamiz).
 * Demak DatabaseTransactions bu yerga yozilgan qatorlarni QAYTARMAYDI.
 * Shuning uchun har test o'zi yozgan `ayollar.anketa_red_flags` qatorlarini
 * `tearDown()`da (har test — muvaffaqiyatli yoki muvaffaqiyatsiz — baribir
 * chaqiriladi) o'chiradi. `ayollar.mahalla_balances`ga esa yozilmaydi —
 * mavjud (production'dagi kabi barchasi nol) qatordan foydalaniladi.
 */
class AyollarSummaryTest extends TestCase
{
    use DatabaseTransactions;
    use ExecutiveScopeFixtures;

    /** @var array<int, string> */
    protected array $connectionsToTransact = ['pgsql', 'auth', 'master', 'mahalla'];

    /** @var array<int, string> Shu testda `ayollar.anketa_red_flags`ga qo'shilgan qator id'lari — tearDown'da o'chiriladi. */
    private array $insertedRedFlagIds = [];

    /** @var array<int, string> Shu testda `ayollar.anketas`ga qo'shilgan qator id'lari — tearDown'da o'chiriladi. */
    private array $insertedAnketaIds = [];

    protected function tearDown(): void
    {
        // `ayollar` ulanishi transaksiyaga kirmaydi -> qo'lda tozalash SHART,
        // aks holda umumiy dev bazasida test axlati qoladi.
        if ($this->insertedRedFlagIds !== []) {
            DB::connection('ayollar')->table('anketa_red_flags')
                ->whereIn('id', $this->insertedRedFlagIds)->delete();
        }

        if ($this->insertedAnketaIds !== []) {
            DB::connection('ayollar')->table('anketas')
                ->whereIn('id', $this->insertedAnketaIds)->delete();
        }

        parent::tearDown();
    }

    public function test_tuman_can_read_summary_for_own_mahalla(): void
    {
        $own = $this->districtId();
        $user = $this->makeTumanUser($own);
        $mahallaId = (string) DB::connection('master')->table('mahallas')
            ->where('district_id', $own)->value('id');

        $res = $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/mahallas/'.$mahallaId.'/ayollar-summary')
            ->assertOk();

        $res->assertJsonStructure(['mahalla' => ['id', 'name'], 'started', 'balance', 'flags']);
        $this->assertSame($mahallaId, $res->json('mahalla.id'));
    }

    public function test_tuman_cannot_read_summary_for_another_district(): void
    {
        $own = $this->districtId();
        $other = $this->anotherDistrictId($own);
        $user = $this->makeTumanUser($own);
        $foreign = (string) DB::connection('master')->table('mahallas')
            ->where('district_id', $other)->value('id');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/mahallas/'.$foreign.'/ayollar-summary')
            ->assertNotFound();
    }

    public function test_started_is_false_when_balance_total_is_zero(): void
    {
        $own = $this->districtId();
        $user = $this->makeTumanUser($own);
        $mahallaId = (string) DB::connection('master')->table('mahallas')
            ->where('district_id', $own)->value('id');

        // Shovot mahallalarida hozir aynan shunday: mahalla_balances qatori
        // bor-yo'qligidan qat'i nazar, total = 0.
        $res = $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/mahallas/'.$mahallaId.'/ayollar-summary')
            ->assertOk();

        $this->assertFalse($res->json('started'));
        $this->assertSame(0, $res->json('balance.total'));
    }

    public function test_sensitive_flag_below_threshold_is_suppressed(): void
    {
        [$user, $mahallaId] = $this->tumanAndMahalla();

        $this->seedRedFlags($mahallaId, 'violence_victim', 4);

        $res = $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/mahallas/'.$mahallaId.'/ayollar-summary')
            ->assertOk();

        $flag = $this->findFlag($res->json('flags'), 'violence_victim');
        $this->assertNotNull($flag, 'violence_victim bayrog\'i javobda bo\'lishi kerak');
        $this->assertTrue($flag['suppressed']);
        $this->assertNull($flag['count']);
    }

    public function test_non_sensitive_flag_is_not_suppressed(): void
    {
        [$user, $mahallaId] = $this->tumanAndMahalla();

        $this->seedRedFlags($mahallaId, 'chronic_illness', 2);

        $res = $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/mahallas/'.$mahallaId.'/ayollar-summary')
            ->assertOk();

        $flag = $this->findFlag($res->json('flags'), 'chronic_illness');
        $this->assertNotNull($flag);
        $this->assertFalse($flag['suppressed']);
        $this->assertSame(2, $flag['count']);
    }

    public function test_sensitive_flag_at_or_above_threshold_is_exact(): void
    {
        [$user, $mahallaId] = $this->tumanAndMahalla();

        $this->seedRedFlags($mahallaId, 'violence_victim', 5);

        $res = $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/mahallas/'.$mahallaId.'/ayollar-summary')
            ->assertOk();

        $flag = $this->findFlag($res->json('flags'), 'violence_victim');
        $this->assertNotNull($flag);
        $this->assertFalse($flag['suppressed']);
        $this->assertSame(5, $flag['count']);
    }

    public function test_response_never_contains_personal_fields(): void
    {
        [$user, $mahallaId] = $this->tumanAndMahalla();

        $this->seedRedFlags($mahallaId, 'violence_victim', 6);

        $res = $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/mahallas/'.$mahallaId.'/ayollar-summary')
            ->assertOk();

        $raw = $res->getContent();
        foreach (['full_name', 'pinfl', 'passport', 'phone', 'address', 'birth_date'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) $raw, "Javobda '{$forbidden}' bo'lmasligi kerak");
        }
    }

    public function test_viloyat_can_read_any_mahalla(): void
    {
        $own = $this->districtId();
        $other = $this->anotherDistrictId($own);
        $user = $this->makeUserWithRole('viloyat');
        $foreign = (string) DB::connection('master')->table('mahallas')
            ->where('district_id', $other)->value('id');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/mahallas/'.$foreign.'/ayollar-summary')
            ->assertOk();
    }

    // ---------- yordamchilar ----------

    /**
     * @return array{0: User, 1: string}
     */
    private function tumanAndMahalla(): array
    {
        $own = $this->districtId();
        $user = $this->makeTumanUser($own);
        $mahallaId = (string) DB::connection('master')->table('mahallas')
            ->where('district_id', $own)->value('id');

        return [$user, $mahallaId];
    }

    /**
     * Shu mahallaga bog'langan $count ta anketa yaratadi, har biriga bitta
     * $flagCode qatorini `anketa_red_flags`ga qo'shadi. Faqat aggregatsiya
     * uchun kerakli minimal ustunlar to'ldiriladi.
     */
    private function seedRedFlags(string $mahallaId, string $flagCode, int $count): void
    {
        $districtId = (string) DB::connection('master')->table('mahallas')
            ->where('id', $mahallaId)->value('district_id');

        for ($i = 0; $i < $count; $i++) {
            $anketaId = (string) Str::uuid();

            DB::connection('ayollar')->table('anketas')->insert([
                'id' => $anketaId,
                'woman_id' => (string) Str::uuid(),
                'mahalla_id' => $mahallaId,
                'district_id' => $districtId,
                'reg_number' => 'TEST-'.substr($anketaId, 0, 8),
                'form_version' => '1.0.0',
                'age_group' => '30-39',
                'status' => 'submitted',
                'client_uuid' => (string) Str::uuid(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->insertedAnketaIds[] = $anketaId;

            $flagId = (string) Str::uuid();
            DB::connection('ayollar')->table('anketa_red_flags')->insert([
                'id' => $flagId,
                'anketa_id' => $anketaId,
                'flag_code' => $flagCode,
                'source_question' => 1,
                'created_at' => now(),
            ]);
            $this->insertedRedFlagIds[] = $flagId;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $flags
     * @return array<string, mixed>|null
     */
    private function findFlag(array $flags, string $code): ?array
    {
        foreach ($flags as $flag) {
            if ($flag['code'] === $code) {
                return $flag;
            }
        }

        return null;
    }
}
