<?php

declare(strict_types=1);

namespace Tests\Feature\Mahalla;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `mahalla:make-viewer` — Vazifa 5: buyruq endi `tuman` hisobini ham yaratadi.
 *
 * Oldingi holat: buyruq `role` ustuniga qattiq `'viloyat'` yozardi va faqat
 * `auth` ulanishiga tegardi. Endi `--role=tuman --district=<uuid>` bilan
 * `mahalla.users` profilini ham (district_id to'ldirilgan holda) yaratishi
 * kerak — aks holda `tuman` rolini yaratishning yagona yo'li qo'lda SQL
 * bo'lib qolar edi.
 */
class MakeViewerCommandTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, string> */
    protected array $connectionsToTransact = ['pgsql', 'auth', 'master', 'mahalla'];

    public function test_viloyat_is_created_without_profile(): void
    {
        $login = 'test_vil_'.Str::random(6);

        $this->artisan('mahalla:make-viewer', ['login' => $login, 'name' => 'ТЕСТ вилоят'])
            ->assertSuccessful();

        $userId = DB::connection('auth')->table('users')->where('login', $login)->value('id');
        $this->assertNotNull($userId);
        $this->assertSame('viloyat', DB::connection('auth')->table('user_system_access')
            ->where('user_id', $userId)->value('role'));
        $this->assertSame(0, DB::connection('mahalla')->table('users')->where('id', $userId)->count(),
            'viloyat uchun profil yaratilmaydi');
    }

    public function test_tuman_is_created_with_district_profile(): void
    {
        $login = 'test_tum_'.Str::random(6);
        $districtId = $this->districtId();

        $this->artisan('mahalla:make-viewer', [
            'login' => $login, 'name' => 'ТЕСТ туман',
            '--role' => 'tuman', '--district' => $districtId,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('tuman')
            ->expectsOutputToContain($this->districtName($districtId));

        $userId = DB::connection('auth')->table('users')->where('login', $login)->value('id');
        $this->assertNotNull($userId);
        $this->assertSame('tuman', DB::connection('auth')->table('user_system_access')
            ->where('user_id', $userId)->value('role'));
        $this->assertSame($districtId, DB::connection('mahalla')->table('users')
            ->where('id', $userId)->value('district_id'));
    }

    public function test_tuman_without_district_is_rejected_and_creates_nothing(): void
    {
        $login = 'test_nod_'.Str::random(6);

        $this->artisan('mahalla:make-viewer', [
            'login' => $login, 'name' => 'ТЕСТ', '--role' => 'tuman',
        ])->assertFailed();

        $this->assertSame(0, DB::connection('auth')->table('users')->where('login', $login)->count(),
            'rad etilgan buyruq HECH NARSA yaratmasligi kerak');
        $this->assertSame(0, DB::connection('mahalla')->table('users')->where('login', $login)->count());
    }

    public function test_unknown_district_is_rejected_and_creates_nothing(): void
    {
        $login = 'test_bad_'.Str::random(6);

        $this->artisan('mahalla:make-viewer', [
            'login' => $login, 'name' => 'ТЕСТ', '--role' => 'tuman',
            '--district' => '00000000-0000-0000-0000-000000000000',
        ])->assertFailed();

        $this->assertSame(0, DB::connection('auth')->table('users')->where('login', $login)->count());
        $this->assertSame(0, DB::connection('mahalla')->table('users')->where('login', $login)->count());
    }

    public function test_operational_roles_are_rejected(): void
    {
        foreach (['deputat', 'rais', 'hokim-yordamchisi', 'nonsense'] as $role) {
            $login = 'test_op_'.Str::random(6);

            $this->artisan('mahalla:make-viewer', [
                'login' => $login, 'name' => 'ТЕСТ', '--role' => $role,
            ])->assertFailed();

            $this->assertSame(0, DB::connection('auth')->table('users')->where('login', $login)->count(),
                "«{$role}» roli bu buyruq orqali yaratilmasligi kerak");
        }
    }

    /**
     * `admin` `MahallaAccess::VIEWER_ROLES` ichida bor, lekin bu buyruq FAQAT
     * kўruvchi hisoblar uchun — admin boshqaruv roli, uni bu yo'l bilan
     * yaratib bo'lmasligi kerak.
     */
    public function test_admin_role_is_rejected_and_creates_nothing(): void
    {
        $login = 'test_adm_'.Str::random(6);

        $this->artisan('mahalla:make-viewer', [
            'login' => $login, 'name' => 'ТЕСТ', '--role' => 'admin',
        ])->assertFailed();

        $this->assertSame(0, DB::connection('auth')->table('users')->where('login', $login)->count(),
            'admin bu buyruq orqali yaratilmasligi kerak');
    }

    /**
     * Mavjud xulq regressiyasi — `withTrashed()` tekshiruvi buzilmasin: soft
     * delete qilingan foydalanuvchi ham "band login" deb hisoblanadi.
     */
    public function test_duplicate_login_is_still_rejected(): void
    {
        $login = 'test_dup_'.Str::random(6);

        $this->artisan('mahalla:make-viewer', ['login' => $login, 'name' => 'ТЕСТ биринчи'])
            ->assertSuccessful();

        $firstId = DB::connection('auth')->table('users')->where('login', $login)->value('id');
        User::on('auth')->findOrFail($firstId)->delete();

        $this->artisan('mahalla:make-viewer', ['login' => $login, 'name' => 'ТЕСТ иккинчи'])
            ->assertFailed();

        // Raw query builder'da global scope yo'q — soft-delete qilingan qator
        // ham sanaladi, shuning uchun `withTrashed()` shart emas.
        $this->assertSame(1, DB::connection('auth')->table('users')->where('login', $login)->count(),
            'ikkinchi marta yaratilmasligi kerak');
    }

    // ---------- fikstura yordamchilari ----------

    /**
     * Standart tuman (Shovot) — haqiqiy bazadan, yaratilmaydi.
     */
    protected function districtId(): string
    {
        $id = DB::connection('master')->table('districts')
            ->where('soato_code', (string) config('mahalla.executive.default_district_soato'))
            ->value('id');

        $this->assertNotNull($id, 'Standart tuman (Shovot) bazada bo\'lishi kerak');

        return (string) $id;
    }

    protected function districtName(string $districtId): string
    {
        return (string) DB::connection('master')->table('districts')
            ->where('id', $districtId)->value('name_cyr');
    }
}
