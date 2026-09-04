<?php

declare(strict_types=1);

namespace Tests\Feature\Ayollar;

use App\Domains\Ayollar\Models\Anketa;
use App\Domains\Ayollar\Models\Household;
use App\Domains\Ayollar\Models\Staff;
use App\Domains\Ayollar\Models\Woman;
use App\Domains\Ayollar\Services\AnketaService;
use App\Domains\Ayollar\Support\AyollarAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ayollar domeni feature testlari poydevori.
 *
 * Ko'p sxemali: `auth` (foydalanuvchi), `master` (geografiya), `ayollar`
 * (domen). To'rtala ulanish ham `connectionsToTransact` da — biri tushib
 * qolsa, test yaratgan yozuvlar dev bazasida ABADIY qolib ketardi.
 */
abstract class AyollarTestCase extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, string> */
    protected array $connectionsToTransact = ['pgsql', 'auth', 'master', 'ayollar'];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function ayollarSystemId(): string
    {
        $auth = DB::connection('auth');
        $id = $auth->table('systems')->where('code', AyollarAccess::SYSTEM_CODE)->value('id');

        if ($id !== null) {
            return (string) $id;
        }

        $id = (string) Str::uuid();
        $auth->table('systems')->insert([
            'id' => $id, 'code' => AyollarAccess::SYSTEM_CODE, 'name' => 'Аёллар баланси',
            'is_active' => true, 'sort_order' => 8, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * Ayollar foydalanuvchisi: `auth.users` + `user_system_access` +
     * `ayollar.staff` (doira).
     *
     * @param  array<string, mixed>  $scope  region_id / district_id / mahalla_id / org_code
     */
    protected function makeUser(string $role, array $scope = []): User
    {
        $userId = (string) Str::uuid();
        $now = now();

        DB::connection('auth')->table('users')->insert([
            'id' => $userId, 'login' => 'ayl_'.substr($userId, 0, 8), 'password' => bcrypt('secret'),
            'name' => 'Sinov '.substr($userId, 0, 4), 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        DB::connection('auth')->table('user_system_access')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $userId, 'system_id' => $this->ayollarSystemId(),
            'role' => $role, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        if ($scope !== []) {
            Staff::query()->create(array_merge([
                'user_id' => $userId,
                'position' => 'Sinov lavozimi',
                'is_active' => true,
            ], $scope));
        }

        return User::on('auth')->findOrFail($userId);
    }

    /** Rolsiz (boshqa modul) foydalanuvchisi — 403 tekshiruvi uchun. */
    protected function makeOutsider(): User
    {
        $userId = (string) Str::uuid();
        DB::connection('auth')->table('users')->insert([
            'id' => $userId, 'login' => 'out_'.substr($userId, 0, 8), 'password' => bcrypt('secret'),
            'name' => 'Begona', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return User::on('auth')->findOrFail($userId);
    }

    // ---------------------------------------------------------------
    // Geografiya (`master`)
    // ---------------------------------------------------------------

    protected function someDistrictId(): string
    {
        return (string) DB::connection('master')->table('districts')->orderBy('sort_order')->value('id');
    }

    protected function otherDistrictId(string $exceptId): string
    {
        return (string) DB::connection('master')->table('districts')
            ->where('id', '!=', $exceptId)->orderBy('sort_order')->value('id');
    }

    protected function someMahallaId(?string $districtId = null): string
    {
        $q = DB::connection('master')->table('mahallas');

        if ($districtId !== null) {
            $q->where('district_id', $districtId);
        }

        return (string) $q->orderBy('sort_order')->value('id');
    }

    protected function otherMahallaId(string $exceptId, ?string $districtId = null): string
    {
        $q = DB::connection('master')->table('mahallas')->where('id', '!=', $exceptId);

        if ($districtId !== null) {
            $q->where('district_id', $districtId);
        }

        return (string) $q->orderBy('sort_order')->value('id');
    }

    // ---------------------------------------------------------------
    // Domen fiksturalari
    // ---------------------------------------------------------------

    protected function makeHousehold(string $mahallaId, string $districtId): Household
    {
        return Household::query()->create([
            'mahalla_id' => $mahallaId,
            'district_id' => $districtId,
            'address' => 'TEST '.Str::random(6),
            'in_social_registry' => false,
        ]);
    }

    /**
     * Ayol yaratadi. Rozilik imzosi sukut bo'yicha QO'YILGAN — aks holda
     * har test uni alohida qo'yishga majbur bo'lardi va roziliksiz holat
     * testi bilan aralashib ketardi.
     */
    protected function makeWoman(Household $household, int $age, ?string $pinfl = null): Woman
    {
        $woman = new Woman;
        $woman->fill([
            'household_id' => $household->id,
            'mahalla_id' => $household->mahalla_id,
            'district_id' => $household->district_id,
            'full_name' => 'Sinov Ayol '.Str::random(5),
            'full_name_norm' => 'sinov ayol',
            'birth_date' => now()->subYears($age)->subDays(10),
            'age_group' => $this->ageGroup($age),
            'consent_signed_at' => now(),
        ]);

        if ($pinfl !== null) {
            $woman->pinfl = $pinfl;
        }

        $woman->save();

        return $woman;
    }

    /**
     * Anketa yaratadi va toifasini hisoblaydi.
     *
     * @param  array<string, mixed>  $answers
     */
    protected function makeAnketa(
        Woman $woman,
        array $answers,
        string $status = Anketa::STATUS_COMPLETED,
        ?User $createdBy = null,
    ): Anketa {
        return app(AnketaService::class)->save(
            $woman,
            $answers,
            ['status' => $status],
            '08',
            (string) random_int(1000, 9999),
            $createdBy === null ? null : (string) $createdBy->id,
        );
    }

    private function ageGroup(int $age): string
    {
        return match (true) {
            $age <= 2 => '0_2',
            $age <= 6 => '3_6',
            $age <= 17 => '7_17',
            default => '18_up',
        };
    }
}
