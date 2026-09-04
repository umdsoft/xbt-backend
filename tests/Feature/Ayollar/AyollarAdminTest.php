<?php

declare(strict_types=1);

namespace Tests\Feature\Ayollar;

use App\Domains\Ayollar\Models\AuditLog;
use App\Domains\Ayollar\Models\Metric;
use App\Domains\Ayollar\Models\SensitiveAccessLog;
use App\Domains\Ayollar\Services\BalanceCalculator;
use App\Domains\Ayollar\Support\AyollarAccess;

/**
 * ADMINISTRATOR — jurnallar, lug'at va tizim salomatligi.
 *
 * Jurnalning butun qiymati IZCHILLIGIDA: agar amal jurnalga tushmasa,
 * u tekshiruv nuqtai nazaridan sodir bo'lmagan bilan barobar. Shuning
 * uchun bu testlar «endpoint ishlaydimi» emas, «amal iz qoldiradimi»
 * degan savolga javob beradi.
 */
class AyollarAdminTest extends AyollarApiTestCase
{
    // ---------------------------------------------------------------
    // JURNAL IZI
    // ---------------------------------------------------------------

    public function test_anketa_creation_is_logged(): void
    {
        $d = $this->someDistrictId();
        $before = AuditLog::query()->where('action', 'anketa.created')->count();

        $anketa = $this->anketaIn($this->someMahallaId($d), $d);

        $this->assertSame($before + 1, AuditLog::query()->where('action', 'anketa.created')->count());

        $row = AuditLog::query()->where('entity_id', $anketa->id)->latest('created_at')->first();

        $this->assertNotNull($row);
        $this->assertSame('anketa', $row->entity_type);
        $this->assertSame($anketa->reg_number, $row->changes['reg_number']);
    }

    /**
     * Jurnalda ANKETA JAVOBLARI yo'q.
     *
     * Aks holda jurnal ikkinchi, himoyalanmagan ma'lumot omboriga
     * aylanardi: PII va V bo'lim javoblari `changes` ustunida xom
     * holda yotardi.
     */
    public function test_audit_log_never_stores_answers(): void
    {
        $d = $this->someDistrictId();

        $anketa = $this->anketaIn($this->someMahallaId($d), $d, answers: [
            'q11' => 'norasmiy_band', 'q12' => 'yoq', 'q13' => 'yoq',
            'q31' => ['violence' => true],
        ]);

        $rows = AuditLog::query()->where('entity_id', $anketa->id)->get();

        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $json = (string) json_encode($row->changes);

            $this->assertStringNotContainsString('violence', $json);
            $this->assertStringNotContainsString('q31', $json);
        }
    }

    /** Balansni yopish jurnalga tushadi. */
    public function test_balance_close_is_logged(): void
    {
        $d = $this->someDistrictId();
        $m = $this->someMahallaId($d);
        $this->anketaIn($m, $d);

        $balance = app(BalanceCalculator::class)->calculateMahalla($m, (int) now()->year, (int) now()->month);
        $user = $this->makeUser(AyollarAccess::ROLE_CHAIRMAN, ['mahalla_id' => $m, 'district_id' => $d]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/ayollar/balances/mahalla/{$balance->id}/close")
            ->assertOk();

        $this->assertTrue(
            AuditLog::query()->where('action', 'balance.closed')->where('entity_id', $balance->id)->exists(),
        );
    }

    /** PII ochilishi IKKALA jurnalga ham tushadi. */
    public function test_pii_reveal_is_in_both_logs(): void
    {
        $d = $this->someDistrictId();
        $m = $this->someMahallaId($d);
        $woman = $this->makeWoman($this->makeHousehold($m, $d), 30, '31234567890401');

        $user = $this->makeUser(AyollarAccess::ROLE_FAMILY_DEPT, ['district_id' => $d]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/ayollar/women/{$woman->id}/reveal-pii", ['fields' => ['pinfl']])
            ->assertOk();

        $this->assertTrue(
            AuditLog::query()->where('action', 'pii.revealed')->where('entity_id', $woman->id)->exists(),
            'Amallar jurnalida yo‘q.',
        );
        $this->assertTrue(
            SensitiveAccessLog::query()->where('woman_id', $woman->id)->exists(),
            'Maxfiy kirish jurnalida yo‘q.',
        );
    }

    /** Eksport ham iz qoldiradi — suv belgisidan mustaqil. */
    public function test_export_is_logged(): void
    {
        $d = $this->someDistrictId();
        $this->anketaIn($this->someMahallaId($d), $d);

        $user = $this->makeUser(AyollarAccess::ROLE_FAMILY_DEPT, ['district_id' => $d]);
        $before = AuditLog::query()->where('action', 'export.registry')->count();

        $this->actingAs($user, 'sanctum')->get('/api/ayollar/export/registry')->assertOk();

        $this->assertSame($before + 1, AuditLog::query()->where('action', 'export.registry')->count());
    }

    // ---------------------------------------------------------------
    // ADMIN ENDPOINTLARI
    // ---------------------------------------------------------------

    public function test_audit_endpoint_requires_permission(): void
    {
        $d = $this->someDistrictId();

        $this->actingAs($this->makeUser(AyollarAccess::ROLE_ADMIN, ['district_id' => $d]), 'sanctum')
            ->getJson('/api/ayollar/admin/audit')
            ->assertOk();

        foreach ([AyollarAccess::ROLE_ACTIVIST, AyollarAccess::ROLE_ANALYST, AyollarAccess::ROLE_CHAIRMAN] as $role) {
            $this->actingAs($this->makeUser($role, ['district_id' => $d, 'mahalla_id' => $this->someMahallaId($d)]), 'sanctum')
                ->getJson('/api/ayollar/admin/audit')
                ->assertForbidden();
        }
    }

    /** Jurnal foydalanuvchi NOMI bilan keladi — ID tekshiruv uchun yaroqsiz. */
    public function test_audit_includes_user_name(): void
    {
        $d = $this->someDistrictId();
        $this->anketaIn($this->someMahallaId($d), $d);

        $admin = $this->makeUser(AyollarAccess::ROLE_ADMIN, ['district_id' => $d]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/ayollar/admin/audit')
            ->assertOk()
            ->assertJsonStructure(['data' => [['action', 'entity_type', 'created_at', 'user_name']]]);
    }

    public function test_sensitive_access_log_is_separate(): void
    {
        $d = $this->someDistrictId();
        $admin = $this->makeUser(AyollarAccess::ROLE_ADMIN, ['district_id' => $d]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/ayollar/admin/sensitive-access')
            ->assertOk()
            ->assertJsonStructure(['data', 'total']);
    }

    /**
     * TIZIM SALOMATLIGI — zinapoya va lug'at SINXRONMI.
     *
     * Bu tekshiruv eng qimmatlisi: `rules.json` ga yangi qator
     * qo'shilib, `metric_registry` ga qo'shilmasa, balans shaklida
     * o'sha qator YO'QOLADI va buni hech qanday test ushlamasdi.
     */
    public function test_health_reports_rules_and_registry_in_sync(): void
    {
        $d = $this->someDistrictId();
        $admin = $this->makeUser(AyollarAccess::ROLE_ADMIN, ['district_id' => $d]);

        $health = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/ayollar/admin/health')
            ->assertOk()
            ->json();

        $this->assertSame(22, $health['ladder_steps']);
        $this->assertSame(13, $health['red_flags']);
        $this->assertSame(
            [],
            $health['missing_metrics'],
            'Zinapoyada bor, lug‘atda yo‘q qator: balans shaklida yo‘qoladi.',
        );
    }

    // ---------------------------------------------------------------
    // LUG'AT
    // ---------------------------------------------------------------

    public function test_metric_can_be_renamed_but_not_recoded(): void
    {
        $d = $this->someDistrictId();
        $admin = $this->makeUser(AyollarAccess::ROLE_ADMIN, ['district_id' => $d]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/ayollar/admin/metrics/yel_informal', [
                'name_lat' => 'Norasmiy bandlik (yangilangan)',
                'code' => 'boshqa_kod',
            ])
            ->assertOk()
            ->assertJsonPath('metric.name_lat', 'Norasmiy bandlik (yangilangan)')
            // `code` O'ZGARMAYDI: u `rules.json` dagi `balance_row` bilan
            // bog'langan va o'zgarsa barcha saqlangan balanslar yaroqsiz
            // bo'lardi.
            ->assertJsonPath('metric.code', 'yel_informal');

        $this->assertSame('yel_informal', Metric::query()->where('code', 'yel_informal')->value('code'));
    }

    public function test_metric_update_requires_manage_permission(): void
    {
        $d = $this->someDistrictId();
        $analyst = $this->makeUser(AyollarAccess::ROLE_ANALYST, ['district_id' => $d]);

        $this->actingAs($analyst, 'sanctum')
            ->patchJson('/api/ayollar/admin/metrics/yel_informal', ['name_lat' => 'x'])
            ->assertForbidden();
    }

    /** Lug'atni KO'RISH hammaga ochiq — shakl qatorlari nomi kerak. */
    public function test_metric_list_is_readable_by_any_role(): void
    {
        $d = $this->someDistrictId();
        $chairman = $this->makeUser(AyollarAccess::ROLE_CHAIRMAN, [
            'mahalla_id' => $this->someMahallaId($d), 'district_id' => $d,
        ]);

        $this->actingAs($chairman, 'sanctum')
            ->getJson('/api/ayollar/admin/metrics')
            ->assertOk()
            ->assertJsonPath('can_manage', false);
    }
}
