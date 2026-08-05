<?php

declare(strict_types=1);

namespace Tests\Feature\Advisor;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Advisor domeni feature testlari uchun umumiy poydevor (maslahatchi userlar +
 * geo yordamchilari). Ko'p sxemali: har ulanish alohida qaytariladi
 * (dev bazaga sizmasin — HokimProjectTest naqshi).
 */
abstract class AdvisorTestCase extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['pgsql', 'auth', 'master', 'advisor'];

    protected function setUp(): void
    {
        parent::setUp();
        // DatabaseTransactions DB'ni qaytaradi, lekin keshni emas — KPI keshi
        // testlar orasida sizmasin (matritsa/xulosa/katalog keshlanadi).
        Cache::flush();
        // Fayl yuklashlar (dalil/hujjat) uchun maxfiy disk soxta — testlar diskka yozmaydi.
        Storage::fake('local');
    }

    /** Sinov uchun soxta tasdiqlovchi fayl (jurnal yozuvi majburiy talab qiladi). */
    protected function fakeEntryFile(string $name = 'dalil.pdf', string $mime = 'application/pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 40, $mime);
    }

    /** 'advisor' tizimi id (mavjud bo'lsa — o'sha; aks holda yaratiladi). */
    protected function advisorSystemId(): string
    {
        $auth = DB::connection('auth');
        $id = $auth->table('systems')->where('code', 'advisor')->value('id');
        if ($id !== null) {
            return (string) $id;
        }

        $id = (string) Str::uuid();
        $auth->table('systems')->insert([
            'id' => $id,
            'code' => 'advisor',
            'name' => 'Маслаҳатчилар',
            'is_active' => true,
            'sort_order' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * Maslahatchi user yaratadi: auth.users + user_system_access (rol) +
     * advisor.advisors profili.
     */
    protected function makeAdvisor(string $role, string $level, ?string $districtId = null): User
    {
        $userId = (string) Str::uuid();
        $now = now();

        DB::connection('auth')->table('users')->insert([
            'id' => $userId,
            'login' => 'adv_'.substr($userId, 0, 8),
            'password' => bcrypt('secret'),
            'name' => 'Синов маслаҳатчи '.substr($userId, 0, 4),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::connection('auth')->table('user_system_access')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'system_id' => $this->advisorSystemId(),
            'role' => $role,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::connection('advisor')->table('advisors')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'level' => $level,
            'district_id' => $districtId,
            'active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return User::on('auth')->findOrFail($userId);
    }

    /** Rolsiz (advisor bo'lmagan) user — 403 tekshiruvi uchun. */
    protected function makeOutsider(): User
    {
        $userId = (string) Str::uuid();
        DB::connection('auth')->table('users')->insert([
            'id' => $userId,
            'login' => 'out_'.substr($userId, 0, 8),
            'password' => bcrypt('secret'),
            'name' => 'Бегона',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::on('auth')->findOrFail($userId);
    }

    protected function someDistrictId(): string
    {
        return (string) DB::connection('master')->table('districts')
            ->whereNotNull('soato_code')->orderBy('sort_order')->value('id');
    }

    protected function anotherDistrictId(string $not): string
    {
        return (string) DB::connection('master')->table('districts')
            ->whereNotNull('soato_code')->where('id', '!=', $not)
            ->orderBy('sort_order')->value('id');
    }

    protected function aCategoryId(): string
    {
        return (string) DB::connection('advisor')->table('task_categories')
            ->orderBy('sort_order')->value('id');
    }

    /** KPI id barqaror `code` bo'yicha (katalog migratsiya/seeder'da mavjud). */
    protected function kpiIdByCode(string $code): string
    {
        return (string) DB::connection('advisor')->table('kpis')->where('code', $code)->value('id');
    }

    /** Maqsad (target) yozadi — ijro% testlari uchun (endpoint yo'q, DB orqali). */
    protected function setKpiTarget(string $kpiId, ?string $districtId, string $period, float $target): void
    {
        DB::connection('advisor')->table('kpi_targets')->insert([
            'id' => (string) Str::uuid(),
            'kpi_id' => $kpiId,
            'district_id' => $districtId,
            'period' => $period,
            'target' => $target,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
