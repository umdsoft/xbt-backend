# `tuman` (туман кўрувчиси) роли — амалга ошириш режаси

> **Агент ишчилар учун:** МАЖБУРИЙ КИЧИК КЎНИКМА: `superpowers:subagent-driven-development`
> билан бажарилади. Қадамлар `- [ ]` белгиси билан кузатилади.

**Мақсад:** Backendга туман билан чекланган «фақат кўриш» ролини қўшиш, ва 8 та
мавжуд executive эндпойнтида қамровни мажбурлаш.

**Архитектура:** Битта янги рол (`tuman`) + битта марказий қамров синфи
(`ExecutiveScope`). Контроллерлар қамров қарорини ўзлари қабул қилмайди —
ҳаммаси шу синф орқали ўтади.

**Технология:** Laravel 12, PHP 8.4 (`C:\php84\php.exe`), PostgreSQL 16 + PostGIS,
PHPUnit (класс асосидаги тестлар).

**Дизайн ҳужжати:** `docs/superpowers/specs/2026-09-13-rahbar-rejimi-design.md`

---

## Глобал чекловлар

Булар ҲАР БИР вазифага тегишли:

1. **PHP 8.4 шарт.** Тестлар `C:\php84\php.exe vendor/bin/phpunit` билан ишлатилади.
   Стандарт `php` 8.3 ва ишламайди.
2. **`RefreshDatabase` / `DatabaseMigrations` / `DatabaseTruncation` ТАҚИҚЛАНГАН**
   (`tests/TestCase.php` уларни рад этади). Фақат `DatabaseTransactions` ва
   `protected array $connectionsToTransact = ['pgsql', 'auth', 'master', 'mahalla'];`
3. **Гео маълумот (туман/маҳалла/кўча/бино) ҲАҚИҚИЙ** — тестлар уни яратмайди,
   мавжудидан танлайди. Фақат операцион қаторлар (user, house, observation) яратилади.
4. **Стандарт туманга қайтиш `tuman` роли учун ТАҚИҚЛАНГАН.** Профилида туман
   кўрсатилмаган `tuman` фойдаланувчи 403 олади, Шовот маълумотини ЭМАС.
5. **Мавжуд `viloyat`/`admin`/`deputat`/`rais`/`hokim-yordamchisi` хулқи ўзгармайди.**
   Ҳар бир вазифада регрессия тести бўлиши шарт.
6. Коммит хабарлари ўзбекча, `feat(mahalla):` / `test(mahalla):` / `fix(mahalla):` шаклида.
   **Аттрибуция қаторлари қўшилмайди.**
7. Формат: ишдан сўнг `C:\php84\php.exe vendor/bin/pint --dirty`.

---

## Файл структураси

| Файл | Масъулияти |
|---|---|
| `app/Domains/Mahalla/Support/MahallaAccess.php` | **ЎЗГАРАДИ** — `tuman` роли |
| `app/Domains/Mahalla/Support/ExecutiveScope.php` | **ЯНГИ** — қамровни ҳал қилиш |
| `.../Api/Executive/DistrictDashboardController.php` | **ЎЗГАРАДИ** |
| `.../Api/Executive/SocialObjectsController.php` | **ЎЗГАРАДИ** |
| `.../Api/Executive/ScoringController.php` | **ЎЗГАРАДИ** |
| `.../Api/Executive/DistrictGeoJsonController.php` | **ЎЗГАРАДИ** |
| `.../Api/Executive/DistrictListController.php` | **ЎЗГАРАДИ** |
| `.../Api/Executive/MahallaDashboardController.php` | **ЎЗГАРАДИ** |
| `.../Api/Executive/ObodDashboardController.php` | **ЎЗГАРАДИ** |
| `.../Api/Executive/ExecutiveProjectsController.php` | **ЎЗГАРАДИ** |
| `tests/Feature/Mahalla/TumanViewerScopeTest.php` | **ЯНГИ** — қамров тестлари |

---

### Vazifa 1: `tuman` роли — `MahallaAccess`

**Файллар:**
- Ўзгартириш: `app/Domains/Mahalla/Support/MahallaAccess.php`
- Тест: `tests/Feature/Mahalla/TumanViewerScopeTest.php` (янги)

**Интерфейслар:**
- Ишлатади: `MahallaScope(bool $isAdmin, ?string $districtId, ?string $mahallaId, array $streetIds, bool $restrictToStreets, bool $canSeeAll = false)`
- Беради: рол коди `'tuman'`; `MahallaAccess::VIEWER_ROLES` энди 3 элемент

- [ ] **Қадам 1: Йиқиладиган тестни ёзиш**

`tests/Feature/Mahalla/TumanViewerScopeTest.php` яратилади. Ёрдамчилар
`ExecutiveDashboardTest.php` дан кўчирилади (`makeUser`, `districtId`), лекин
`makeUser` профилсиз user яратади — `tuman` учун профилда `district_id` керак,
шунинг учун янги ёрдамчи `makeTumanUser(?string $districtId)`.

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Mahalla;

use App\Domains\Mahalla\Support\MahallaAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `tuman` roli — tuman bilan cheklangan «faqat ko'rish» rahbariyati.
 *
 * `viloyat` dan farqi FAQAT qamrovda: ruxsatlar bir xil, lekin canSeeAll=false
 * va districtId profildan olinadi.
 */
class TumanViewerScopeTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, string> */
    protected array $connectionsToTransact = ['pgsql', 'auth', 'master', 'mahalla'];

    public function test_tuman_role_is_a_viewer_role(): void
    {
        $this->assertContains('tuman', MahallaAccess::VIEWER_ROLES);
    }

    public function test_tuman_has_view_only_permissions(): void
    {
        $user = $this->makeTumanUser($this->districtId());
        $perms = app(MahallaAccess::class)->permissionsFor($user);

        $this->assertContains('dashboard.view', $perms);
        $this->assertContains('analyses.view', $perms);
        $this->assertNotContains('photos.upload', $perms, 'rahbar surat yuklamaydi');
        $this->assertNotContains('*', $perms, 'rahbar super-admin emas');
    }

    public function test_tuman_scope_is_limited_to_its_own_district(): void
    {
        $districtId = $this->districtId();
        $user = $this->makeTumanUser($districtId);

        $scope = app(MahallaAccess::class)->scopeFor($user);

        $this->assertFalse($scope->canSeeAll, 'tuman HAMMASINI ko\'rmaydi');
        $this->assertFalse($scope->isAdmin, 'tuman boshqaruvchi emas');
        $this->assertFalse($scope->restrictToStreets, 'tuman ko\'chalar bilan cheklanmaydi');
        $this->assertSame($districtId, $scope->districtId);
        $this->assertNull($scope->mahallaId, 'tuman bitta mahalla bilan cheklanmaydi');
    }

    public function test_tuman_without_district_on_profile_gets_null_district(): void
    {
        $user = $this->makeTumanUser(null);

        $scope = app(MahallaAccess::class)->scopeFor($user);

        $this->assertNull($scope->districtId, 'profilsiz tuman useriga tuman berilmaydi');
        $this->assertFalse($scope->canSeeAll, 'va u HAMMASINI ham ko\'rmaydi — fail-closed');
    }

    /**
     * REGRESSIYA. `tuman` qo'shilganda `viloyat` xulqi buzilmaganini qulflaydi.
     */
    public function test_viloyat_still_sees_everything(): void
    {
        $user = $this->makeUserWithRole('viloyat');
        $scope = app(MahallaAccess::class)->scopeFor($user);

        $this->assertTrue($scope->canSeeAll);
        $this->assertFalse($scope->isAdmin);
    }

    /**
     * REGRESSIYA. `deputat` hamon ko'chalar bilan cheklangan.
     */
    public function test_deputat_still_restricted_to_streets(): void
    {
        $user = $this->makeUserWithRole('deputat');
        $scope = app(MahallaAccess::class)->scopeFor($user);

        $this->assertFalse($scope->canSeeAll);
        $this->assertTrue($scope->restrictToStreets);
    }

    // ---------- fikstura yordamchilari ----------

    protected function districtId(): string
    {
        $id = DB::connection('master')->table('districts')
            ->where('soato_code', (string) config('mahalla.executive.default_district_soato'))
            ->value('id');

        $this->assertNotNull($id, 'Standart tuman (Shovot) bazada bo\'lishi kerak');

        return (string) $id;
    }

    protected function anotherDistrictId(string $exclude): string
    {
        $id = DB::connection('master')->table('districts')
            ->where('id', '!=', $exclude)->orderBy('sort_order')->value('id');

        $this->assertNotNull($id, 'Ikkinchi tuman bazada bo\'lishi kerak');

        return (string) $id;
    }

    protected function makeUserWithRole(string $role): User
    {
        $userId = (string) Str::uuid();
        $now = now();

        DB::connection('auth')->table('users')->insert([
            'id' => $userId, 'login' => 'test_'.substr($userId, 0, 8),
            'password' => bcrypt('secret'), 'name' => 'ТЕСТ раҳбар',
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

    /**
     * `tuman` roli + mahalla profili (district_id shu yerdan olinadi).
     * `$districtId = null` — profilda tuman ko'rsatilmagan holat.
     *
     * `mahalla.users` ustunlari (2026_07_18_156005 migratsiyasi):
     * id, name (NOT NULL), login (NOT NULL, UNIQUE), password?, email?,
     * district_id?, mahalla_id?, is_active, timestamps, deleted_at.
     */
    protected function makeTumanUser(?string $districtId): User
    {
        $user = $this->makeUserWithRole('tuman');

        DB::connection('mahalla')->table('users')->insert([
            'id' => $user->id,
            'name' => 'ТЕСТ туман раҳбари',
            'login' => 'test_'.substr((string) $user->id, 0, 8),
            'district_id' => $districtId,
            'mahalla_id' => null,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }
}
```

- [ ] **Қадам 2: Тестни ишга тушириб, ЙИҚИЛИШИНИ кўриш**

```
C:\php84\php.exe vendor/bin/phpunit --filter=TumanViewerScopeTest
```
Кутилган: `test_tuman_role_is_a_viewer_role` йиқилади (`tuman` `VIEWER_ROLES` да йўқ).

> **Эслатма:** `mahalla.users` жадвалининг ҳақиқий устунларини аввал текшир:
> `\d mahalla.users` ёки миграцияни ўқи. Агар `position` устуни `NOT NULL` бўлса
> ёки бошқа мажбурий устун бўлса — фиксурани мослаштир, лекин `district_id`
> `null` бўла олишини сақла.

- [ ] **Қадам 3: `MahallaAccess` га `tuman` ролини қўшиш**

`PERMISSIONS` массивига `'viloyat'` дан кейин:

```php
        /*
         * Туман раҳбарияти — `viloyat` билан АЙНАН БИР ХИЛ кўриш ҳуқуқи.
         * Фарқ ruxsatlar рўйхатида эмас, `scopeFor()` даги ҚАМРОВДА:
         * `viloyat` бутун вилоятни, `tuman` эса фақат ўз туманини кўради.
         * Шунинг учун бу икки қатор атайлаб бир хил — уларни «DRY» деб
         * бирлаштириш нотўғри бўларди, чунки улар бошқа-бошқа сабабга кўра
         * ўзгаради.
         */
        'tuman' => ['dashboard.view', 'reports.view', 'houses.view', 'analyses.view'],
```

`VIEWER_ROLES`:

```php
    public const VIEWER_ROLES = ['admin', 'viloyat', 'tuman'];
```

`scopeFor()` да — `$profile` олингандан КЕЙИН, `rais` шохидан ОЛДИН:

```php
        /*
         * Туман кўрувчиси — бутун туманини кўради, лекин ундан ташқарига чиқа олмайди.
         *
         * `mahallaId = null` ва `streetIds = []` атайлаб: у бирор маҳалла ёки
         * кўча билан чекланмаган. Натижада `House::scopeVisibleTo()` унга
         * `whereIn('street_id', [])` беради, яъни ХОНАДОН рўйхатида ҲЕЧ НАРСА
         * кўрмайди. Бу ХАТО ЭМАС — fail-closed: раҳбар жамланма кўрсаткичларни
         * `executive/*` эндпойнтлари орқали кўради, хом хонадон рўйхатини эмас.
         * Уни «тузатиб» очиб юбориш — қамровни бузиш.
         */
        if ($role === 'tuman') {
            return new MahallaScope(
                false,
                $profile?->district_id,
                null,
                [],
                false,
                false,
            );
        }
```

- [ ] **Қадам 4: Тестларни ишга тушириб, ЎТИШИНИ кўриш**

```
C:\php84\php.exe vendor/bin/phpunit --filter=TumanViewerScopeTest
```
Кутилган: 6/6 ўтади.

Кейин регрессия:
```
C:\php84\php.exe vendor/bin/phpunit --filter=Mahalla
```
Кутилган: барчаси яшил (аввал 94 та эди, энди 100 та).

- [ ] **Қадам 5: Коммит**

```bash
C:\php84\php.exe vendor/bin/pint --dirty
git add app/Domains/Mahalla/Support/MahallaAccess.php tests/Feature/Mahalla/TumanViewerScopeTest.php
git commit -m "feat(mahalla): tuman — tuman bilan cheklangan ko'rish roli"
```

---

### Vazifa 2: `ExecutiveScope` — қамровни ҳал қилувчи синф

**Файллар:**
- Яратиш: `app/Domains/Mahalla/Support/ExecutiveScope.php`
- Ўзгартириш: `tests/Feature/Mahalla/TumanViewerScopeTest.php` (тестлар қўшилади)

**Интерфейслар:**
- Ишлатади: `MahallaAccess::scopeFor(User): MahallaScope` (Vazifa 1 дан)
- Беради — кейинги вазифалар шу учта методни чақиради:
  - `district(User $user, ?string $requestedId): District`
  - `mahalla(User $user, string $mahallaId): Mahalla`
  - `visibleDistrictIds(User $user): ?array` (`null` = ҳаммаси)

- [ ] **Қадам 1: Йиқиладиган тестларни ёзиш**

`TumanViewerScopeTest.php` га қўшилади:

```php
    public function test_scope_resolves_own_district_when_none_requested(): void
    {
        $districtId = $this->districtId();
        $user = $this->makeTumanUser($districtId);

        $model = app(\App\Domains\Mahalla\Support\ExecutiveScope::class)
            ->district($user, null);

        $this->assertSame($districtId, (string) $model->id);
    }

    public function test_scope_allows_own_district_when_requested_explicitly(): void
    {
        $districtId = $this->districtId();
        $user = $this->makeTumanUser($districtId);

        $model = app(\App\Domains\Mahalla\Support\ExecutiveScope::class)
            ->district($user, $districtId);

        $this->assertSame($districtId, (string) $model->id);
    }

    public function test_scope_forbids_another_district(): void
    {
        $own = $this->districtId();
        $other = $this->anotherDistrictId($own);
        $user = $this->makeTumanUser($own);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->expectExceptionCode(403);

        app(\App\Domains\Mahalla\Support\ExecutiveScope::class)->district($user, $other);
    }

    /**
     * ENG MUHIM TEST. Profilida tuman ko'rsatilmagan `tuman` user standart
     * tumanga (Shovot) TUSHMASLIGI kerak — aks holda noto'g'ri sozlangan
     * hisob jimgina begona tuman ma'lumotini oladi.
     */
    public function test_scope_forbids_tuman_without_district_instead_of_defaulting(): void
    {
        $user = $this->makeTumanUser(null);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->expectExceptionCode(403);

        app(\App\Domains\Mahalla\Support\ExecutiveScope::class)->district($user, null);
    }

    public function test_scope_lets_viloyat_open_any_district(): void
    {
        $own = $this->districtId();
        $other = $this->anotherDistrictId($own);
        $user = $this->makeUserWithRole('viloyat');

        $model = app(\App\Domains\Mahalla\Support\ExecutiveScope::class)
            ->district($user, $other);

        $this->assertSame($other, (string) $model->id);
    }

    public function test_scope_defaults_viloyat_to_configured_district(): void
    {
        $user = $this->makeUserWithRole('viloyat');

        $model = app(\App\Domains\Mahalla\Support\ExecutiveScope::class)
            ->district($user, null);

        $this->assertSame($this->districtId(), (string) $model->id);
    }

    public function test_scope_rejects_mahalla_outside_own_district(): void
    {
        $own = $this->districtId();
        $other = $this->anotherDistrictId($own);
        $user = $this->makeTumanUser($own);

        $foreign = DB::connection('master')->table('mahallas')
            ->where('district_id', $other)->value('id');
        $this->assertNotNull($foreign, 'Boshqa tumanda mahalla bo\'lishi kerak');

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        app(\App\Domains\Mahalla\Support\ExecutiveScope::class)
            ->mahalla($user, (string) $foreign);
    }

    public function test_scope_accepts_mahalla_inside_own_district(): void
    {
        $own = $this->districtId();
        $user = $this->makeTumanUser($own);

        $mine = DB::connection('master')->table('mahallas')
            ->where('district_id', $own)->value('id');

        $model = app(\App\Domains\Mahalla\Support\ExecutiveScope::class)
            ->mahalla($user, (string) $mine);

        $this->assertSame((string) $mine, (string) $model->id);
    }

    public function test_visible_district_ids_is_null_for_viloyat_and_single_for_tuman(): void
    {
        $scope = app(\App\Domains\Mahalla\Support\ExecutiveScope::class);

        $this->assertNull($scope->visibleDistrictIds($this->makeUserWithRole('viloyat')));

        $own = $this->districtId();
        $this->assertSame([$own], $scope->visibleDistrictIds($this->makeTumanUser($own)));

        $this->assertSame([], $scope->visibleDistrictIds($this->makeTumanUser(null)),
            'tumansiz user hech qanday tuman ko\'rmaydi');
    }
```

- [ ] **Қадам 2: Тестларни ишга тушириб, ЙИҚИЛИШИНИ кўриш**

```
C:\php84\php.exe vendor/bin/phpunit --filter=TumanViewerScopeTest
```
Кутилган: янги 9 та тест `Class "App\Domains\Mahalla\Support\ExecutiveScope" not found` билан йиқилади.

- [ ] **Қадам 3: `ExecutiveScope` ни ёзиш**

`app/Domains/Mahalla/Support/ExecutiveScope.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Support;

use App\Domains\Mahalla\Models\Master\District;
use App\Domains\Mahalla\Models\Master\Mahalla;
use App\Models\User;

/**
 * Rahbariyat (executive) endpointlari uchun QAMROV hal qiluvchisi.
 *
 * Nima uchun alohida sinf: `executive/*` ostidagi 8 ta controller hozirgacha
 * tumanni umuman tekshirmas edi — u yerga faqat `canSeeAll` rollari kirardi.
 * `tuman` roli qo'shilishi bilan har bir controller "bu user shu tumanni
 * ko'ra oladimi?" degan savolga javob berishi shart bo'ldi. Bu qarorni 8 joyda
 * takrorlash — 8 ta xato qilish imkoniyati; shuning uchun bitta joyda.
 */
final class ExecutiveScope
{
    public function __construct(private readonly MahallaAccess $access)
    {
    }

    /**
     * So'ralgan tumanni hal qiladi.
     *
     * `canSeeAll` (admin/viloyat): istalgan tuman; berilmasa konfiguratsiyadagi
     * standart tuman.
     *
     * Tuman bilan cheklangan user: FAQAT o'z tumani. Boshqasi so'ralsa 403.
     * Profilida tuman yo'q bo'lsa ham 403 — standart tumanga TUSHMAYDI.
     */
    public function district(User $user, ?string $requestedId): District
    {
        $scope = $this->access->scopeFor($user);

        if ($scope->canSeeAll) {
            return $requestedId !== null
                ? District::on('master')->findOrFail($requestedId)
                : $this->defaultDistrict();
        }

        $own = $scope->districtId;

        if ($own === null) {
            abort(403, 'Профилингизда туман кўрсатилмаган.');
        }

        if ($requestedId !== null && $requestedId !== $own) {
            abort(403, 'Бу туман сизнинг қамровингизда эмас.');
        }

        return District::on('master')->findOrFail($own);
    }

    /**
     * Mahallani hal qiladi va u ruxsat etilgan tuman ichida ekanini tekshiradi.
     *
     * Qamrovdan tashqarisi uchun 404 (403 emas) — begona tumandagi mahalla
     * `id` sining MAVJUDLIGINI ham oshkor qilmaslik uchun.
     */
    public function mahalla(User $user, string $mahallaId): Mahalla
    {
        $scope = $this->access->scopeFor($user);
        $model = Mahalla::on('master')->with('district')->findOrFail($mahallaId);

        if ($scope->canSeeAll) {
            return $model;
        }

        if ($scope->districtId === null || (string) $model->district_id !== $scope->districtId) {
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())
                ->setModel(Mahalla::class, [$mahallaId]);
        }

        return $model;
    }

    /**
     * Tanlagichda ko'rinadigan tuman id'lari. `null` — cheklov yo'q (hammasi).
     *
     * @return array<int, string>|null
     */
    public function visibleDistrictIds(User $user): ?array
    {
        $scope = $this->access->scopeFor($user);

        if ($scope->canSeeAll) {
            return null;
        }

        return $scope->districtId !== null ? [$scope->districtId] : [];
    }

    private function defaultDistrict(): District
    {
        return District::on('master')
            ->where('soato_code', (string) config('mahalla.executive.default_district_soato'))
            ->firstOrFail();
    }
}
```

- [ ] **Қадам 4: Тестларни ишга тушириб, ЎТИШИНИ кўриш**

```
C:\php84\php.exe vendor/bin/phpunit --filter=TumanViewerScopeTest
```
Кутилган: 15/15 ўтади.

- [ ] **Қадам 5: Коммит**

```bash
C:\php84\php.exe vendor/bin/pint --dirty
git add app/Domains/Mahalla/Support/ExecutiveScope.php tests/Feature/Mahalla/TumanViewerScopeTest.php
git commit -m "feat(mahalla): ExecutiveScope — rahbariyat endpointlari uchun qamrov hal qiluvchisi"
```

---

### Vazifa 3: Туман шаклидаги 5 та эндпойнтни қамровга улаш

**Файллар:**
- Ўзгартириш: `app/Domains/Mahalla/Http/Controllers/Api/Executive/DistrictDashboardController.php`
- Ўзгартириш: `.../SocialObjectsController.php`
- Ўзгартириш: `.../ScoringController.php`
- Ўзгартириш: `.../DistrictGeoJsonController.php`
- Ўзгартириш: `.../DistrictListController.php`
- Тест: `tests/Feature/Mahalla/TumanViewerScopeTest.php`

**Интерфейслар:**
- Ишлатади: `ExecutiveScope::district()`, `ExecutiveScope::visibleDistrictIds()` (Vazifa 2 дан)

- [ ] **Қадам 1: Йиқиладиган HTTP тестларини ёзиш**

```php
    /**
     * Har bir "tuman shaklidagi" endpoint uchun bir xil uch holat:
     * o'z tumani 200, begona tuman 403, tumansiz profil 403.
     *
     * @return array<string, array{0: string}>
     */
    public static function districtEndpointProvider(): array
    {
        return [
            'dashboard' => ['/api/mahalla/executive/districts/%s'],
            'social-objects' => ['/api/mahalla/executive/districts/%s/social-objects'],
            'geojson' => ['/api/mahalla/executive/districts/%s/geojson'],
            'scoring' => ['/api/mahalla/executive/scoring/%s'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('districtEndpointProvider')]
    public function test_tuman_can_open_its_own_district(string $template): void
    {
        $own = $this->districtId();
        $user = $this->makeTumanUser($own);

        $this->actingAs($user, 'sanctum')
            ->getJson(sprintf($template, $own))
            ->assertOk();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('districtEndpointProvider')]
    public function test_tuman_cannot_open_another_district(string $template): void
    {
        $own = $this->districtId();
        $other = $this->anotherDistrictId($own);
        $user = $this->makeTumanUser($own);

        $this->actingAs($user, 'sanctum')
            ->getJson(sprintf($template, $other))
            ->assertForbidden();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('districtEndpointProvider')]
    public function test_tuman_without_district_is_forbidden(string $template): void
    {
        $user = $this->makeTumanUser(null);
        $someDistrict = $this->districtId();

        $this->actingAs($user, 'sanctum')
            ->getJson(sprintf($template, $someDistrict))
            ->assertForbidden();
    }

    public function test_tuman_dashboard_without_id_falls_back_to_own_district(): void
    {
        $own = $this->districtId();
        $user = $this->makeTumanUser($own);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/districts')
            ->assertOk()
            ->assertJsonPath('district.id', $own);
    }

    public function test_district_list_shows_only_own_district_to_tuman(): void
    {
        $own = $this->districtId();
        $user = $this->makeTumanUser($own);

        $res = $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/district-list')
            ->assertOk();

        $ids = array_column($res->json('districts'), 'id');
        $this->assertSame([$own], $ids);
    }

    /**
     * REGRESSIYA: viloyat hamon barcha tumanlarni ko'radi.
     */
    public function test_district_list_shows_all_districts_to_viloyat(): void
    {
        $user = $this->makeUserWithRole('viloyat');

        $res = $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/district-list')
            ->assertOk();

        $this->assertGreaterThan(1, count($res->json('districts')),
            'viloyat bir nechta tuman ko\'rishi kerak');
    }

    /**
     * REGRESSIYA: viloyat begona tumanni ham ocha oladi.
     */
    public function test_viloyat_can_open_any_district(): void
    {
        $own = $this->districtId();
        $other = $this->anotherDistrictId($own);
        $user = $this->makeUserWithRole('viloyat');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/districts/'.$other)
            ->assertOk()
            ->assertJsonPath('district.id', $other);
    }
```

- [ ] **Қадам 2: Тестларни ишга тушириб, ЙИҚИЛИШИНИ кўриш**

```
C:\php84\php.exe vendor/bin/phpunit --filter=TumanViewerScopeTest
```
Кутилган: `test_tuman_cannot_open_another_district` 200 қайтариб йиқилади (403 кутилган эди) —
бу айнан тузатилаётган нуқсон.

- [ ] **Қадам 3: 5 та контроллерни улаш**

Ҳар бирида бир хил намуна — `Request` ни биринчи параметр қилиб қўшиб,
моделни `ExecutiveScope` орқали олиш.

`DistrictDashboardController`:

```php
    public function __construct(
        private readonly ExecutiveStats $stats,
        private readonly ExecutiveScope $scope,
    ) {
    }

    public function __invoke(Request $request, ?string $district = null): JsonResponse
    {
        $model = $this->scope->district($request->user(), $district);

        // ... қолгани ўзгармайди
    }
```

`SocialObjectsController`, `DistrictGeoJsonController` — `__invoke(Request $request, string $district)`,
ичида `District::on('master')->findOrFail($district)` ўрнига
`$this->scope->district($request->user(), $district)`.

`ScoringController` — `__invoke(Request $request, ?string $district = null)`, худди
`DistrictDashboardController` каби.

`DistrictListController` — `__invoke(Request $request)`, туманлар сўровига фильтр:

```php
        $visible = $this->scope->visibleDistrictIds($request->user());

        $query = DB::connection('master')->table('districts')
            ->orderBy('sort_order')->orderBy('name_cyr');

        // null — cheklov yo'q; massiv — faqat shular (bo'sh massiv = hech narsa)
        if ($visible !== null) {
            $query->whereIn('id', $visible);
        }

        $rows = $query->get(['id', 'name_cyr', 'soato_code'])
        // ... қолгани ўзгармайди
```

`use` қаторларини қўшишни унутма:
`use App\Domains\Mahalla\Support\ExecutiveScope;` ва `use Illuminate\Http\Request;`

- [ ] **Қадам 4: Тестларни ишга тушириб, ЎТИШИНИ кўриш**

```
C:\php84\php.exe vendor/bin/phpunit --filter=TumanViewerScopeTest
C:\php84\php.exe vendor/bin/phpunit --filter=Mahalla
```
Кутилган: иккаласи ҳам яшил.

- [ ] **Қадам 5: Коммит**

```bash
C:\php84\php.exe vendor/bin/pint --dirty
git add app/Domains/Mahalla/Http/Controllers/Api/Executive/ tests/Feature/Mahalla/TumanViewerScopeTest.php
git commit -m "feat(mahalla): tuman qamrovi — tuman kesimidagi 5 ta rahbariyat endpointi"
```

---

### Vazifa 4: Маҳалла шаклидаги 3 та эндпойнт + якуний текширув

**Файллар:**
- Ўзгартириш: `.../MahallaDashboardController.php`
- Ўзгартириш: `.../ObodDashboardController.php`
- Ўзгартириш: `.../ExecutiveProjectsController.php`
- Тест: `tests/Feature/Mahalla/TumanViewerScopeTest.php`

**Интерфейслар:**
- Ишлатади: `ExecutiveScope::mahalla()` (Vazifa 2 дан)

- [ ] **Қадам 1: Йиқиладиган тестларни ёзиш**

```php
    /**
     * @return array<string, array{0: string}>
     */
    public static function mahallaEndpointProvider(): array
    {
        return [
            'dashboard' => ['/api/mahalla/executive/mahallas/%s'],
            'obod' => ['/api/mahalla/executive/mahallas/%s/obod'],
            'projects' => ['/api/mahalla/executive/mahallas/%s/projects'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mahallaEndpointProvider')]
    public function test_tuman_can_open_mahalla_in_own_district(string $template): void
    {
        $own = $this->districtId();
        $user = $this->makeTumanUser($own);
        $mine = (string) DB::connection('master')->table('mahallas')
            ->where('district_id', $own)->value('id');

        $this->actingAs($user, 'sanctum')->getJson(sprintf($template, $mine))->assertOk();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mahallaEndpointProvider')]
    public function test_tuman_cannot_open_mahalla_in_another_district(string $template): void
    {
        $own = $this->districtId();
        $other = $this->anotherDistrictId($own);
        $user = $this->makeTumanUser($own);
        $foreign = (string) DB::connection('master')->table('mahallas')
            ->where('district_id', $other)->value('id');

        $this->actingAs($user, 'sanctum')->getJson(sprintf($template, $foreign))->assertNotFound();
    }

    /**
     * REGRESSIYA: viloyat istalgan mahallani ocha oladi.
     */
    public function test_viloyat_can_open_mahalla_in_any_district(): void
    {
        $own = $this->districtId();
        $other = $this->anotherDistrictId($own);
        $user = $this->makeUserWithRole('viloyat');
        $foreign = (string) DB::connection('master')->table('mahallas')
            ->where('district_id', $other)->value('id');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/executive/mahallas/'.$foreign)
            ->assertOk();
    }

    /**
     * `nearby` endpointi `tuman` uchun ALLAQACHON to'g'ri ishlashi kerak:
     * u `canSeeAll` bo'lmagan userni `scope->districtId` bilan cheklaydi.
     * Bu testni qo'shish shu bog'lanishni qulflaydi — kelajakda kimdir
     * `scopeFor()` ni o'zgartirsa, shu test tutadi.
     */
    public function test_nearby_scopes_tuman_to_its_own_district(): void
    {
        $own = $this->districtId();
        $user = $this->makeTumanUser($own);

        $center = DB::connection('master')->table('mahallas')
            ->where('district_id', $own)->whereNotNull('center_lat')
            ->first(['center_lat', 'center_lng']);
        $this->assertNotNull($center, 'Tumanda markaz koordinatasi bo\'lgan mahalla kerak');

        $res = $this->actingAs($user, 'sanctum')->getJson(sprintf(
            '/api/mahalla/nearby?lat=%s&lng=%s&radius_m=3000&limit=50',
            $center->center_lat, $center->center_lng
        ))->assertOk();

        $this->assertNotEmpty($res->json('points'), 'tuman rahbari o\'z tumanida nuqtalarni ko\'rishi kerak');
    }

    /**
     * Tumansiz `tuman` user `nearby` da ham hech narsa ko'rmaydi.
     */
    public function test_nearby_returns_nothing_for_tuman_without_district(): void
    {
        $user = $this->makeTumanUser(null);

        $res = $this->actingAs($user, 'sanctum')->getJson(
            '/api/mahalla/nearby?lat=41.62&lng=60.38&radius_m=3000&limit=50'
        )->assertOk();

        $this->assertSame([], $res->json('points'));
    }
```

- [ ] **Қадам 2: Тестларни ишга тушириб, ЙИҚИЛИШИНИ кўриш**

```
C:\php84\php.exe vendor/bin/phpunit --filter=TumanViewerScopeTest
```
Кутилган: `test_tuman_cannot_open_mahalla_in_another_district` 200 қайтариб йиқилади.

- [ ] **Қадам 3: 3 та контроллерни улаш**

Ҳар учаласида `Mahalla::on('master')->with('district')->findOrFail($mahalla)`
ўрнига:

```php
    public function __invoke(Request $request, string $mahalla): JsonResponse
    {
        $model = $this->scope->mahalla($request->user(), $mahalla);
```

конструкторга `private readonly ExecutiveScope $scope,` қўшилади,
`use App\Domains\Mahalla\Support\ExecutiveScope;` ва `use Illuminate\Http\Request;`.

`ExecutiveProjectsController` ни аввал ўқи — у `Mahalla` моделини бошқача
олаётган бўлиши мумкин; намунани мослаштир, лекин қамров текшируви
`ExecutiveScope::mahalla()` орқали бўлиши шарт.

- [ ] **Қадам 4: Якуний тўлиқ текширув**

```
C:\php84\php.exe vendor/bin/phpunit
```
Кутилган: барчаси яшил (аввал 726 та эди).

- [ ] **Қадам 5: Мутация текшируви (тестлар ҳақиқатан ушлайдими)**

Тестларнинг ҳақиқатан ажрата олишини исботлаш учун — вақтинча
`ExecutiveScope::mahalla()` дан қамров текширувини олиб ташла:

```php
        if ($scope->canSeeAll) {
            return $model;
        }
        // ↓ шу блокни вақтинча комментарийга ол
```

Тестни ишга тушир — `test_tuman_cannot_open_mahalla_in_another_district`
**йиқилиши ШАРТ**. Йиқилмаса, тест ҳеч нарсани текширмаяпти демак.
Кейин ўзгаришни қайтар.

Худди шундай `ExecutiveScope::district()` даги 403 шохи учун ҳам.
Натижани ҳисоботда ёз.

- [ ] **Қадам 6: Коммит**

```bash
C:\php84\php.exe vendor/bin/pint --dirty
git add app/Domains/Mahalla/Http/Controllers/Api/Executive/ tests/Feature/Mahalla/TumanViewerScopeTest.php
git commit -m "feat(mahalla): tuman qamrovi — mahalla kesimidagi 3 ta endpoint va nearby bog'lanishi"
```
