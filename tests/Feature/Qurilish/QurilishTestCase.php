<?php

declare(strict_types=1);

namespace Tests\Feature\Qurilish;

use App\Domains\Qurilish\Models\ConstructionObject;
use App\Domains\Qurilish\Models\ObjectStage;
use App\Domains\Qurilish\Services\StageService;
use App\Domains\Qurilish\Support\QurilishAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Qurilish domeni feature testlari poydevori. Ko'p sxemali (auth/master/qurilish).
 * DatabaseTransactions (RefreshDatabase EMAS — dev baza ustida, murojaat naqshi).
 */
abstract class QurilishTestCase extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, string> */
    protected array $connectionsToTransact = ['pgsql', 'auth', 'master', 'qurilish'];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function qurilishSystemId(): string
    {
        $auth = DB::connection('auth');
        $id = $auth->table('systems')->where('code', QurilishAccess::SYSTEM_CODE)->value('id');
        if ($id !== null) {
            return (string) $id;
        }

        $id = (string) Str::uuid();
        $auth->table('systems')->insert([
            'id' => $id, 'code' => QurilishAccess::SYSTEM_CODE, 'name' => 'Қурилиш',
            'is_active' => true, 'sort_order' => 6, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** qurilish foydalanuvchisi: auth.users + user_system_access + qurilish.profiles. */
    protected function makeUser(string $role, ?string $organizationId = null, ?string $districtId = null): User
    {
        $userId = (string) Str::uuid();
        $now = now();

        DB::connection('auth')->table('users')->insert([
            'id' => $userId, 'login' => 'qur_'.substr($userId, 0, 8), 'password' => bcrypt('secret'),
            'name' => 'Синов '.substr($userId, 0, 4), 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::connection('auth')->table('user_system_access')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $userId, 'system_id' => $this->qurilishSystemId(),
            'role' => $role, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::connection('qurilish')->table('profiles')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $userId, 'role' => $role,
            'organization_id' => $organizationId, 'district_id' => $districtId,
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return User::on('auth')->findOrFail($userId);
    }

    /** Rolsiz (qurilish bo'lmagan) user — 403 tekshiruvi uchun. */
    protected function makeOutsider(): User
    {
        $userId = (string) Str::uuid();
        DB::connection('auth')->table('users')->insert([
            'id' => $userId, 'login' => 'out_'.substr($userId, 0, 8), 'password' => bcrypt('secret'),
            'name' => 'Бегона', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return User::on('auth')->findOrFail($userId);
    }

    protected function someDistrictId(): string
    {
        return (string) DB::connection('master')->table('districts')
            ->whereNotNull('soato_code')->orderBy('sort_order')->value('id');
    }

    /**
     * Test uchun obyekt yaratadi (8 bosqichi bilan).
     *
     * DIQQAT: testlar UMUMIY dev bazasida yuradi va u yerda haqiqiy import
     * ma'lumoti turishi mumkin. Shuning uchun har obyekt nomi noyob prefiks
     * bilan belgilanadi — assertion'lar shu bo'yicha cheklanadi.
     *
     * @param  array<string, mixed>  $attrs
     */
    protected function makeObject(array $attrs = []): ConstructionObject
    {
        $object = ConstructionObject::query()->create(array_merge([
            'name' => 'TEST-'.Str::random(8),
            'lifecycle' => 'reja',
        ], $attrs));

        app(StageService::class)->ensureStages($object);

        return $object->refresh();
    }

    /** Bosqichni to'g'ridan-to'g'ri (qoidalarni chetlab) qo'yadi — fikstura uchun. */
    protected function setStage(ConstructionObject $object, string $code, string $status): void
    {
        ObjectStage::query()->updateOrCreate(
            ['object_id' => $object->id, 'stage_code' => $code],
            ['status' => $status],
        );
    }

    /** Barcha oldingi bosqichlarni yakunlangan qilib qo'yadi. */
    protected function completeStagesBefore(ConstructionObject $object, string $stageCode): void
    {
        foreach (ConstructionObject::STAGES as $code) {
            if ($code === $stageCode) {
                return;
            }
            $this->setStage($object, $code, 'yakunlangan');
        }
    }

    /**
     * Test uchun tashkilot yaratadi va id sini qaytaradi.
     *
     * @param  array<string, mixed>  $flags
     */
    protected function makeOrganization(string $name, array $flags = []): string
    {
        $id = (string) Str::uuid();
        DB::connection('qurilish')->table('organizations')->insert(array_merge([
            'id' => $id, 'name_cyr' => $name, 'name_lat' => $name,
            'is_customer' => false, 'is_designer' => false,
            'is_contractor' => false, 'is_department' => false,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ], $flags));

        return $id;
    }
}
