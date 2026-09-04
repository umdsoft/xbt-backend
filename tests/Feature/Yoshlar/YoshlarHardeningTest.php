<?php

declare(strict_types=1);

namespace Tests\Feature\Yoshlar;

use App\Domains\Yoshlar\Models\EmploymentCase;
use App\Domains\Yoshlar\Models\Notification;
use App\Domains\Yoshlar\Models\Organization;
use App\Domains\Yoshlar\Models\Sector;
use App\Domains\Yoshlar\Models\Staff;
use App\Domains\Yoshlar\Models\Task;
use App\Domains\Yoshlar\Models\Youth;
use App\Domains\Yoshlar\Models\YouthCase;
use App\Domains\Yoshlar\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Chuqur audit natijasida topilgan nuqsonlar uchun qopqoq testlar.
 *
 * Har test AYNAN bitta teshikni yopadi — kelajakda kod o'zgarganda
 * o'sha teshik qaytib ochilsa, shu test qulaydi.
 */
class YoshlarHardeningTest extends YoshlarTestCase
{
    // ---------- 1. Doiradan tashqariga ko'chirish ----------

    public function test_youth_cannot_be_moved_out_of_own_district(): void
    {
        $own = $this->someDistrictId();
        $other = $this->otherDistrictId($own);

        $youth = $this->makeYouth($own);
        $org = $this->makeOrganization(Organization::TYPE_TUMAN_YOSHLAR, ['district_id' => $own]);
        $user = $this->makeUser('yoshlar_bolim', $org->id);

        // Tuman bo'limi o'z yozuvini boshqa tumanga KO'CHIRA olmaydi:
        // aks holda yozuv ikkala tuman uchun ham «yo'qolgan» bo'lardi.
        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/yoshlar/youth/{$youth->id}", [
                'district_id' => $other,
                'mahalla_id' => $this->someMahallaId($other),
            ])
            ->assertStatus(403);

        $this->assertSame($own, $youth->fresh()->district_id);
    }

    public function test_admin_can_move_youth_between_districts(): void
    {
        $own = $this->someDistrictId();
        $other = $this->otherDistrictId($own);
        $youth = $this->makeYouth($own);

        // Admin viloyat darajasida — ko'chirish uning vakolatida.
        $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->patchJson("/api/yoshlar/youth/{$youth->id}", [
                'district_id' => $other,
                'mahalla_id' => $this->someMahallaId($other),
            ])
            ->assertOk();

        $this->assertSame($other, $youth->fresh()->district_id);
    }

    // ---------- 2. Tashkilot mavjudligi va turi ----------

    public function test_task_cannot_be_assigned_to_nonexistent_org(): void
    {
        $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->postJson('/api/yoshlar/tasks', [
                'title' => 'Sinov',
                'assigned_org_id' => (string) Str::uuid(),   // mavjud emas
                'deadline' => now()->addDays(5)->toDateString(),
                'priority' => 'orta',
            ])
            ->assertStatus(422);
    }

    public function test_task_cannot_be_led_by_the_final_approver(): void
    {
        // QOIDA ANIQLASHTIRILDI (2026-08-24).
        //
        // Ilgari butun yoshlar vertikali ijrochi boʻlishdan man etilgan
        // edi. Ammo rasmiy hujjatda masʼul koʻpincha tuman yoshlar
        // boʻlimi boʻladi va uni tanlab boʻlmagani uchun band tizimga
        // toʻgʻri kiritilmasdi.
        //
        // Haqiqiy xavf torroq: viloyat yoshlar boshqarmasi zanjirda
        // YAKUNIY tasdiqlovchi, shuning uchun aynan U bosh ijrochi
        // boʻlsa, oʻz ishini oʻzi tasdiqlardi.
        $org = $this->makeOrganization(Organization::TYPE_VILOYAT_YOSHLAR, [
            'district_id' => $this->someDistrictId(),
        ]);

        $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->postJson('/api/yoshlar/tasks', [
                'title' => 'Sinov',
                'assigned_org_id' => $org->id,
                'deadline' => now()->addDays(5)->toDateString(),
                'priority' => 'orta',
            ])
            ->assertStatus(422);
    }

    public function test_task_district_is_derived_from_organization(): void
    {
        $districtId = $this->someDistrictId();
        $org = $this->sectorOrg($districtId);

        // Qo'lda BOSHQA tuman yuborilsa ham, tashkilotniki ustun turadi:
        // aks holda topshiriq hisobotlarda noto'g'ri tumanga tushardi.
        $id = $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->postJson('/api/yoshlar/tasks', [
                'title' => 'Sinov',
                'assigned_org_id' => $org->id,
                'district_id' => $this->otherDistrictId($districtId),
                'deadline' => now()->addDays(5)->toDateString(),
                'priority' => 'orta',
            ])
            ->assertCreated()->json('data.id');

        $this->assertSame($districtId, Task::query()->findOrFail($id)->district_id);
    }

    public function test_case_cannot_be_assigned_to_nonexistent_org(): void
    {
        $youth = $this->makeYouth($this->someDistrictId());

        $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->postJson('/api/yoshlar/cases', [
                'youth_id' => $youth->id,
                'category' => 'bandlik',
                'source' => 'mahalla',
                'title' => 'Sinov',
                'assigned_org_id' => (string) Str::uuid(),
            ])
            ->assertStatus(422);
    }

    // ---------- 3. Audit to'liqligi ----------

    public function test_task_update_is_audited(): void
    {
        $districtId = $this->someDistrictId();
        $org = $this->sectorOrg($districtId);

        $task = Task::query()->create([
            'title' => 'TEST-'.Str::random(6),
            'assigned_org_id' => $org->id,
            'district_id' => $districtId,
            'deadline' => now()->addDays(10)->toDateString(),
            'priority' => 'orta',
            'status' => 'belgilandi',
        ]);

        $admin = $this->makeUser('yoshlar_admin');

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/yoshlar/tasks/{$task->id}", ['title' => 'Yangilangan matn'])
            ->assertOk();

        // Muddat yoki mas'ulni o'zgartirish — nazorat uchun muhim amal,
        // u jurnalsiz qolmasligi kerak (TZ 5.10).
        $this->assertTrue(
            DB::connection('yoshlar')->table('audit_log')
                ->where('action', 'task.update')->where('entity_id', $task->id)->exists(),
        );
    }

    // ---------- 4. Atomarlik ----------

    public function test_employment_confirmation_and_registry_update_are_atomic(): void
    {
        $districtId = $this->someDistrictId();
        $youth = $this->makeYouth($districtId, ['employment_status' => 'band_emas', 'is_neet' => true]);

        $taxSector = Sector::query()->firstOrCreate(
            ['code' => 'soliq'],
            ['name_cyr' => 'Солиқ', 'name_lat' => 'Soliq'],
        );
        $taxProvince = $this->makeOrganization(Organization::TYPE_VILOYAT_SEKTOR, ['sector_id' => $taxSector->id]);

        $case = EmploymentCase::query()->create([
            'youth_id' => $youth->id,
            'district_id' => $districtId,
            'employer_name' => 'MChJ Sinov',
            'start_date' => now()->toDateString(),
            'status' => EmploymentCase::STATUS_TAX_DISTRICT,
            'submitted_by' => $this->makeUser('yoshlar_admin')->id,
            'submitted_at' => now(),
        ]);

        $this->actingAs($this->makeUser('sektor_boshqarma', $taxProvince->id), 'sanctum')
            ->postJson("/api/yoshlar/employment/{$case->id}/review", ['approve' => true])
            ->assertOk();

        // Ikkalasi ham o'zgargan bo'lishi kerak — biri o'zgarib, ikkinchisi
        // qolib ketsa, ikki joyda ikki xil haqiqat bo'lardi.
        $this->assertSame(EmploymentCase::STATUS_CONFIRMED, $case->fresh()->status);
        $this->assertSame('band', $youth->fresh()->employment_status);
        $this->assertFalse($youth->fresh()->is_neet);
    }

    public function test_case_creation_and_registry_flag_are_atomic(): void
    {
        $districtId = $this->someDistrictId();
        $youth = $this->makeYouth($districtId);

        $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->postJson('/api/yoshlar/cases', [
                'youth_id' => $youth->id,
                'category' => 'talim',
                'source' => 'yosh',
                'title' => 'Oʻqishga qaytarish',
            ])
            ->assertCreated();

        $this->assertTrue($youth->fresh()->has_open_case);
        $this->assertSame(1, YouthCase::query()->where('youth_id', $youth->id)->count());
    }

    // ---------- 5. Bildirishnoma: so'rov soni ----------

    public function test_bulk_notification_uses_constant_query_count(): void
    {
        $districtId = $this->someDistrictId();
        $org = $this->sectorOrg($districtId);

        // Bitta tashkilotda 10 xodim.
        for ($i = 0; $i < 10; $i++) {
            $user = $this->makeUser('sektor_bolim');
            Staff::query()->where('user_id', $user->id)->delete();
            Staff::query()->create([
                'user_id' => $user->id, 'org_id' => $org->id,
                'is_active' => true, 'can_patronage' => false,
            ]);
        }

        $entityId = (string) Str::uuid();

        DB::connection('yoshlar')->enableQueryLog();

        $sent = app(NotificationService::class)->notifyOrganization($org->id, 'task.overdue', [
            'title' => 'Sinov',
            'entity_id' => $entityId,
            'entity_type' => 'task',
        ]);

        $queries = count(DB::connection('yoshlar')->getQueryLog());
        DB::connection('yoshlar')->disableQueryLog();

        // Xodimlar soni oshsa ham so'rovlar soni oshmasligi kerak:
        // staff ro'yxati + mavjudlar + bitta insert = 3 ta.
        $this->assertLessThanOrEqual(4, $queries, "10 xodimga xabar {$queries} soʻrov oldi — N+1 qaytdi");

        // Va hamma xodim xabarni olgan bo'lishi kerak.
        $this->assertSame(10, $sent);
        $this->assertSame(
            10,
            Notification::query()->where('entity_id', $entityId)->count(),
        );
    }

    // ---------- Yordamchilar ----------

    private function sectorOrg(string $districtId): Organization
    {
        $sector = Sector::query()->firstOrCreate(
            ['code' => 'bandlik'],
            ['name_cyr' => 'Бандлик', 'name_lat' => 'Bandlik'],
        );

        $province = $this->makeOrganization(Organization::TYPE_VILOYAT_SEKTOR, ['sector_id' => $sector->id]);

        return $this->makeOrganization(Organization::TYPE_TUMAN_SEKTOR, [
            'sector_id' => $sector->id,
            'parent_id' => $province->id,
            'district_id' => $districtId,
        ]);
    }

    /** @param array<string, mixed> $attrs */
    private function makeYouth(string $districtId, array $attrs = []): Youth
    {
        return Youth::query()->create(array_merge([
            'last_name' => 'Audit'.Str::random(4),
            'first_name' => 'Test',
            'birth_date' => now()->subYears(20)->toDateString(),
            'gender' => 'erkak',
            'district_id' => $districtId,
            'mahalla_id' => $this->someMahallaId($districtId),
            'verification_status' => 'verified',
        ], $attrs));
    }
}
