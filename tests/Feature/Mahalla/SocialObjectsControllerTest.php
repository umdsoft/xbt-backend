<?php

declare(strict_types=1);

namespace Tests\Feature\Mahalla;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `SocialObjectsController` — `?mahalla=` query parametri xavfsizligi.
 *
 * Xavfsizlik ko'rigi (2026-09) topilgan ikki masala:
 *
 * MEDIUM-2: begona tumandagi mahalla `?mahalla=` sifatida berilsa, kontroller
 * bo'sh natija qaytarishi kerak (113 raqamining orqasidagi ro'yxatni chetdan
 * o'qib olishning oldi). Javob shaklining o'zi (bo'sh ro'yxat) bu tekshiruvni
 * QULFLAMAYDI — chunki `ExecutiveStats::buildSocialObjects()` o'zi ham
 * `district_id` + `mahalla_id` juftligi bo'yicha qattiq filtrlaydi (himoya-
 * chuqurlik), ya'ni tekshiruv o'chirilsa ham natija tasodifan bir xil bo'sh
 * shaklga chiqadi (bu amalda tekshirilgan — `.superpowers/sdd/tuman/
 * security-followup-report.md`). Shuning uchun bu yerda SQL so'rov jurnali
 * bilan nazorat qilinadi: tekshiruv o'z ishini qilsa, `buildings` jadvaliga
 * UMUMAN so'rov bormasligi kerak (kontroller servisga yetmasdan qaytadi).
 *
 * LOW-1: noto'g'ri formatdagi `?mahalla=` (UUID emas yoki massiv) to'g'ridan-
 * to'g'ri Postgres'ga yetib borib `SQLSTATE[22P02]` bilan 500 qaytarardi.
 */
class SocialObjectsControllerTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, string> */
    protected array $connectionsToTransact = ['pgsql', 'auth', 'master', 'mahalla'];

    /**
     * MUHIM ISBOT TESTI. Agar kimdir kontrollerdagi `$belongs` blokini olib
     * tashlasa, bu test YIQILADI — chunki servis endi to'g'ridan-to'g'ri
     * begona mahalla id'si bilan chaqirilib, `buildings` jadvaliga so'rov
     * yuboradi. Javob tanasi o'zi buni tutmaydi (yuqoridagi izohga qarang),
     * shuning uchun bu yerda ikkala qatlam ham (HTTP javobi + haqiqatan
     * bajarilgan SQL so'rovlar) tekshiriladi.
     */
    public function test_belongs_check_short_circuits_before_computing_stats_for_foreign_mahalla(): void
    {
        $own = $this->districtId();
        $other = $this->anotherDistrictId($own);
        $user = $this->makeUser('viloyat');

        $foreign = DB::connection('master')->table('mahallas')
            ->where('district_id', $other)->value('id');
        $this->assertNotNull($foreign, 'Boshqa tumanda mahalla bo\'lishi kerak');

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if ($query->connectionName === 'master') {
                $queries[] = $query->sql;
            }
        });

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/districts/'.$own.'/social-objects?mahalla='.$foreign)
            ->assertOk()
            ->assertExactJson(['total' => 0, 'types' => [], 'objects' => []]);

        $touchedBuildings = collect($queries)->contains(
            fn (string $sql) => str_contains($sql, 'buildings')
        );
        $this->assertFalse(
            $touchedBuildings,
            'Tekshiruv ERTAROQ to\'xtatishi kerak edi — "buildings" jadvaliga so\'rov borgan, '.
            'ya\'ni servis begona mahalla id\'si bilan haqiqatan chaqirilgan.'
        );
    }

    public function test_non_uuid_mahalla_query_param_returns_422_not_500(): void
    {
        $user = $this->makeUser('viloyat');
        $own = $this->districtId();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/districts/'.$own.'/social-objects?mahalla=x')
            ->assertStatus(422)
            ->assertJsonValidationErrors('mahalla');
    }

    public function test_array_mahalla_query_param_returns_422_not_500(): void
    {
        $user = $this->makeUser('viloyat');
        $own = $this->districtId();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/districts/'.$own.'/social-objects?mahalla[]=1')
            ->assertStatus(422)
            ->assertJsonValidationErrors('mahalla');
    }

    public function test_null_mahalla_query_param_is_still_allowed(): void
    {
        $user = $this->makeUser('viloyat');
        $own = $this->districtId();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/districts/'.$own.'/social-objects')
            ->assertOk();
    }

    // ---------------------------------------------------------------- yordamchi

    /** Shovot tumani (SOATO 1733230) — kadastr yuklanmagan bo'lsa test o'tkazib yuboriladi. */
    protected function districtId(): string
    {
        $id = DB::connection('master')->table('districts')
            ->where('soato_code', '1733230')->value('id');

        if ($id === null) {
            $this->markTestSkipped('Shovot kadastr ma\'lumoti yuklanmagan.');
        }

        return (string) $id;
    }

    protected function anotherDistrictId(string $exclude): string
    {
        $id = DB::connection('master')->table('districts')
            ->where('id', '!=', $exclude)->orderBy('sort_order')->value('id');

        $this->assertNotNull($id, 'Ikkinchi tuman bazada bo\'lishi kerak');

        return (string) $id;
    }

    /** Markaziy auth'da user + mahalla tizimiga rol yaratadi. */
    protected function makeUser(string $role): User
    {
        $userId = (string) Str::uuid();
        $now = now();

        DB::connection('auth')->table('users')->insert([
            'id' => $userId, 'login' => 'test_'.substr($userId, 0, 8),
            'password' => bcrypt('secret'), 'name' => 'ТЕСТ фойдаланувчи',
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $systemId = DB::connection('auth')->table('systems')
            ->where('code', 'mahalla')->value('id');

        DB::connection('auth')->table('user_system_access')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $userId, 'system_id' => $systemId, 'role' => $role,
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return User::on('auth')->findOrFail($userId);
    }
}
