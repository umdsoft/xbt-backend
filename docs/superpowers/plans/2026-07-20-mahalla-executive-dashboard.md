# Mahalla rahbariyat dashboard'i — implementatsiya rejasi

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Viloyat hokimi o'rinbosari uchun faqat-ko'rish dashboard: Shovot tumani mahallalari kesimida, har mahalla ichida zonalar kesimida — jami xonadon, shu haftada va bugun o'zgarganlar.

**Architecture:** Backend'da `ExecutiveStats` servisi ikkita mustaqil agregat so'rov yuritadi (maxraj `master` ulanishida, surat `mahalla` ulanishida) va PHP'da `mahalla_id` bo'yicha birlashtiradi. Ikkita kontroller javobni shakllantiradi, yangi `mahalla.viewer` middleware'i himoya qiladi. Frontend'da ikkita Vue sahifasi mavjud Tailwind dizayn tilida.

**Tech Stack:** Laravel 13 / PHP 8.4 / PostgreSQL 16 + PostGIS · Vue 3.5 + TypeScript + Pinia + Tailwind v4 · PHPUnit

**Spec:** `docs/superpowers/specs/2026-07-20-mahalla-executive-dashboard-design.md`

## Global Constraints

- PHP har doim `C:\php84\php.exe` (default `php` — 8.3, loyiha 8.4 talab qiladi).
- Loyiha ildizi: `D:\kadr\platform` (backend), `D:\kadr\mahalla` (frontend).
- **Dev bazasi `kbt` ga hech qachon `migrate:fresh`, `db:wipe`, `migrate:rollback` yurgizilmaydi.** Testlar shu bazada, lekin FAQAT `DatabaseTransactions` bilan (oxirida qaytariladi). `tests/TestCase.php` dagi darvoza `RefreshDatabase` ni to'sadi.
- Deploy/push **faqat foydalanuvchi topshirig'i bilan**. Bu reja lokal ish uchun; `git push` yo'q.
- Ko'rsatiladigan barcha matn — **kirill o'zbek** (mavjud kod bilan bir xil).
- Zona kodlari: `facade`, `kitchen`, `toilet`, `yard` (`MahallaZones::ZONES`).
- Davrlar: `Asia/Tashkent`, hafta **dushanbadan**.
- Sanoq: `COUNT(DISTINCT house_id)`, filtr `is_change = true`.

---

## Fayl tuzilishi

**Yaratiladi (backend):**
- `app/Domains/Mahalla/Services/ExecutiveStats.php` — davr hisoblash + ikkita agregat + birlashtirish. Butun hisoblash mantig'i shu yerda, kontrollerlar faqat shakllantiradi.
- `app/Domains/Mahalla/Http/Middleware/EnsureMahallaViewer.php`
- `app/Domains/Mahalla/Http/Controllers/Api/Executive/DistrictDashboardController.php`
- `app/Domains/Mahalla/Http/Controllers/Api/Executive/MahallaDashboardController.php`
- `tests/Feature/Mahalla/ExecutiveDashboardTest.php`
- `tests/Unit/Mahalla/ExecutiveStatsPeriodTest.php`

**Yaratiladi (frontend):**
- `src/pages/executive/ExecutiveDistrict.vue`
- `src/pages/executive/ExecutiveMahalla.vue`
- `src/stores/executive.ts`

**O'zgaradi:**
- `app/Domains/Mahalla/Support/MahallaAccess.php` · `MahallaScope.php` · `Models/House.php`
- `bootstrap/app.php` · `routes/api/mahalla.php` · `config/mahalla.php` · `phpunit.xml`
- `src/router/index.ts` · `src/stores/auth.ts` · `src/types/index.ts`

**Vazifalar tartibi:** 1 (test muhiti) → 2 (rol) → 3 (servis) → 4 (API) → 5 (tiplar/store) → 6 (sahifalar) → 7 (router + yakuniy tekshiruv). Sahifalar router'dan oldin yaratiladi, aks holda `import` xatosi build'ni yiqitadi.

---

## Task 1: Test muhiti (dev bazada, tranzaksiya bilan)

Platformada hozir haqiqiy test infratuzilmasi yo'q. Bu vazifa uni quradi — keyingi barcha vazifalar shunga tayanadi.

**Nega alohida `kbt_test` bazasi EMAS:** `kbt` foydalanuvchisida `CREATEDB` huquqi yo'q va uni berish superuser parolini talab qiladi.

**Nega fikstura geo yaratilmaydi:** `mahalla.houses` da schema'lararo tashqi kalitlar bor (`district_id → master.districts`, `mahalla_id → master.mahallas`, `street_id → master.streets`). Tranzaksiya usulida har ulanish o'z tranzaksiyasida bo'ladi, ya'ni `master` ulanishida yaratilgan (hali commit qilinmagan) tumanni `mahalla` ulanishidagi tashqi kalit tekshiruvi **ko'rmaydi** va INSERT yiqiladi.

**Shuning uchun:** testlar **haqiqiy kadastr geo ma'lumotidan** foydalanadi (u allaqachon commit qilingan), faqat operatsion qatorlarni (`houses`, `zone_observations`) tranzaksiya ichida yaratadi va oxirida hammasi qaytariladi. Bazada hech narsa qolmaydi.

**Files:**
- Modify: `phpunit.xml`
- Modify: `tests/TestCase.php`
- Create: `tests/Feature/Mahalla/ExecutiveDashboardTest.php`

**Interfaces:**
- Produces: `ExecutiveDashboardTest::districtId(): string` (Shovot, SOATO `1733230`); `::mahallaWithStreet(): array{0:string,1:string}`; `::makeHouse(string $mahallaId, string $streetId): string`; `::makeObservation(string $houseId, string $zone, Carbon $at, bool $isChange): void`

- [ ] **Step 1: `phpunit.xml` ni dev bazaga yo'naltirish**

`phpunit.xml` da quyidagi ikki qatorni toping:

```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

va ularni **butunlay o'chirib**, o'rniga izoh qo'ying:

```xml
        <!--
            DB_CONNECTION / DB_DATABASE ATAYLAB belgilanmagan — testlar .env dagi
            dev bazasida (kbt) yuradi.

            SQLite ishlatib bo'lmaydi: auth/master/mahalla ulanishlari
            config/database.php da qattiq `pgsql` drayveriga bog'langan va
            migratsiyalarda PostGIS turlari bor.

            XAVFSIZLIK: har test `DatabaseTransactions` bilan o'raladi va oxirida
            QAYTARILADI. `RefreshDatabase` ishlatish TAQIQLANGAN — tests/TestCase.php
            dagi darvoza uni to'xtatadi.
        -->
```

- [ ] **Step 2: `TestCase` ga xavfsizlik darvozasini qo'shish**

`tests/TestCase.php` ni to'liq almashtiring:

```php
<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * XAVFSIZLIK DARVOZASI.
     *
     * Testlar UMUMIY dev bazasida (kbt) yuradi — alohida test bazasi yaratish
     * uchun `kbt` foydalanuvchisida CREATEDB huquqi yo'q, ustiga mahalla.houses
     * da master schema'siga tashqi kalitlar bor (fikstura commit qilingan
     * bo'lishi shart).
     *
     * `RefreshDatabase` / `DatabaseMigrations` / `DatabaseTruncation` bazani
     * O'CHIRADI. Agar kimdir ularni qo'shsa, bu darvoza testni darhol
     * to'xtatadi — dev ma'lumoti yo'qolgandan KEYIN emas, undan OLDIN.
     *
     * Tekshiruv parent::setUp() dan OLDIN turishi shart: trait'lar aynan
     * parent::setUp() ichida ishga tushadi.
     */
    protected function setUp(): void
    {
        $forbidden = [RefreshDatabase::class, DatabaseMigrations::class, DatabaseTruncation::class];
        $used = class_uses_recursive(static::class);

        foreach ($forbidden as $trait) {
            if (in_array($trait, $used, true)) {
                $this->fail(
                    class_basename($trait)." ishlatib bo'lmaydi: testlar umumiy dev bazasida ".
                    "yuradi va bu trait butun bazani o'chiradi. `DatabaseTransactions` ishlating."
                );
            }
        }

        parent::setUp();
    }
}
```

- [ ] **Step 3: Darvozaning ishlashini tekshirish (vaqtinchalik test)**

Vaqtincha `tests/Unit/GuardCheckTest.php` yarating:

```php
<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuardCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_should_never_run(): void
    {
        $this->assertTrue(true);
    }
}
```

```powershell
Push-Location D:\kadr\platform
& C:\php84\php.exe artisan test --filter=GuardCheckTest
Pop-Location
```

Kutilgan natija: **FAIL** — «RefreshDatabase ishlatib bo'lmaydi…». Bu darvoza ishlayotganini isbotlaydi.

So'ng faylni o'chiring:

```powershell
Remove-Item D:\kadr\platform\tests\Unit\GuardCheckTest.php
```

- [ ] **Step 4: Asosiy test sinfini yozish**

`tests/Feature/Mahalla/ExecutiveDashboardTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Mahalla;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Rahbariyat dashboard'i testlari.
 *
 * Geo (tuman/mahalla/ko'cha/bino) — HAQIQIY kadastr ma'lumoti, chunki
 * mahalla.houses dan master schema'siga tashqi kalitlar bor va ular commit
 * qilingan qatorni talab qiladi. Test faqat operatsion qatorlarni yaratadi,
 * ular tranzaksiya bilan qaytariladi.
 */
class ExecutiveDashboardTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Har ulanish alohida tranzaksiyada — test oxirida hammasi qaytariladi.
     *
     * @var array<int, string>
     */
    protected array $connectionsToTransact = ['pgsql', 'auth', 'master', 'mahalla'];

    public function test_real_cadastre_data_is_available(): void
    {
        $count = DB::connection('master')->table('buildings')
            ->where('district_id', $this->districtId())
            ->where('type', 'residential')
            ->count();

        $this->assertGreaterThan(0, $count, 'Shovot kadastr binolari topilishi kerak');
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

    /**
     * Shovot ichidan ko'chasi bor bitta mahalla.
     *
     * @return array{0: string, 1: string} [mahalla_id, street_id]
     */
    protected function mahallaWithStreet(): array
    {
        $row = DB::connection('master')->table('streets as s')
            ->join('mahallas as m', 'm.id', '=', 's.mahalla_id')
            ->where('m.district_id', $this->districtId())
            ->select('m.id as mahalla_id', 's.id as street_id')
            ->first();

        if ($row === null) {
            $this->markTestSkipped('Ko\'chasi bor mahalla topilmadi.');
        }

        return [(string) $row->mahalla_id, (string) $row->street_id];
    }

    protected function makeHouse(string $mahallaId, string $streetId): string
    {
        $id = (string) Str::uuid();

        DB::connection('mahalla')->table('houses')->insert([
            'id' => $id,
            'district_id' => $this->districtId(),
            'mahalla_id' => $mahallaId,
            'street_id' => $streetId,
            'lat' => 41.5, 'lng' => 60.6, 'address' => 'ТЕСТ уй',
            'status' => 'not_started', 'progress_percent' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    protected function makeObservation(string $houseId, string $zone, Carbon $at, bool $isChange): void
    {
        DB::connection('mahalla')->table('zone_observations')->insert([
            'id' => (string) Str::uuid(),
            'house_id' => $houseId, 'zone' => $zone,
            'observed_at' => $at, 'is_on_site' => true, 'photo_count' => 1,
            'is_change' => $isChange,
            'decision' => $isChange ? 'auto_confirmed' : 'flagged',
            'status' => 'needs_work',
            'created_at' => now(), 'updated_at' => now(),
        ]);
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
```

- [ ] **Step 5: Testni yurgizish**

```powershell
Push-Location D:\kadr\platform
& C:\php84\php.exe artisan test --filter=ExecutiveDashboardTest
Pop-Location
```

Kutilgan natija: PASS — 1 passed.

- [ ] **Step 6: Bazada qoldiq yo'qligini tasdiqlash**

```powershell
Push-Location D:\kadr\platform
& C:\php84\php.exe artisan tinker --execute="echo 'houses: '.DB::connection('mahalla')->table('houses')->count().PHP_EOL; echo 'test userlar: '.DB::connection('auth')->table('users')->where('login','like','test_%')->count().PHP_EOL;"
Pop-Location
```

Kutilgan natija: `houses: 1` (testdan oldingi holat o'zgarmagan), `test userlar: 0` — tranzaksiya hammasini qaytargan.

- [ ] **Step 7: Commit**

```bash
cd /d/kadr/platform
git add phpunit.xml tests/TestCase.php tests/Feature/Mahalla/ExecutiveDashboardTest.php
git commit -m "test: tranzaksiya asosidagi test infratuzilmasi + RefreshDatabase darvozasi"
```

---

## Task 2: `viloyat` roli va ko'rish doirasi

**Files:**
- Modify: `app/Domains/Mahalla/Support/MahallaScope.php`
- Modify: `app/Domains/Mahalla/Support/MahallaAccess.php:27-30, 107-127`
- Modify: `app/Domains/Mahalla/Models/House.php:86-93`
- Create: `app/Domains/Mahalla/Http/Middleware/EnsureMahallaViewer.php`
- Modify: `bootstrap/app.php:32-37`
- Modify: `tests/Feature/Mahalla/ExecutiveDashboardTest.php`

**Interfaces:**
- Consumes: `$this->districtId()` (Task 1)
- Produces: `MahallaScope->canSeeAll: bool` (6-konstruktor parametri); `MahallaAccess::VIEWER_ROLES: array<int,string>`; middleware aliasi `mahalla.viewer`

- [ ] **Step 1: `new MahallaScope(...)` chaqiruvlarini topish**

Konstruktorga yangi parametr qo'shilgani uchun barcha chaqiruv joyini bilish shart:

```bash
cd /d/kadr/platform
grep -rn "new MahallaScope" app tests
```

Kutilgan natija: faqat `app/Domains/Mahalla/Support/MahallaAccess.php` da ikkita chaqiruv. Boshqa joyda topilsa — ularni ham 5-qadamda yangilang.

- [ ] **Step 2: Failing test yozish**

`ExecutiveDashboardTest.php` ga qo'shing (sinf ichiga):

```php
    public function test_viloyat_role_sees_all_houses_but_is_not_admin(): void
    {
        $user = $this->makeUser('viloyat');
        $access = app(\App\Domains\Mahalla\Support\MahallaAccess::class);

        $scope = $access->scopeFor($user);

        $this->assertTrue($scope->canSeeAll, 'viloyat barcha honadonlarni ko\'rishi kerak');
        $this->assertFalse($scope->isAdmin, 'viloyat ADMIN emas');
        $this->assertFalse($scope->restrictToStreets);
    }

    public function test_deputat_role_is_restricted_to_streets(): void
    {
        $user = $this->makeUser('deputat');
        $scope = app(\App\Domains\Mahalla\Support\MahallaAccess::class)->scopeFor($user);

        $this->assertFalse($scope->canSeeAll);
        $this->assertTrue($scope->restrictToStreets);
    }

    // `makeUser()` 1-vazifada bazaviy sinfda ta'riflangan — qayta yozilmaydi.
```

- [ ] **Step 3: Testni yurgizib, yiqilishini tasdiqlash**

```powershell
Push-Location D:\kadr\platform
& C:\php84\php.exe artisan test --filter=test_viloyat_role_sees_all_houses
Pop-Location
```

Kutilgan natija: FAIL — `Undefined property: ...MahallaScope::$canSeeAll`

- [ ] **Step 4: `MahallaScope` ga `canSeeAll` qo'shish**

`app/Domains/Mahalla/Support/MahallaScope.php` — konstruktorni almashtiring:

```php
    /**
     * @param  array<int, string>  $streetIds
     */
    public function __construct(
        public readonly bool $isAdmin,
        public readonly ?string $districtId,
        public readonly ?string $mahallaId,
        public readonly array $streetIds,
        public readonly bool $restrictToStreets,
        /*
         * KO'RISH doirasi — BOSHQARUV huquqidan ajratilgan. `viloyat` roli
         * barcha honadonlarni ko'radi (canSeeAll=true), lekin admin EMAS
         * (isAdmin=false): user yarata olmaydi, kuzatuv tasdiqlay olmaydi.
         * Ikkalasini bitta bayroq bilan ifodalash kelajakda xatoga olib keladi.
         */
        public readonly bool $canSeeAll = false,
    ) {
    }
```

- [ ] **Step 5: `MahallaAccess` ga `viloyat` rolini qo'shish**

`PERMISSIONS` konstantasini almashtiring:

```php
    private const PERMISSIONS = [
        'admin' => ['*'],
        // Raҳbariyat: FAQAT ko'rish. photos.upload va `*` ataylab yo'q.
        'viloyat' => ['dashboard.view', 'reports.view', 'houses.view', 'analyses.view'],
        'deputat' => ['houses.view', 'photos.view', 'photos.upload', 'analyses.view', 'dashboard.view'],
    ];

    /**
     * Rahbariyat dashboard'ini ko'ra oladigan rollar.
     *
     * @var array<int, string>
     */
    public const VIEWER_ROLES = ['admin', 'viloyat'];
```

`scopeFor()` metodini almashtiring:

```php
    public function scopeFor(User $user): MahallaScope
    {
        $role = $this->roleFor($user);

        if ($role === 'admin') {
            return new MahallaScope(true, null, null, [], false, true);
        }

        if ($role === 'viloyat') {
            return new MahallaScope(false, null, null, [], false, true);
        }

        $profile = MahallaProfile::find($user->id);
        $streetIds = $profile !== null
            ? $profile->streetAssignments()->pluck('street_id')->all()
            : [];

        return new MahallaScope(
            false,
            $profile?->district_id,
            $profile?->mahalla_id,
            $streetIds,
            true,
            false,
        );
    }
```

- [ ] **Step 6: `House::scopeVisibleTo` ni `canSeeAll` ga o'tkazish**

`app/Domains/Mahalla/Models/House.php` — `scopeVisibleTo` ichidagi shartni almashtiring:

```php
        if ($scope->canSeeAll) {
            return $query;
        }
```

(izohdagi `admin — hammasi` jumlasini `admin va viloyat — hammasi` ga tuzating)

- [ ] **Step 7: Testni yurgizib, o'tishini tasdiqlash**

```powershell
Push-Location D:\kadr\platform
& C:\php84\php.exe artisan test --filter=ExecutiveDashboardTest
Pop-Location
```

Kutilgan natija: PASS — 3 passed.

- [ ] **Step 8: `EnsureMahallaViewer` middleware'ini yozish**

`app/Domains/Mahalla/Http/Middleware/EnsureMahallaViewer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Middleware;

use App\Domains\Mahalla\Support\MahallaAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rahbariyat dashboard'i gvardiyasi: `admin` va `viloyat` rollariga ruxsat.
 * `mahalla.admin` dan farqi — bu FAQAT KO'RISH bo'limi uchun; boshqaruv
 * endpointlari o'z gvardiyasida qoladi, shuning uchun viloyat roli u yerga
 * kira olmaydi.
 */
class EnsureMahallaViewer
{
    public function __construct(private readonly MahallaAccess $access)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! in_array($this->access->roleFor($user), MahallaAccess::VIEWER_ROLES, true)) {
            abort(403, 'Бу бўлим фақат раҳбарият учун.');
        }

        return $next($request);
    }
}
```

- [ ] **Step 9: Aliasni ro'yxatdan o'tkazish**

`bootstrap/app.php` — `$middleware->alias([...])` ichiga `'mahalla.admin'` qatoridan keyin qo'shing:

```php
            'mahalla.viewer' => \App\Domains\Mahalla\Http\Middleware\EnsureMahallaViewer::class,
```

- [ ] **Step 10: Commit**

```bash
cd /d/kadr/platform
git add app/Domains/Mahalla bootstrap/app.php tests/Feature/Mahalla
git commit -m "feat(mahalla): viloyat roli va canSeeAll ko'rish doirasi"
```

---

## Task 3: `ExecutiveStats` servisi

**Files:**
- Create: `app/Domains/Mahalla/Services/ExecutiveStats.php`
- Create: `tests/Unit/Mahalla/ExecutiveStatsPeriodTest.php`
- Modify: `config/mahalla.php`
- Modify: `tests/Feature/Mahalla/ExecutiveDashboardTest.php`

**Interfaces:**
- Consumes: `ExecutiveDashboardTest::districtId()`, `::mahallaWithStreet()`, `::makeHouse()`, `::makeObservation()` (Task 1)
- Produces:
  - `ExecutiveStats::period(): array{timezone:string, today:string, week_start:string, today_start_utc:\Illuminate\Support\Carbon, week_start_utc:\Illuminate\Support\Carbon}`
  - `ExecutiveStats::district(string $districtId): array{rows:array, totals:array, unassigned_households:int}`
  - `ExecutiveStats::mahalla(string $mahallaId): array{households:int, rows:array}`

- [ ] **Step 1: Konfiguratsiya qo'shish**

`config/mahalla.php` — massivning eng oxiridagi `];` dan OLDIN qo'shing (ya'ni `'ai' => [...]` bloki yopilgandan keyin):

```php
    /*
     * Ko'rsatiladigan vaqt mintaqasi. Ilova UTC da ishlaydi; "bugun" va
     * "shu hafta" chegaralari MAHALLIY vaqt bo'yicha olinishi shart, aks holda
     * mahalliy 00:00-05:00 oralig'idagi o'zgarishlar kechagi kunga tushadi.
     */
    'timezone' => env('MAHALLA_TIMEZONE', 'Asia/Tashkent'),

    'executive' => [
        // Hozircha faqat Shovot ochiladi; kod barcha tumanlar uchun tayyor.
        'default_district_soato' => env('MAHALLA_EXECUTIVE_DISTRICT', '1733230'),
    ],
```

- [ ] **Step 2: Davr hisoblash uchun failing test**

`tests/Unit/Mahalla/ExecutiveStatsPeriodTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Mahalla;

use App\Domains\Mahalla\Services\ExecutiveStats;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ExecutiveStatsPeriodTest extends TestCase
{
    public function test_today_starts_at_tashkent_midnight_not_utc(): void
    {
        // Toshkent: 2026-07-20 00:30  ==  UTC: 2026-07-19 19:30
        Carbon::setTestNow(Carbon::parse('2026-07-19 19:30:00', 'UTC'));

        $period = app(ExecutiveStats::class)->period();

        $this->assertSame('2026-07-20', $period['today'], 'Toshkent sanasi olinishi kerak');
        $this->assertSame(
            '2026-07-19 19:00:00',
            $period['today_start_utc']->format('Y-m-d H:i:s'),
            'Toshkent yarim tuni = UTC 19:00',
        );

        Carbon::setTestNow();
    }

    public function test_week_starts_on_monday(): void
    {
        // 2026-07-23 — payshanba (Toshkent)
        Carbon::setTestNow(Carbon::parse('2026-07-23 10:00:00', 'Asia/Tashkent'));

        $period = app(ExecutiveStats::class)->period();

        $this->assertSame('2026-07-20', $period['week_start'], 'hafta dushanbadan boshlanadi');

        Carbon::setTestNow();
    }
}
```

- [ ] **Step 3: Testni yurgizib, yiqilishini tasdiqlash**

```powershell
Push-Location D:\kadr\platform
& C:\php84\php.exe artisan test --filter=ExecutiveStatsPeriodTest
Pop-Location
```

Kutilgan natija: FAIL — `Class "App\Domains\Mahalla\Services\ExecutiveStats" does not exist`

- [ ] **Step 4: `ExecutiveStats` servisini yozish**

`app/Domains/Mahalla/Services/ExecutiveStats.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Services;

use App\Domains\Mahalla\Support\MahallaZones;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rahbariyat dashboard'i uchun agregat hisoblash.
 *
 * MAXRAJ (jami xonadon) kadastrdan — `master.buildings`. Operatsion
 * `mahalla.houses` ishlatilmaydi: u kerak bo'lganda to'ladi (HouseProvisioner),
 * shuning uchun undan olingan son har doim haqiqatdan kam bo'ladi.
 *
 * SURAT (o'zgarganlar) `mahalla.zone_observations` dan, `COUNT(DISTINCT house_id)`
 * bilan — bitta xonadon hafta ichida ikki marta o'zgarsa ham 1 marta sanaladi,
 * aks holda son maxrajdan oshib ketishi mumkin.
 *
 * Schema'lararo JOIN ataylab qilinmaydi: har so'rov o'z ulanishida qoladi,
 * birlashtirish PHP'da (52 qator — farqsiz tez).
 */
final class ExecutiveStats
{
    /**
     * Davr chegaralari. Mahalliy (Toshkent) kun/hafta boshi UTC ga o'giriladi,
     * chunki `observed_at` bazada UTC da saqlanadi.
     *
     * @return array{timezone: string, today: string, week_start: string,
     *               today_start_utc: Carbon, week_start_utc: Carbon}
     */
    public function period(): array
    {
        $tz = (string) config('mahalla.timezone', 'Asia/Tashkent');
        $now = Carbon::now($tz);

        return [
            'timezone' => $tz,
            'today' => $now->toDateString(),
            'week_start' => $now->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
            'today_start_utc' => $now->copy()->startOfDay()->utc(),
            'week_start_utc' => $now->copy()->startOfWeek(Carbon::MONDAY)->startOfDay()->utc(),
        ];
    }

    /**
     * Tuman kesimi: har mahalla uchun jami xonadon + zona bo'yicha o'zgarishlar.
     *
     * @return array{rows: array<int, array<string, mixed>>,
     *               totals: array<string, mixed>,
     *               unassigned_households: int}
     */
    public function district(string $districtId): array
    {
        $period = $this->period();
        $households = $this->householdsByMahalla($districtId);
        $changes = $this->changesByMahallaZone($districtId, $period);

        $mahallas = DB::connection('master')->table('mahallas')
            ->where('district_id', $districtId)
            ->orderBy('sort_order')->orderBy('name_cyr')
            ->get(['id', 'name_cyr']);

        $rows = [];
        $totals = $this->emptyZoneCounters();
        $totalHouseholds = 0;

        foreach ($mahallas as $m) {
            $zones = $this->emptyZoneCounters();
            foreach (MahallaZones::zoneCodes() as $zone) {
                $zones[$zone] = $changes[$m->id][$zone] ?? ['week' => 0, 'today' => 0];
                $totals[$zone]['week'] += $zones[$zone]['week'];
                $totals[$zone]['today'] += $zones[$zone]['today'];
            }

            $count = $households[$m->id] ?? 0;
            $totalHouseholds += $count;

            $rows[] = [
                'mahalla' => ['id' => $m->id, 'name' => $m->name_cyr],
                'households' => $count,
                'zones' => $zones,
            ];
        }

        return [
            'rows' => $rows,
            'totals' => ['households' => $totalHouseholds, 'zones' => $totals],
            'unassigned_households' => $this->unassignedHouseholds($districtId),
        ];
    }

    /**
     * Mahalla kesimi: zonalar bo'yicha (qo'lyozma jadval shakli).
     *
     * @return array{households: int, rows: array<int, array<string, mixed>>}
     */
    public function mahalla(string $mahallaId): array
    {
        $period = $this->period();

        $households = (int) DB::connection('master')->table('buildings')
            ->where('mahalla_id', $mahallaId)
            ->where('type', 'residential')
            ->count();

        $changes = $this->changesByZone($mahallaId, $period);

        $rows = [];
        foreach (MahallaZones::zoneCodes() as $zone) {
            $c = $changes[$zone] ?? ['week' => 0, 'today' => 0];
            $rows[] = [
                'zone' => $zone,
                'label' => MahallaZones::zoneLabel($zone),
                'households' => $households,
                'week' => $c['week'],
                'today' => $c['today'],
            ];
        }

        return ['households' => $households, 'rows' => $rows];
    }

    /**
     * Mahalla bo'yicha turar-joy binolari soni (maxraj).
     *
     * @return array<string, int>
     */
    private function householdsByMahalla(string $districtId): array
    {
        $rows = DB::connection('master')->table('buildings')
            ->where('district_id', $districtId)
            ->where('type', 'residential')
            ->whereNotNull('mahalla_id')
            ->groupBy('mahalla_id')
            ->select('mahalla_id', DB::raw('count(*) as households'))
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->mahalla_id] = (int) $r->households;
        }

        return $out;
    }

    /** Mahallaga biriktirilmagan turar-joy binolari (jadval ostidagi izoh uchun). */
    private function unassignedHouseholds(string $districtId): int
    {
        return DB::connection('master')->table('buildings')
            ->where('district_id', $districtId)
            ->where('type', 'residential')
            ->whereNull('mahalla_id')
            ->count();
    }

    /**
     * @param  array{today_start_utc: Carbon, week_start_utc: Carbon}  $period
     * @return array<string, array<string, array{week: int, today: int}>>
     */
    private function changesByMahallaZone(string $districtId, array $period): array
    {
        // DIQQAT: `select()` EMAS, `addSelect()` — `select()` changeQuery() dagi
        // hisoblangan ustunlarni (week_count/today_count) o'chirib yuborardi.
        $rows = $this->changeQuery($period)
            ->where('h.district_id', $districtId)
            ->groupBy('h.mahalla_id', 'o.zone')
            ->addSelect('h.mahalla_id', 'o.zone')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->mahalla_id][$r->zone] = [
                'week' => (int) $r->week_count,
                'today' => (int) $r->today_count,
            ];
        }

        return $out;
    }

    /**
     * @param  array{today_start_utc: Carbon, week_start_utc: Carbon}  $period
     * @return array<string, array{week: int, today: int}>
     */
    private function changesByZone(string $mahallaId, array $period): array
    {
        // `addSelect()` — yuqoridagi izohga qarang.
        $rows = $this->changeQuery($period)
            ->where('h.mahalla_id', $mahallaId)
            ->groupBy('o.zone')
            ->addSelect('o.zone')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->zone] = ['week' => (int) $r->week_count, 'today' => (int) $r->today_count];
        }

        return $out;
    }

    /**
     * Umumiy o'zgarish so'rovi. `FILTER (WHERE ...)` emas, `CASE` — standart SQL.
     *
     * @param  array{today_start_utc: Carbon, week_start_utc: Carbon}  $period
     */
    private function changeQuery(array $period): \Illuminate\Database\Query\Builder
    {
        return DB::connection('mahalla')->table('zone_observations as o')
            ->join('houses as h', 'h.id', '=', 'o.house_id')
            ->where('o.is_change', true)
            ->where('o.observed_at', '>=', $period['week_start_utc'])
            ->selectRaw('count(distinct o.house_id) as week_count')
            ->selectRaw(
                'count(distinct case when o.observed_at >= ? then o.house_id end) as today_count',
                [$period['today_start_utc']],
            );
    }

    /**
     * @return array<string, array{week: int, today: int}>
     */
    private function emptyZoneCounters(): array
    {
        $out = [];
        foreach (MahallaZones::zoneCodes() as $zone) {
            $out[$zone] = ['week' => 0, 'today' => 0];
        }

        return $out;
    }
}
```

- [ ] **Step 5: Davr testini yurgizib, o'tishini tasdiqlash**

```powershell
Push-Location D:\kadr\platform
& C:\php84\php.exe artisan test --filter=ExecutiveStatsPeriodTest
Pop-Location
```

Kutilgan natija: PASS — 2 passed.

- [ ] **Step 6: Sanoq mantig'i uchun testlar**

Testlar HAQIQIY kadastr geo'sidan foydalanadi, shuning uchun kutilgan qiymatlar
qattiq yozilmaydi — ular bazadan hisoblanadi yoki BOSHLANG'ICH holatga nisbatan
o'lchanadi (delta). Bu testlarni kadastr qayta yuklansa ham buzilmaydigan qiladi.

`tests/Feature/Mahalla/ExecutiveDashboardTest.php` sinfiga qo'shing:

```php
    public function test_household_denominator_comes_from_cadastre_not_houses_table(): void
    {
        $districtId = $this->districtId();
        $stats = app(\App\Domains\Mahalla\Services\ExecutiveStats::class)->district($districtId);

        // Kutilgan qiymat bazadan hisoblanadi — kadastr yangilansa test buzilmaydi.
        $expected = DB::connection('master')->table('buildings')
            ->where('district_id', $districtId)
            ->where('type', 'residential')
            ->whereNotNull('mahalla_id')
            ->count();

        $this->assertSame($expected, $stats['totals']['households'],
            'JAMI = mahallaga biriktirilgan turar-joy binolari yig\'indisi');

        $unassigned = DB::connection('master')->table('buildings')
            ->where('district_id', $districtId)
            ->where('type', 'residential')
            ->whereNull('mahalla_id')
            ->count();

        $this->assertSame($unassigned, $stats['unassigned_households']);

        // `houses` operatsion jadvali deyarli bo'sh — maxraj undan OLINMAYDI.
        $operational = DB::connection('mahalla')->table('houses')
            ->where('district_id', $districtId)->count();
        $this->assertGreaterThan($operational, $stats['totals']['households']);
    }

    public function test_same_house_changing_twice_counts_once(): void
    {
        [$mahallaId, $streetId] = $this->mahallaWithStreet();
        $stats = app(\App\Domains\Mahalla\Services\ExecutiveStats::class);

        $before = collect($stats->mahalla($mahallaId)['rows'])->firstWhere('zone', 'yard');

        $house = $this->makeHouse($mahallaId, $streetId);
        $this->makeObservation($house, 'yard', now()->subHours(3), isChange: true);
        $this->makeObservation($house, 'yard', now()->subHours(1), isChange: true);

        $after = collect($stats->mahalla($mahallaId)['rows'])->firstWhere('zone', 'yard');

        $this->assertSame(1, $after['week'] - $before['week'],
            'DISTINCT house_id — bitta uydagi ikki kuzatuv 1 marta sanaladi');
        $this->assertSame(1, $after['today'] - $before['today']);
    }

    public function test_unconfirmed_change_is_not_counted(): void
    {
        [$mahallaId, $streetId] = $this->mahallaWithStreet();
        $stats = app(\App\Domains\Mahalla\Services\ExecutiveStats::class);

        $before = collect($stats->mahalla($mahallaId)['rows'])->firstWhere('zone', 'facade');

        $house = $this->makeHouse($mahallaId, $streetId);
        $this->makeObservation($house, 'facade', now()->subHour(), isChange: false);

        $after = collect($stats->mahalla($mahallaId)['rows'])->firstWhere('zone', 'facade');

        $this->assertSame(0, $after['week'] - $before['week'],
            'tasdiqlanmagan (is_change=false) kuzatuv sanalmaydi');
    }

    public function test_change_outside_current_week_is_not_counted(): void
    {
        [$mahallaId, $streetId] = $this->mahallaWithStreet();
        $stats = app(\App\Domains\Mahalla\Services\ExecutiveStats::class);

        $before = collect($stats->mahalla($mahallaId)['rows'])->firstWhere('zone', 'toilet');

        $house = $this->makeHouse($mahallaId, $streetId);
        // Joriy hafta boshidan 1 soat OLDIN — hisobga kirmasligi kerak
        $weekStart = app(\App\Domains\Mahalla\Services\ExecutiveStats::class)
            ->period()['week_start_utc'];
        $this->makeObservation($house, 'toilet', $weekStart->copy()->subHour(), isChange: true);

        $after = collect($stats->mahalla($mahallaId)['rows'])->firstWhere('zone', 'toilet');

        $this->assertSame(0, $after['week'] - $before['week'],
            'o\'tgan haftadagi o\'zgarish joriy hafta hisobiga kirmaydi');
    }

    public function test_every_mahalla_of_district_appears_in_rows(): void
    {
        $districtId = $this->districtId();
        $stats = app(\App\Domains\Mahalla\Services\ExecutiveStats::class)->district($districtId);

        $expected = DB::connection('master')->table('mahallas')
            ->where('district_id', $districtId)->count();

        $this->assertCount($expected, $stats['rows'],
            'kuzatuvi yo\'q mahalla ham jadvalda nol bilan ko\'rinishi kerak');

        foreach ($stats['rows'] as $row) {
            $this->assertArrayHasKey('yard', $row['zones']);
            $this->assertIsInt($row['zones']['yard']['week']);
        }
    }
```

- [ ] **Step 7: Testlarni yurgizish**

```powershell
Push-Location D:\kadr\platform
& C:\php84\php.exe artisan test --filter=ExecutiveDashboardTest
Pop-Location
```

Kutilgan natija: PASS — 9 passed.

- [ ] **Step 8: Commit**

```bash
cd /d/kadr/platform
git add app/Domains/Mahalla/Services/ExecutiveStats.php config/mahalla.php tests
git commit -m "feat(mahalla): ExecutiveStats agregat servisi (davr + DISTINCT sanoq)"
```

---

## Task 4: API endpointlari

**Files:**
- Create: `app/Domains/Mahalla/Http/Controllers/Api/Executive/DistrictDashboardController.php`
- Create: `app/Domains/Mahalla/Http/Controllers/Api/Executive/MahallaDashboardController.php`
- Modify: `routes/api/mahalla.php`
- Modify: `tests/Feature/Mahalla/ExecutiveDashboardTest.php`

**Interfaces:**
- Consumes: `ExecutiveStats::district()`, `::mahalla()`, `::period()` (Task 3); `mahalla.viewer` aliasi (Task 2)
- Produces: `GET /api/mahalla/executive/districts/{district?}`, `GET /api/mahalla/executive/mahallas/{mahalla}`

- [ ] **Step 1: Ruxsat uchun failing test**

`ExecutiveDashboardTest.php` ga qo'shing:

```php
    public function test_viloyat_can_open_executive_endpoint(): void
    {
        $user = $this->makeUser('viloyat');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/districts/'.$this->districtId())
            ->assertOk()
            ->assertJsonStructure([
                'district' => ['id', 'name', 'soato'],
                'period' => ['today', 'week_start', 'timezone'],
                'zones', 'rows', 'totals', 'unassigned_households',
            ]);
    }

    public function test_viloyat_cannot_touch_admin_endpoints(): void
    {
        $user = $this->makeUser('viloyat');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/mahalla/admin/users', ['name' => 'X'])
            ->assertForbidden();
    }

    public function test_deputat_cannot_open_executive_endpoint(): void
    {
        $user = $this->makeUser('deputat');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/districts/'.$this->districtId())
            ->assertForbidden();
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/mahalla/executive/districts/'.$this->districtId())
            ->assertUnauthorized();
    }
```

- [ ] **Step 2: Testni yurgizib, yiqilishini tasdiqlash**

```powershell
Push-Location D:\kadr\platform
& C:\php84\php.exe artisan test --filter=test_viloyat_can_open_executive_endpoint
Pop-Location
```

Kutilgan natija: FAIL — 404 (marshrut hali yo'q).

- [ ] **Step 3: Tuman kontrollerini yozish**

`app/Domains/Mahalla/Http/Controllers/Api/Executive/DistrictDashboardController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Controllers\Api\Executive;

use App\Domains\Mahalla\Models\Master\District;
use App\Domains\Mahalla\Services\ExecutiveStats;
use App\Domains\Mahalla\Support\MahallaZones;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Rahbariyat: tuman kesimi (mahallalar jadvali).
 *
 * `{district}` ixtiyoriy — berilmasa sozlamadagi standart tuman (Shovot).
 * Shu tufayli frontend `/executive` ni parametrsiz ocha oladi va tuman kodi
 * faqat konfiguratsiyada turadi.
 */
class DistrictDashboardController extends Controller
{
    public function __construct(private readonly ExecutiveStats $stats)
    {
    }

    public function __invoke(?string $district = null): JsonResponse
    {
        $model = $district !== null
            ? District::on('master')->findOrFail($district)
            : District::on('master')
                ->where('soato_code', (string) config('mahalla.executive.default_district_soato'))
                ->firstOrFail();

        $data = $this->stats->district((string) $model->id);
        $period = $this->stats->period();

        return response()->json([
            'district' => [
                'id' => $model->id,
                'name' => $model->name_cyr,
                'soato' => $model->soato_code,
            ],
            'period' => [
                'today' => $period['today'],
                'week_start' => $period['week_start'],
                'timezone' => $period['timezone'],
            ],
            'zones' => MahallaZones::zoneOptions(),
            'rows' => $data['rows'],
            'totals' => $data['totals'],
            'unassigned_households' => $data['unassigned_households'],
        ]);
    }
}
```

- [ ] **Step 4: Mahalla kontrollerini yozish**

`app/Domains/Mahalla/Http/Controllers/Api/Executive/MahallaDashboardController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Controllers\Api\Executive;

use App\Domains\Mahalla\Models\Master\Mahalla;
use App\Domains\Mahalla\Services\ExecutiveStats;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Rahbariyat: mahalla kesimi (zonalar jadvali — qo'lyozma shakli).
 */
class MahallaDashboardController extends Controller
{
    public function __construct(private readonly ExecutiveStats $stats)
    {
    }

    public function __invoke(string $mahalla): JsonResponse
    {
        $model = Mahalla::on('master')->with('district')->findOrFail($mahalla);

        $data = $this->stats->mahalla((string) $model->id);
        $period = $this->stats->period();

        return response()->json([
            'mahalla' => [
                'id' => $model->id,
                'name' => $model->name_cyr,
                'district' => [
                    'id' => $model->district?->id,
                    'name' => $model->district?->name_cyr,
                ],
            ],
            'period' => [
                'today' => $period['today'],
                'week_start' => $period['week_start'],
                'timezone' => $period['timezone'],
            ],
            'households' => $data['households'],
            'rows' => $data['rows'],
        ]);
    }
}
```

- [ ] **Step 5: Marshrutlarni qo'shish**

`routes/api/mahalla.php` — `use` blokiga qo'shing:

```php
use App\Domains\Mahalla\Http\Controllers\Api\Executive\DistrictDashboardController;
use App\Domains\Mahalla\Http\Controllers\Api\Executive\MahallaDashboardController;
```

va `Route::get('/photos/{photo}', ...)` qatoridan KEYIN, `admin` guruhidan OLDIN qo'shing:

```php
        /*
         * RAHBARIYAT (viloyat hokimi o'rinbosari) — FAQAT KO'RISH.
         * `mahalla.viewer`: admin + viloyat. Boshqaruv endpointlari
         * o'z gvardiyasida (`mahalla.admin`) qoladi.
         */
        Route::prefix('executive')
            ->name('executive.')
            ->middleware('mahalla.viewer')
            ->group(function () {
                Route::get('/districts/{district?}', DistrictDashboardController::class)->name('district');
                Route::get('/mahallas/{mahalla}', MahallaDashboardController::class)->name('mahalla');
            });
```

- [ ] **Step 6: Testlarni yurgizib, o'tishini tasdiqlash**

```powershell
Push-Location D:\kadr\platform
& C:\php84\php.exe artisan test --filter=ExecutiveDashboardTest
Pop-Location
```

Kutilgan natija: PASS — 13 passed.

- [ ] **Step 7: Haqiqiy Shovot ma'lumotida qo'lda tekshirish**

```powershell
Push-Location D:\kadr\platform
& C:\php84\php.exe artisan tinker --execute="`$s = app(App\Domains\Mahalla\Services\ExecutiveStats::class); `$d = DB::connection('master')->table('districts')->where('soato_code','1733230')->value('id'); `$r = `$s->district(`$d); echo 'mahallalar: '.count(`$r['rows']).PHP_EOL; echo 'jami xonadon: '.`$r['totals']['households'].PHP_EOL; echo 'biriktirilmagan: '.`$r['unassigned_households'].PHP_EOL;"
Pop-Location
```

Kutilgan natija:
```
mahallalar: 52
jami xonadon: 34648
biriktirilmagan: 3
```

- [ ] **Step 8: Commit**

```bash
cd /d/kadr/platform
git add app/Domains/Mahalla/Http/Controllers/Api/Executive routes/api/mahalla.php tests
git commit -m "feat(mahalla): rahbariyat API endpointlari (tuman va mahalla kesimi)"
```

---

## Task 5: Frontend — tiplar va store

**Files:**
- Modify: `D:\kadr\mahalla\src\types\index.ts`
- Create: `D:\kadr\mahalla\src\stores\executive.ts`
- Modify: `D:\kadr\mahalla\src\stores\auth.ts:42-45`

**Interfaces:**
- Consumes: Task 4 API javob shakli
- Produces: `useExecutiveStore()` — `district`, `mahalla`, `fetchDistrict(id?)`, `fetchMahalla(id)`; `useAuthStore().isViewer`

- [ ] **Step 1: Tiplarni qo'shish**

`src/types/index.ts` oxiriga qo'shing:

```ts
// --- Rahbariyat dashboard'i ---

export interface ExecutivePeriod {
    today: string;
    week_start: string;
    timezone: string;
}

export interface ZoneCounter {
    week: number;
    today: number;
}

export interface ExecutiveDistrictRow {
    mahalla: { id: string; name: string };
    households: number;
    zones: Record<string, ZoneCounter>;
}

export interface ExecutiveDistrictResponse {
    district: { id: string; name: string; soato: string };
    period: ExecutivePeriod;
    zones: Array<{ code: string; name: string }>;
    rows: ExecutiveDistrictRow[];
    totals: { households: number; zones: Record<string, ZoneCounter> };
    unassigned_households: number;
}

export interface ExecutiveMahallaRow {
    zone: string;
    label: string;
    households: number;
    week: number;
    today: number;
}

export interface ExecutiveMahallaResponse {
    mahalla: { id: string; name: string; district: { id: string; name: string } };
    period: ExecutivePeriod;
    households: number;
    rows: ExecutiveMahallaRow[];
}
```

- [ ] **Step 2: Store yozish**

`src/stores/executive.ts`:

```ts
import { defineStore } from 'pinia';
import { api } from '@/lib/api';
import type { ExecutiveDistrictResponse, ExecutiveMahallaResponse } from '@/types';

interface ExecutiveState {
    district: ExecutiveDistrictResponse | null;
    mahalla: ExecutiveMahallaResponse | null;
    loading: boolean;
}

export const useExecutiveStore = defineStore('executive', {
    state: (): ExecutiveState => ({
        district: null,
        mahalla: null,
        loading: false,
    }),

    actions: {
        /** `id` berilmasa backend standart tumanni (Shovot) qaytaradi. */
        async fetchDistrict(id?: string): Promise<void> {
            this.loading = true;
            try {
                const url = id
                    ? `/api/mahalla/executive/districts/${id}`
                    : '/api/mahalla/executive/districts';
                const { data } = await api.get<ExecutiveDistrictResponse>(url);
                this.district = data;
            } finally {
                this.loading = false;
            }
        },

        async fetchMahalla(id: string): Promise<void> {
            this.loading = true;
            try {
                const { data } = await api.get<ExecutiveMahallaResponse>(
                    `/api/mahalla/executive/mahallas/${id}`,
                );
                this.mahalla = data;
            } finally {
                this.loading = false;
            }
        },
    },
});
```

- [ ] **Step 3: `auth` store'ga `isViewer` qo'shish**

`src/stores/auth.ts` — `isAdmin` getteridan keyin qo'shing:

```ts
        /** Rahbariyat roli — faqat ko'rish (viloyat hokimi o'rinbosari). */
        isViewer: (state): boolean => state.context?.role === 'viloyat',
```

- [ ] **Step 4: Build tekshiruvi**

Router hali o'zgartirilmagan (u 7-vazifada, sahifalar yaratilgandan keyin) — shuning uchun bu bosqichda build toza o'tishi shart:

```powershell
Push-Location D:\kadr\mahalla
npm run build
Pop-Location
```

Kutilgan natija: build muvaffaqiyatli, TypeScript xatosiz. Yangi tiplar va store hech qayerda ishlatilmagani uchun ular build'ga ta'sir qilmaydi, lekin sintaksis xatolari shu yerda ushlanadi.

> **Eslatma:** `D:\kadr\mahalla` git repo EMAS. Frontend o'zgarishlari commit qilinmaydi — ular diskda saqlanadi. Faqat `platform` repo'siga commit qilinadi.

---

## Task 6: Frontend — ikkala jadval sahifasi

Sahifalar router'dan OLDIN yaratiladi: router ular mavjud bo'lmasa `import` xatosi bilan build'ni yiqitadi.

**Files:**
- Create: `D:\kadr\mahalla\src\pages\executive\ExecutiveDistrict.vue`
- Create: `D:\kadr\mahalla\src\pages\executive\ExecutiveMahalla.vue`

**Interfaces:**
- Consumes: `useExecutiveStore().fetchDistrict()`, `.fetchMahalla(id)`, `ExecutiveDistrictResponse`, `ExecutiveMahallaResponse` (Task 5)
- Produces: `executive-district` va `executive-mahalla` nomli marshrutlarni kutadi (Task 7 da ulanadi)

- [ ] **Step 1: Sahifani yozish**

`src/pages/executive/ExecutiveDistrict.vue`:

```vue
<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useExecutiveStore } from '@/stores/executive';

const store = useExecutiveStore();
const router = useRouter();

const data = computed(() => store.district);
const zones = computed(() => data.value?.zones ?? []);

onMounted(() => store.fetchDistrict());

function openMahalla(id: string): void {
    router.push({ name: 'executive-mahalla', params: { id } });
}
</script>

<template>
    <div class="mx-auto max-w-7xl px-4 py-6">
        <header class="mb-5">
            <h1 class="text-lg font-semibold text-slate-800">
                {{ data?.district.name ?? 'Юкланмоқда…' }}
            </h1>
            <p v-if="data" class="mt-1 text-sm text-slate-500">
                Ҳафта боши: {{ data.period.week_start }} · Бугун: {{ data.period.today }}
            </p>
        </header>

        <div v-if="store.loading" class="rounded-xl border border-slate-200 bg-white px-4 py-8 text-center text-sm text-slate-500">
            Юкланмоқда…
        </div>

        <div v-else-if="data" class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="w-full text-left text-sm tabular-nums">
                <thead>
                    <tr class="bg-slate-50 text-xs uppercase text-slate-500">
                        <th rowspan="2" class="px-3 py-2">№</th>
                        <th rowspan="2" class="px-3 py-2">Маҳалла</th>
                        <th rowspan="2" class="px-3 py-2 text-right">Жами<br />хонадон</th>
                        <th v-for="z in zones" :key="z.code" colspan="2" class="border-l border-slate-200 px-3 py-2 text-center">
                            {{ z.name }}
                        </th>
                    </tr>
                    <tr class="bg-slate-50 text-[11px] uppercase text-slate-400">
                        <template v-for="z in zones" :key="z.code">
                            <th class="border-l border-slate-200 px-3 py-1 text-right font-medium">Ҳафта</th>
                            <th class="px-3 py-1 text-right font-medium">Бугун</th>
                        </template>
                    </tr>
                </thead>

                <tbody class="divide-y divide-slate-100">
                    <tr
                        v-for="(row, i) in data.rows"
                        :key="row.mahalla.id"
                        class="cursor-pointer hover:bg-slate-50"
                        @click="openMahalla(row.mahalla.id)"
                    >
                        <td class="px-3 py-2 text-slate-400">{{ i + 1 }}</td>
                        <td class="px-3 py-2 font-medium text-slate-700">{{ row.mahalla.name }}</td>
                        <td class="px-3 py-2 text-right text-slate-600">{{ row.households }}</td>
                        <template v-for="z in zones" :key="z.code">
                            <td class="border-l border-slate-200 px-3 py-2 text-right"
                                :class="row.zones[z.code].week > 0 ? 'font-semibold text-green-700' : 'text-slate-400'">
                                {{ row.zones[z.code].week }}
                            </td>
                            <td class="px-3 py-2 text-right"
                                :class="row.zones[z.code].today > 0 ? 'font-semibold text-green-700' : 'text-slate-400'">
                                {{ row.zones[z.code].today }}
                            </td>
                        </template>
                    </tr>
                </tbody>

                <tfoot>
                    <tr class="border-t-2 border-slate-300 bg-slate-50 font-semibold text-slate-700">
                        <td class="px-3 py-2"></td>
                        <td class="px-3 py-2">ЖАМИ ({{ data.rows.length }})</td>
                        <td class="px-3 py-2 text-right">{{ data.totals.households }}</td>
                        <template v-for="z in zones" :key="z.code">
                            <td class="border-l border-slate-200 px-3 py-2 text-right">{{ data.totals.zones[z.code].week }}</td>
                            <td class="px-3 py-2 text-right">{{ data.totals.zones[z.code].today }}</td>
                        </template>
                    </tr>
                </tfoot>
            </table>
        </div>

        <p v-if="data && data.unassigned_households > 0" class="mt-2 text-xs text-slate-500">
            {{ data.unassigned_households }} та хонадон ҳеч бир маҳаллага бириктирилмаган —
            жадвалдаги ЖАМИ кўрсаткичига кирмайди.
        </p>
    </div>
</template>
```

- [ ] **Step 2: Mahalla sahifasini yozish**

`src/pages/executive/ExecutiveMahalla.vue`:

```vue
<script setup lang="ts">
import { computed, onMounted, watch } from 'vue';
import { useExecutiveStore } from '@/stores/executive';

const props = defineProps<{ id: string }>();
const store = useExecutiveStore();
const data = computed(() => store.mahalla);

onMounted(() => store.fetchMahalla(props.id));
watch(() => props.id, (id) => store.fetchMahalla(id));
</script>

<template>
    <div class="mx-auto max-w-3xl px-4 py-6">
        <router-link :to="{ name: 'executive-district' }" class="text-sm text-slate-500 hover:text-slate-700">
            ← {{ data?.mahalla.district.name ?? 'Орқага' }}
        </router-link>

        <header class="mb-5 mt-2">
            <h1 class="text-lg font-semibold text-slate-800">
                {{ data?.mahalla.name ?? 'Юкланмоқда…' }}
            </h1>
            <p v-if="data" class="mt-1 text-sm text-slate-500">
                Жами хонадон: <span class="font-medium text-slate-700">{{ data.households }}</span>
                · Ҳафта боши: {{ data.period.week_start }} · Бугун: {{ data.period.today }}
            </p>
        </header>

        <div v-if="store.loading" class="rounded-xl border border-slate-200 bg-white px-4 py-8 text-center text-sm text-slate-500">
            Юкланмоқда…
        </div>

        <div v-else-if="data" class="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <table class="w-full text-left text-sm tabular-nums">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-4 py-2">№</th>
                        <th class="px-4 py-2">Қисм</th>
                        <th class="px-4 py-2 text-right">Сони</th>
                        <th class="px-4 py-2 text-right">Шу ҳафтада<br />ўзгарган</th>
                        <th class="px-4 py-2 text-right">Бугун<br />ўзгарган</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="(row, i) in data.rows" :key="row.zone">
                        <td class="px-4 py-3 text-slate-400">{{ i + 1 }}</td>
                        <td class="px-4 py-3 font-medium text-slate-700">{{ row.label }}</td>
                        <td class="px-4 py-3 text-right text-slate-600">{{ row.households }}</td>
                        <td class="px-4 py-3 text-right"
                            :class="row.week > 0 ? 'font-semibold text-green-700' : 'text-slate-400'">
                            {{ row.week }}
                        </td>
                        <td class="px-4 py-3 text-right"
                            :class="row.today > 0 ? 'font-semibold text-green-700' : 'text-slate-400'">
                            {{ row.today }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
```

- [ ] **Step 3: Sintaksis tekshiruvi**

Sahifalar hali hech qayerdan import qilinmagan, shuning uchun build ularni tekshirmaydi. Vue kompilyatorini to'g'ridan-to'g'ri yurgizamiz:

```powershell
Push-Location D:\kadr\mahalla
npx vue-tsc --noEmit
Pop-Location
```

Kutilgan natija: xatosiz tugaydi.

---

## Task 7: Router ulash va yakuniy tekshiruv

**Files:**
- Modify: `D:\kadr\mahalla\src\router\index.ts`

**Interfaces:**
- Consumes: `ExecutiveDistrict.vue`, `ExecutiveMahalla.vue` (Task 6); `useAuthStore().isViewer` (Task 5)

- [ ] **Step 1: Marshrutlarni qo'shish**

`src/router/index.ts` — `admin` marshrutlaridan keyin, `pathMatch` dan OLDIN qo'shing:

```ts
    // --- Rahbariyat (viloyat) — faqat ko'rish ---
    {
        path: '/executive',
        name: 'executive-district',
        component: () => import('@/pages/executive/ExecutiveDistrict.vue'),
        meta: { requiresAuth: true, viewerOnly: true, title: 'Туман кесими' },
    },
    {
        path: '/executive/mahallas/:id',
        name: 'executive-mahalla',
        component: () => import('@/pages/executive/ExecutiveMahalla.vue'),
        meta: { requiresAuth: true, viewerOnly: true, title: 'Маҳалла кесими' },
        props: true,
    },
```

- [ ] **Step 2: `homeFor` ni uch rolga moslash**

`src/router/index.ts` dagi `homeFor` funksiyasini almashtiring:

```ts
/** Rolga mos boshlang'ich sahifa: viloyat → rahbariyat, admin → boshqaruv, deputat → operatsion. */
function homeFor(auth: { isAdmin: boolean; isViewer: boolean }): { name: string } {
    if (auth.isViewer) return { name: 'executive-district' };

    return { name: auth.isAdmin ? 'admin-overview' : 'dashboard' };
}
```

`router.beforeEach` ichidagi barcha `homeFor(...)` chaqiruvlarini `homeFor(auth)` ga o'zgartiring.

- [ ] **Step 3: Gvardiyani yangilash**

`router.beforeEach` ichida, `adminOnly` tekshiruvi yoniga qo'shing:

```ts
    // Rahbariyat bo'limi: viloyat va admin kira oladi.
    if (to.meta.viewerOnly && !auth.isViewer && !auth.isAdmin) {
        return homeFor(auth);
    }

    // Viloyat roli operatsion sahifalarga tushib qolmasin (u yerda ko'cha-scope kerak).
    if (to.meta.operationalOnly && auth.isViewer) {
        return homeFor(auth);
    }
```

- [ ] **Step 4: Build**

```powershell
Push-Location D:\kadr\mahalla
npm run build
Pop-Location
```

Kutilgan natija: build muvaffaqiyatli, TypeScript xatosiz.

- [ ] **Step 5: `viloyat` rolli test foydalanuvchisini yaratish**

```powershell
Push-Location D:\kadr\platform
& C:\php84\php.exe artisan tinker --execute="
`$id = (string) Illuminate\Support\Str::uuid();
DB::connection('auth')->table('users')->insert(['id'=>`$id,'login'=>'viloyat','password'=>bcrypt(env('MAHALLA_VIEWER_SEED_PASSWORD')),'name'=>'Вилоят ҳокими ўринбосари','is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
`$sid = DB::connection('auth')->table('systems')->where('code','mahalla')->value('id');
DB::connection('auth')->table('user_system_access')->insert(['id'=>(string) Illuminate\Support\Str::uuid(),'user_id'=>`$id,'system_id'=>`$sid,'role'=>'viloyat','is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
echo 'yaratildi: viloyat (parol alohida)'.PHP_EOL;
"
Pop-Location
```

Kutilgan natija: `yaratildi: viloyat (parol alohida)`

> Parol faqat lokal dev uchun. Prod'da boshqa parol beriladi va u gitignore qilingan faylda saqlanadi.

- [ ] **Step 6: Brauzerda uchdan-uchgacha tekshirish**

Ikki terminalda:

```powershell
Push-Location D:\kadr\platform; & C:\php84\php.exe artisan serve --port=8020; Pop-Location
```

```powershell
Push-Location D:\kadr\mahalla; npm run dev; Pop-Location
```

`viloyat` / (parol alohida beriladi) bilan kiring.

Kutilgan natija:
1. Kirgandan keyin avtomatik `/executive` ga tushadi (dashboard'ga emas).
2. Jadvalda **52 qator**, ЖАМИ = **34 648**, ostida «3 та хонадон… бириктирилмаган» izohi.
3. Barcha o'zgarish ustunlari `0` — hali kuzatuv yo'q, bu to'g'ri holat.
4. Istalgan mahalla qatoriga bosilsa — 4 qatorli zona jadvali ochiladi.
5. Qo'lda `/admin/users` ga o'tishga urinilsa — qaytarib yuboradi.

- [ ] **Step 7: To'liq test to'plami**

```powershell
Push-Location D:\kadr\platform
& C:\php84\php.exe artisan test
Pop-Location
```

Kutilgan natija: barcha testlar PASS (13 yangi + 2 mavjud namunaviy).

- [ ] **Step 8: Commit**

```bash
cd /d/kadr/platform
git add docs
git commit -m "docs: rahbariyat dashboard'i implementatsiya rejasi"
```

---

## Bajarilgandan keyin

- Prod'ga chiqarish **qilinmaydi** — foydalanuvchi topshirig'ini kutadi.
- Prod'da `viloyat` rolli foydalanuvchi yaratish kerak bo'ladi (`auth.user_system_access.role = 'viloyat'`).
- Jadval barcha ustunlarda `0` ko'rsatishi **kutilgan holat** — deputatlar ish boshlagach to'ladi.
