<?php

declare(strict_types=1);

namespace Tests\Feature\Ayollar;

use App\Domains\Ayollar\Support\AyollarAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Poydevor testi: schema, ulanish, SSO gvardiyasi va context endpoint'i.
 * Domen jadvallari hali yo'q — bu yerda faqat ULANISH to'g'riligi tekshiriladi.
 */
class AyollarFoundationTest extends AyollarTestCase
{
    public function test_ayollar_schema_and_connection_exist(): void
    {
        $this->assertSame('pgsql', config('database.connections.ayollar.driver'));
        $this->assertSame(
            config('database.connections.pgsql.database'),
            config('database.connections.ayollar.database'),
            'Ayollar boshqa bazaga ulanmoqda — platforma BITTA bazada ishlaydi.'
        );

        $exists = DB::connection('ayollar')->selectOne(
            "select 1 as ok from information_schema.schemata where schema_name = 'ayollar'"
        );
        $this->assertNotNull($exists, 'ayollar schema yaratilmagan — migratsiya yurgizilmagan.');
        $this->assertTrue(Schema::connection('ayollar')->hasTable('audit_log'));
    }

    /**
     * `search_path` tartibi: `ayollar` `master`/`public` dan OLDIN.
     *
     * Buzilsa modul jimgina boshqa schema'ning nomdosh jadvalini o'qiy
     * boshlardi — xato emas, shunchaki NOTO'G'RI ma'lumot.
     */
    public function test_search_path_puts_ayollar_first(): void
    {
        $this->assertSame(
            'ayollar,master,public',
            config('database.connections.ayollar.search_path')
        );
    }

    public function test_system_is_registered_in_sso_registry(): void
    {
        $this->assertNotNull(
            DB::connection('auth')->table('systems')
                ->where('code', AyollarAccess::SYSTEM_CODE)->value('id'),
            'auth.systems da `ayollar` yo‘q — SystemsSeeder yurgizilmagan.'
        );
    }

    public function test_context_requires_authentication(): void
    {
        $this->getJson('/api/ayollar/context')->assertUnauthorized();
    }

    /** Boshqa modul foydalanuvchisi 403 oladi — 401 EMAS (login sikli bo'lmasin). */
    public function test_context_forbids_user_without_ayollar_role(): void
    {
        $this->actingAs($this->makeOutsider(), 'sanctum')
            ->getJson('/api/ayollar/context')
            ->assertForbidden();
    }

    public function test_context_returns_role_permissions_and_reference(): void
    {
        $response = $this->actingAs($this->makeUser(AyollarAccess::ROLE_ADMIN), 'sanctum')
            ->getJson('/api/ayollar/context')
            ->assertOk()
            ->assertJsonPath('role', AyollarAccess::ROLE_ADMIN)
            ->assertJsonStructure([
                'user' => ['id', 'name', 'login'],
                'role', 'role_name', 'permissions', 'can_see_red_names',
                'scope' => ['level', 'sees_everything'],
                'reference' => ['districts', 'mahallas', 'metrics', 'roles', 'district_orgs'],
            ]);

        $this->assertContains('ayollar.admin', $response->json('permissions'));
        $this->assertNotEmpty($response->json('reference.districts'), 'master.districts bo‘sh.');
        $this->assertNotEmpty($response->json('reference.metrics'), 'metric_registry bo‘sh — seeder yurgizilmagan.');
        $this->assertCount(8, $response->json('reference.district_orgs'), 'Tuman darajasida 8 imzo bo‘lishi kerak.');
    }

    /**
     * Viloyat tahlilchisi AGREGAT ko'radi, shaxsni emas.
     *
     * `pii.reveal` va `red.names` unda ATAYLAB yo'q — promt §6.3.
     */
    public function test_analyst_sees_aggregate_not_identities(): void
    {
        $response = $this->actingAs($this->makeUser(AyollarAccess::ROLE_ANALYST), 'sanctum')
            ->getJson('/api/ayollar/context')
            ->assertOk();

        $permissions = $response->json('permissions');

        $this->assertContains('ayollar.analytics.view', $permissions);
        $this->assertNotContains('ayollar.pii.reveal', $permissions);
        $this->assertNotContains('ayollar.red.names', $permissions);
    }
}
