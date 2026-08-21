# Yoshlar F1 (poydevor) — backend implementatsiya rejasi

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `yoshlar` domeni poydevorini qurish — PG schema, markaziy auth integratsiyasi, 6 rolli RBAC, ikki o'lchovli fail-closed scope, tashkilot/xodim reyestri, PII-himoyalangan yoshlar reyestri va hisob yaratish buyrug'i.

**Architecture:** `digital-xorazm` ekotizimining mavjud domen naqshi aynan takrorlanadi: alohida PG schema (`yoshlar`) bir xil DB ichida, `search_path=yoshlar,master,public`; rol markaziy `auth.user_system_access` dan o'qiladi, ruxsat kod xaritasidan; ko'rish doirasi domen ichidagi `staff` jadvalidan kelib chiqadi va profil topilmasa **bo'sh natija** qaytaradi (fail-closed). Geo ma'lumot `master.districts/mahallas` da qoladi — cross-schema FK yaratilmaydi, faqat uuid + index.

**Tech Stack:** Laravel 11 (PHP 8.4 — `C:\php84\php.exe`), PostgreSQL 16 (`kbt` DB), Sanctum SPA auth, PHPUnit (`DatabaseTransactions`).

**Spec:** `docs/superpowers/specs/2026-08-21-yoshlar-f1-design.md`

## Global Constraints

- PHP interpretatori: **`C:/php84/php.exe`** (default `php` 8.3 — ishlatilmaydi).
- Ish papkasi: **`D:\kadr\platform`**. Barcha yo'llar shu papkaga nisbatan.
- Migratsiya **idempotent va forward-only**: `CREATE SCHEMA IF NOT EXISTS`, jadval mavjud bo'lsa `return`. `down()` yozilmaydi (ekotizim qoidasi).
- Migratsiya faqat PostgreSQL'da ishlaydi: `if (config('database.default') !== 'pgsql') return;`.
- **Cross-schema FK YO'Q.** `master.*` va `auth.*` ga faqat `uuid` ustun + index.
- Testlar **dev bazasida** (`kbt`) yuradi, har test `DatabaseTransactions` bilan qaytariladi. **`RefreshDatabase` TAQIQLANGAN** (`tests/TestCase.php` darvozasi to'xtatadi).
- Barcha PHP fayl `declare(strict_types=1);` bilan boshlanadi.
- Kod izohlari **o'zbek tilida** (lotin), ekotizimdagi kabi — «nega» tushuntiriladi, «nima» emas.
- Domen kodi: `App\Domains\Yoshlar\...`, tizim kodi `auth.systems.code = 'yoshlar'`.
- Ma'lumot ustunlari: spravochniklarda `name_cyr` + `name_lat` ikkalasi; yoshlar F.I.Sh **lotinda**.
- Har task oxirida **lokal commit**. `git push` QILINMAYDI (foydalanuvchi topshirig'isiz).
- Test buyrug'i: `C:/php84/php.exe artisan test --filter=<Klass>`.

---

## File Structure

**Yaratiladi:**

| Fayl | Mas'uliyati |
|---|---|
| `database/migrations/2026_08_21_100000_create_yoshlar_schema.php` | `yoshlar` schema + 6 jadval |
| `app/Domains/Yoshlar/Support/YoshlarAccess.php` | Rol o'qish + ruxsat xaritasi (RBAC) |
| `app/Domains/Yoshlar/Support/YoshlarScope.php` | Geo + org doira, fail-closed |
| `app/Domains/Yoshlar/Support/Translit.php` | Lotin↔kirill (qidiruv normalizatsiyasi) |
| `app/Domains/Yoshlar/Http/Middleware/EnsureYoshlar.php` | Domen gvardiyasi (403) |
| `app/Domains/Yoshlar/Models/{Sector,Organization,Staff,Youth,AuditLog,PiiAccessLog}.php` | Eloquent modellar |
| `app/Domains/Yoshlar/Services/{OrganizationService,StaffService,YouthService,UserAdminService}.php` | Biznes qoidalari |
| `app/Domains/Yoshlar/Services/{AuditLogger,PiiGuard}.php` | Audit va PII ochilishi |
| `app/Domains/Yoshlar/Http/Controllers/Api/{ContextController,YouthController,OrganizationController,StaffController,SectorController,AdminController,AuditController}.php` | API |
| `app/Domains/Yoshlar/Console/Commands/{MakeYoshlarUserCommand,RefreshRegistryCommand}.php` | Konsol buyruqlari |
| `app/Domains/Yoshlar/Database/Seeders/YoshlarReferenceSeeder.php` | 9 sektor + 14 tashkilot |
| `routes/api/yoshlar.php` | Domen marshrutlari |
| `tests/Feature/Yoshlar/*.php` | Feature testlar |

**O'zgartiriladi:** `config/database.php` (yangi ulanish), `bootstrap/app.php` (middleware alias), `routes/api.php` (require), `database/seeders/SystemsSeeder.php` (`yoshlar` tizimi).

---

## Task 1: Schema, ulanish va migratsiya

**Files:**
- Modify: `config/database.php` (`sport` blokidan keyin)
- Create: `database/migrations/2026_08_21_100000_create_yoshlar_schema.php`
- Test: `tests/Feature/Yoshlar/YoshlarSchemaTest.php`

**Interfaces:**
- Consumes: mavjud `master.districts` (13), `master.mahallas` (509), `auth.users`.
- Produces: `yoshlar` ulanishi (`DB::connection('yoshlar')`) va 6 jadval: `sectors`, `organizations`, `staff`, `youth`, `audit_log`, `pii_access_log`.

- [ ] **Step 1: `config/database.php` ga ulanish qo'shish**

`'sport' => [...]` blokidan keyin qo'shing:

```php
        // YOSHLAR (yoshlar ishlari monitoringi) domeni — tashkilot/xodim/reyestr.
        // Qurilish/sport ulanishi naqshi: bir xil host/DB, alohida schema (yoshlar).
        'yoshlar' => [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => env('DB_YOSHLAR_SEARCH_PATH', 'yoshlar,master,public'),
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],
```

- [ ] **Step 2: Migratsiya faylini yozish**

`database/migrations/2026_08_21_100000_create_yoshlar_schema.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * YOSHLAR domeni poydevori — `yoshlar` schema + 6 jadval.
 *
 * Qurilish/murojaat naqshi AYNAN: schema jadvallardan OLDIN yaratiladi;
 * faqat PostgreSQL; mavjud jadval -> skip (idempotent, forward-only).
 *
 * district_id/mahalla_id -> master.districts/mahallas, user_id -> auth.users:
 * cross-schema FK YO'Q (uuid + index), chunki schema'lar mustaqil ko'chiriladi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        DB::connection('yoshlar')->statement('CREATE SCHEMA IF NOT EXISTS yoshlar');
        $schema = Schema::connection('yoshlar');

        // ---------- Spravochnik ----------

        $this->create($schema, 'sectors', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code', 40)->unique();
            $t->string('name_cyr', 300);
            $t->string('name_lat', 300);
            $t->integer('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        // Ikki vertikal (yoshlar + sektoral) YAGONA reyestrda: farq `type` da.
        // parent_id -> tuman tashkiloti o'z viloyat tashkilotiga bog'lanadi.
        $this->create($schema, 'organizations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type', 20)->index();
            $t->uuid('parent_id')->nullable()->index();
            $t->uuid('district_id')->nullable()->index();
            $t->uuid('sector_id')->nullable()->index();
            $t->string('name_cyr', 500);
            $t->string('name_lat', 500);
            $t->string('short_name', 200)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        // Foydalanuvchi profili — SCOPE MANBAI. Bu yozuvsiz rol hech narsa ko'rmaydi.
        $this->create($schema, 'staff', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->index();
            $t->uuid('org_id')->index();
            $t->string('position', 200)->nullable();
            $t->boolean('can_patronage')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        // ---------- Reyestr ----------

        $this->create($schema, 'youth', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('last_name', 120);
            $t->string('first_name', 120);
            $t->string('middle_name', 120)->nullable();
            $t->text('full_name_norm');
            $t->date('birth_date')->index();
            $t->string('gender', 10);
            $t->uuid('district_id')->index();
            $t->uuid('mahalla_id')->index();
            $t->text('address')->nullable();
            $t->string('phone', 30)->nullable();
            // Shifrlangan — `encrypted` cast (Model). Shifrmatn uzunligi o'zgaruvchan.
            $t->text('pinfl')->nullable();
            $t->string('pinfl_hash', 64)->nullable();
            $t->text('passport_series')->nullable();
            $t->text('passport_number')->nullable();
            $t->string('education_status', 30)->default('oqimaydi');
            $t->string('education_place', 300)->nullable();
            $t->string('employment_status', 30)->default('band_emas');
            $t->string('workplace', 300)->nullable();
            $t->boolean('is_neet')->default(false);
            $t->boolean('is_graduate_unemployed')->default(false);
            $t->boolean('in_patronage')->default(false);
            $t->boolean('has_open_case')->default(false);
            $t->boolean('in_youth_book')->default(false);
            $t->boolean('is_entrepreneur')->default(false);
            $t->string('registry_status', 20)->default('active');
            $t->string('verification_status', 20)->default('verified');
            $t->uuid('verified_by')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->text('reject_reason')->nullable();
            $t->uuid('created_by_org_id')->nullable()->index();
            $t->uuid('created_by')->nullable();
            $t->uuid('updated_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['registry_status', 'district_id']);
            $t->index('verification_status');
        });

        // ---------- Jurnal ----------

        $this->create($schema, 'audit_log', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->nullable()->index();
            $t->string('action', 60);
            $t->string('entity_type', 40);
            $t->uuid('entity_id')->nullable()->index();
            $t->jsonb('changes')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->nullable()->index();
        });

        // Maxfiy maydon ochilishi — HECH QACHON o'chirilmaydi (hisobdorlik).
        $this->create($schema, 'pii_access_log', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->index();
            $t->uuid('youth_id')->index();
            $t->string('fields', 120);
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->nullable()->index();
        });

        $this->afterTables();
    }

    /**
     * Jadval yaratilgandan keyingi xom SQL: PINFL unikalligi va FIO qidiruvi.
     *
     * `pinfl_hash` partial unique — PINFL ixtiyoriy, NULL qatorlar cheklanmaydi.
     * Trigram indeks `pg_trgm` bo'lsagina; prodda kengaytmaga huquq bo'lmasligi
     * mumkin, shuning uchun xato yutiladi va btree prefiks indeksiga tushamiz.
     */
    private function afterTables(): void
    {
        $db = DB::connection('yoshlar');

        $db->statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS youth_pinfl_hash_unique
             ON yoshlar.youth (pinfl_hash) WHERE pinfl_hash IS NOT NULL'
        );

        try {
            $db->statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
            $db->statement(
                'CREATE INDEX IF NOT EXISTS youth_full_name_trgm
                 ON yoshlar.youth USING gin (full_name_norm gin_trgm_ops)'
            );
        } catch (\Throwable) {
            $db->statement(
                'CREATE INDEX IF NOT EXISTS youth_full_name_prefix
                 ON yoshlar.youth (full_name_norm text_pattern_ops)'
            );
        }

        // Bitta faol xodim — bitta tashkilot. Aks holda scope qaysi tashkilotni
        // olishini bilmay qoladi (ikki xil doira orasida noaniqlik).
        $db->statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS staff_active_user_unique
             ON yoshlar.staff (user_id) WHERE is_active'
        );
    }

    private function create(\Illuminate\Database\Schema\Builder $schema, string $table, callable $definition): void
    {
        if ($schema->hasTable($table)) {
            return;
        }

        $schema->create($table, $definition);
    }
};
```

- [ ] **Step 3: Migratsiyani ishga tushirish**

Run: `C:/php84/php.exe artisan migrate`
Expected: `2026_08_21_100000_create_yoshlar_schema ... DONE`

- [ ] **Step 4: Idempotentlikni tekshirish uchun test yozish**

`tests/Feature/Yoshlar/YoshlarSchemaTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Yoshlar;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Schema qurilganini va ulanish to'g'ri sozlanganini tekshiradi.
 */
class YoshlarSchemaTest extends TestCase
{
    public function test_all_six_tables_exist(): void
    {
        $schema = Schema::connection('yoshlar');

        foreach (['sectors', 'organizations', 'staff', 'youth', 'audit_log', 'pii_access_log'] as $table) {
            $this->assertTrue($schema->hasTable($table), "yoshlar.{$table} jadvali yo'q");
        }
    }

    public function test_search_path_includes_master(): void
    {
        $this->assertSame(
            'yoshlar,master,public',
            config('database.connections.yoshlar.search_path'),
        );
    }

    public function test_youth_pii_columns_exist(): void
    {
        $columns = Schema::connection('yoshlar')->getColumnListing('youth');

        foreach (['pinfl', 'pinfl_hash', 'passport_series', 'passport_number'] as $column) {
            $this->assertContains($column, $columns);
        }
    }
}
```

- [ ] **Step 5: Testni ishga tushirish**

Run: `C:/php84/php.exe artisan test --filter=YoshlarSchemaTest`
Expected: `OK (3 tests)`

- [ ] **Step 6: Migratsiyani qayta ishga tushirib idempotentlikni tasdiqlash**

Run: `C:/php84/php.exe artisan migrate:status | grep yoshlar`
Expected: `Ran` holati; qayta `migrate` xato bermaydi (yangi migratsiya yo'q).

- [ ] **Step 7: Commit**

```bash
git add config/database.php database/migrations/2026_08_21_100000_create_yoshlar_schema.php tests/Feature/Yoshlar/YoshlarSchemaTest.php
git commit -m "feat(yoshlar): schema poydevori — ulanish va 6 jadval"
```

---

## Task 2: RBAC — `YoshlarAccess` va tizim ro'yxati

**Files:**
- Modify: `database/seeders/SystemsSeeder.php`
- Create: `app/Domains/Yoshlar/Support/YoshlarAccess.php`
- Create: `app/Domains/Yoshlar/Models/Staff.php`, `Organization.php`, `Sector.php`
- Test: `tests/Feature/Yoshlar/YoshlarAccessTest.php` (Task 3'da to'ldiriladi)

**Interfaces:**
- Consumes: `auth.user_system_access.role`, `auth.systems.code='yoshlar'`.
- Produces:
  - `YoshlarAccess::SYSTEM_CODE`, `::ROLES` (6), `::ROLE_NAMES`, `::VIEWER_ROLES`
  - `roleFor(User): ?string`, `isYoshlar(User): bool`, `can(User, string): bool`,
    `permissionsFor(User): array`, `staffFor(User): ?Staff`, `seesEverything(User): bool`

- [ ] **Step 1: `SystemsSeeder` ga `yoshlar` qo'shish**

`database/seeders/SystemsSeeder.php` da `SYSTEMS` massiviga qo'shing:

```php
        ['code' => 'yoshlar', 'name' => 'Ёшлар ишлари мониторинги', 'sort_order' => 7],
```

Run: `C:/php84/php.exe artisan db:seed --class=SystemsSeeder`
Expected: xatosiz tugaydi (mavjud tizimlar o'zgarmaydi — seeder idempotent).

- [ ] **Step 2: Modellarni yozish**

`app/Domains/Yoshlar/Models/Sector.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Sector extends Model
{
    use HasUuids;

    protected $connection = 'yoshlar';

    protected $table = 'sectors';

    protected $fillable = ['code', 'name_cyr', 'name_lat', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }
}
```

`app/Domains/Yoshlar/Models/Organization.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ikki vertikal yagona reyestrda; farq `type` da.
 * Zanjir (F3 bandlik) rolga emas, `type` + `sector_id` juftligiga bog'lanadi —
 * shuning uchun yangi tasdiqlovchi tashkilot kodsiz qo'shiladi.
 */
class Organization extends Model
{
    use HasUuids;

    protected $connection = 'yoshlar';

    protected $table = 'organizations';

    public const TYPE_VILOYAT_YOSHLAR = 'viloyat_yoshlar';

    public const TYPE_TUMAN_YOSHLAR = 'tuman_yoshlar';

    public const TYPE_VILOYAT_SEKTOR = 'viloyat_sektor';

    public const TYPE_TUMAN_SEKTOR = 'tuman_sektor';

    /** @var array<int, string> */
    public const TYPES = [
        self::TYPE_VILOYAT_YOSHLAR,
        self::TYPE_TUMAN_YOSHLAR,
        self::TYPE_VILOYAT_SEKTOR,
        self::TYPE_TUMAN_SEKTOR,
    ];

    /** Rol -> shu rol biriktirilishi mumkin bo'lgan tashkilot turi. */
    public const ROLE_TYPE = [
        'yoshlar_boshqarma' => self::TYPE_VILOYAT_YOSHLAR,
        'yoshlar_bolim' => self::TYPE_TUMAN_YOSHLAR,
        'sektor_boshqarma' => self::TYPE_VILOYAT_SEKTOR,
        'sektor_bolim' => self::TYPE_TUMAN_SEKTOR,
    ];

    protected $fillable = [
        'type', 'parent_id', 'district_id', 'sector_id',
        'name_cyr', 'name_lat', 'short_name', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<Organization, Organization> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<Organization> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return BelongsTo<Sector, Organization> */
    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class, 'sector_id');
    }

    public function isDistrictLevel(): bool
    {
        return in_array($this->type, [self::TYPE_TUMAN_YOSHLAR, self::TYPE_TUMAN_SEKTOR], true);
    }
}
```

`app/Domains/Yoshlar/Models/Staff.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Staff extends Model
{
    use HasUuids;

    protected $connection = 'yoshlar';

    protected $table = 'staff';

    protected $fillable = ['user_id', 'org_id', 'position', 'can_patronage', 'is_active'];

    protected function casts(): array
    {
        return ['can_patronage' => 'boolean', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<Organization, Staff> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }
}
```

- [ ] **Step 3: `YoshlarAccess` ni yozish**

`app/Domains/Yoshlar/Support/YoshlarAccess.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Support;

use App\Domains\Yoshlar\Models\Staff;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * YOSHLAR domeni RBAC — rol markaziy identifikatsiyadan, ruxsat kod xaritasidan
 * (qurilish/advisor naqshi).
 *
 * VAKOLATLAR BO'LINISHI: `yoshlar_admin` da `youth.verify` YO'Q. Hisob ochuvchi
 * odam ayni paytda tasdiqlay olsa, o'ziga hisob ochib, o'zi kiritib, o'zi
 * tasdiqlardi — nazorat sikli soxta bo'lardi.
 *
 * PII need-to-know: `yoshlar_hokim_orinbosari` — eng yuqori mansab, lekin PINFL
 * ko'rmaydi. Rahbariyat agregat ko'radi, shaxsni emas.
 */
class YoshlarAccess
{
    public const SYSTEM_CODE = 'yoshlar';

    /** @var array<int, string> */
    public const ROLES = [
        'yoshlar_hokim_orinbosari',
        'yoshlar_admin',
        'yoshlar_boshqarma',
        'yoshlar_bolim',
        'sektor_boshqarma',
        'sektor_bolim',
    ];

    /** @var array<string, string> */
    public const ROLE_NAMES = [
        'yoshlar_hokim_orinbosari' => 'Hokim o‘rinbosari',
        'yoshlar_admin' => 'Administrator',
        'yoshlar_boshqarma' => 'Viloyat yoshlar boshqarmasi',
        'yoshlar_bolim' => 'Tuman yoshlar bo‘limi',
        'sektor_boshqarma' => 'Viloyat sektor boshqarmasi',
        'sektor_bolim' => 'Tuman sektor bo‘limi',
    ];

    /** Yozish taqiqlangan rollar. */
    public const VIEWER_ROLES = ['yoshlar_hokim_orinbosari'];

    /** Tashkilotsiz ishlay oladigan rollar (viloyat darajasi/super). */
    public const ORG_OPTIONAL_ROLES = ['yoshlar_hokim_orinbosari', 'yoshlar_admin'];

    /** @var array<string, array<int, string>> */
    private const PERMISSIONS = [
        'yoshlar_admin' => [
            'yoshlar.view',
            'yoshlar.export',
            'yoshlar.youth.create',
            'yoshlar.youth.update',
            'yoshlar.youth.delete',
            'yoshlar.pii.reveal',
            'yoshlar.org.manage',
            'yoshlar.staff.manage',
            'yoshlar.user.manage',
            'yoshlar.audit.view',
        ],
        'yoshlar_hokim_orinbosari' => ['yoshlar.view', 'yoshlar.export'],
        'yoshlar_boshqarma' => [
            'yoshlar.view',
            'yoshlar.export',
            'yoshlar.youth.verify',
            'yoshlar.pii.reveal',
            'yoshlar.audit.view',
        ],
        'yoshlar_bolim' => [
            'yoshlar.view',
            'yoshlar.export',
            'yoshlar.youth.create',
            'yoshlar.youth.update',
            'yoshlar.youth.verify',
            'yoshlar.pii.reveal',
        ],
        'sektor_boshqarma' => ['yoshlar.view', 'yoshlar.export'],
        'sektor_bolim' => ['yoshlar.view', 'yoshlar.youth.create'],
    ];

    /** @var array<string, ?string> */
    private array $roleCache = [];

    /** @var array<string, ?Staff> */
    private array $staffCache = [];

    public function roleFor(User $user): ?string
    {
        if (! array_key_exists($user->id, $this->roleCache)) {
            $this->roleCache[$user->id] = DB::connection('auth')->table('user_system_access as usa')
                ->join('systems as s', 's.id', '=', 'usa.system_id')
                ->where('usa.user_id', $user->id)
                ->where('usa.is_active', true)
                ->where('s.code', self::SYSTEM_CODE)
                ->value('usa.role');
        }

        return $this->roleCache[$user->id];
    }

    public function isYoshlar(User $user): bool
    {
        return $this->roleFor($user) !== null;
    }

    public function can(User $user, string $permission): bool
    {
        return in_array($permission, $this->permissionsFor($user), true);
    }

    /** @return array<int, string> */
    public function permissionsFor(User $user): array
    {
        $role = $this->roleFor($user);

        return $role === null ? [] : (self::PERMISSIONS[$role] ?? []);
    }

    public function staffFor(User $user): ?Staff
    {
        if (! array_key_exists($user->id, $this->staffCache)) {
            $this->staffCache[$user->id] = Staff::query()
                ->with('organization')
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->first();
        }

        return $this->staffCache[$user->id];
    }

    /** Viloyat darajasi — reyestrni to'liq ko'radi (geo scope qo'llanmaydi). */
    public function seesEverything(User $user): bool
    {
        return in_array($this->roleFor($user), [
            'yoshlar_admin',
            'yoshlar_hokim_orinbosari',
            'yoshlar_boshqarma',
            'sektor_boshqarma',
        ], true);
    }

    public function isViewerOnly(User $user): bool
    {
        return in_array($this->roleFor($user), self::VIEWER_ROLES, true);
    }
}
```

- [ ] **Step 4: Ruxsat xaritasini tekshiruvchi unit test**

`tests/Unit/Yoshlar/YoshlarPermissionMapTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Yoshlar;

use App\Domains\Yoshlar\Support\YoshlarAccess;
use PHPUnit\Framework\TestCase;

/**
 * Ruxsat xaritasi — spec 5-bo'limidagi matritsaning kod ko'rinishi.
 * Bu test o'zgarsa, spec ham o'zgarishi kerak (ataylab qattiq bog'langan).
 */
class YoshlarPermissionMapTest extends TestCase
{
    public function test_admin_cannot_verify(): void
    {
        $perms = $this->permissionsOf('yoshlar_admin');

        $this->assertContains('yoshlar.user.manage', $perms);
        $this->assertNotContains('yoshlar.youth.verify', $perms, 'Vakolatlar bo\'linishi buzildi');
    }

    public function test_hokim_orinbosari_cannot_reveal_pii(): void
    {
        $perms = $this->permissionsOf('yoshlar_hokim_orinbosari');

        $this->assertContains('yoshlar.view', $perms);
        $this->assertNotContains('yoshlar.pii.reveal', $perms);
        $this->assertNotContains('yoshlar.youth.update', $perms);
    }

    public function test_sektor_bolim_can_only_create(): void
    {
        $perms = $this->permissionsOf('sektor_bolim');

        $this->assertContains('yoshlar.youth.create', $perms);
        $this->assertNotContains('yoshlar.youth.update', $perms);
        $this->assertNotContains('yoshlar.pii.reveal', $perms);
    }

    public function test_no_wildcard_permission_exists(): void
    {
        foreach (YoshlarAccess::ROLES as $role) {
            $this->assertNotContains('*', $this->permissionsOf($role), "«{$role}» da wildcard bor");
        }
    }

    /** @return array<int, string> */
    private function permissionsOf(string $role): array
    {
        /** @var array<string, array<int, string>> $map */
        $map = (new \ReflectionClass(YoshlarAccess::class))->getConstant('PERMISSIONS');

        return $map[$role] ?? [];
    }
}
```

**Izoh:** `PERMISSIONS` — `private const`, lekin `ReflectionClass::getConstant()`
uni ham qaytaradi (PHP 8). Konstantani test uchun `public` qilish SHART EMAS —
xarita kod ichida qolgani ma'qul.

- [ ] **Step 5: Testni ishga tushirish**

Run: `C:/php84/php.exe artisan test --filter=YoshlarPermissionMapTest`
Expected: `OK (4 tests)`

- [ ] **Step 6: Commit**

```bash
git add app/Domains/Yoshlar database/seeders/SystemsSeeder.php tests/Unit/Yoshlar
git commit -m "feat(yoshlar): RBAC — 6 rol, ruxsat xaritasi, tizim ro'yxati"
```

---

## Task 3: Gvardiya, marshrutlar va minimal `/context`

**Files:**
- Create: `app/Domains/Yoshlar/Http/Middleware/EnsureYoshlar.php`
- Create: `app/Domains/Yoshlar/Http/Controllers/Api/ContextController.php`
- Create: `routes/api/yoshlar.php`
- Modify: `bootstrap/app.php` (alias), `routes/api.php` (require)
- Create: `tests/Feature/Yoshlar/YoshlarTestCase.php`
- Test: `tests/Feature/Yoshlar/YoshlarAccessTest.php`

**Interfaces:**
- Consumes: `YoshlarAccess` (Task 2).
- Produces:
  - `'yoshlar'` middleware aliasi
  - `GET /api/yoshlar/context` -> `{user, role, role_name, permissions, sees_everything, viewer_only, scope}`
  - `YoshlarTestCase::makeUser(string $role, ?string $orgId = null): User`,
    `makeOutsider(): User`, `makeOrganization(string $type, array $attrs = []): Organization`,
    `someDistrictId(): string`, `someMahallaId(?string $districtId = null): string`

- [ ] **Step 1: Middleware yozish**

`app/Domains/Yoshlar/Http/Middleware/EnsureYoshlar.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Http\Middleware;

use App\Domains\Yoshlar\Support\YoshlarAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * yoshlar domeni gvardiyasi: foydalanuvchi 'yoshlar' tizimida (biror rol bilan)
 * ekanini tekshiradi. Rolsiz -> 403. Auth-siz -> 401 (auth:sanctum).
 */
class EnsureYoshlar
{
    public function __construct(private readonly YoshlarAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $this->access->isYoshlar($user)) {
            abort(403, 'Bu tizimga ruxsat yo‘q.');
        }

        return $next($request);
    }
}
```

- [ ] **Step 2: `bootstrap/app.php` ga alias qo'shish**

`$middleware->alias([...])` ichiga, `'qurilish' => ...` qatoridan keyin:

```php
            'yoshlar' => \App\Domains\Yoshlar\Http\Middleware\EnsureYoshlar::class,
```

- [ ] **Step 3: Minimal `ContextController`**

`app/Domains/Yoshlar/Http/Controllers/Api/ContextController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Http\Controllers\Api;

use App\Domains\Yoshlar\Support\YoshlarAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/api/yoshlar/context` — SPA ishga tushganda BIR marta chaqiriladi:
 * foydalanuvchi, roli, ruxsatlari va ko'rish doirasi.
 * Spravochnik va nishonlar Task 11 da qo'shiladi.
 */
class ContextController extends Controller
{
    public function __invoke(Request $request, YoshlarAccess $access): JsonResponse
    {
        $user = $request->user();
        $staff = $access->staffFor($user);
        $org = $staff?->organization;

        return response()->json([
            'user' => ['id' => $user->id, 'name' => $user->name, 'login' => $user->login],
            'role' => $access->roleFor($user),
            'role_name' => YoshlarAccess::ROLE_NAMES[$access->roleFor($user)] ?? null,
            'permissions' => $access->permissionsFor($user),
            'sees_everything' => $access->seesEverything($user),
            'viewer_only' => $access->isViewerOnly($user),
            'scope' => [
                'org_id' => $staff?->org_id,
                'org_name' => $org?->name_lat,
                'org_type' => $org?->type,
                'district_id' => $org?->district_id,
                'sector_id' => $org?->sector_id,
                'can_patronage' => (bool) $staff?->can_patronage,
            ],
        ]);
    }
}
```

- [ ] **Step 4: Marshrut faylini yaratish va ulash**

`routes/api/yoshlar.php`:

```php
<?php

declare(strict_types=1);

use App\Domains\Yoshlar\Http\Controllers\Api\ContextController;
use Illuminate\Support\Facades\Route;

/*
 * YOSHLAR domeni API. auth:sanctum + yoshlar gvardiyasi.
 * Auth-siz -> 401; rolsiz -> 403. Rol ichidagi doira YoshlarScope da.
 */
Route::middleware(['auth:sanctum', 'yoshlar'])
    ->prefix('yoshlar')
    ->name('api.yoshlar.')
    ->group(function () {
        Route::get('/context', ContextController::class)->name('context');
    });
```

`routes/api.php` oxiriga (`require __DIR__.'/api/qurilish.php';` dan keyin):

```php
require __DIR__.'/api/yoshlar.php';
```

- [ ] **Step 5: `YoshlarTestCase` poydevorini yozish**

`tests/Feature/Yoshlar/YoshlarTestCase.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Yoshlar;

use App\Domains\Yoshlar\Models\Organization;
use App\Domains\Yoshlar\Models\Staff;
use App\Domains\Yoshlar\Support\YoshlarAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Yoshlar domeni feature testlari poydevori. Ko'p sxemali (auth/master/yoshlar).
 * DatabaseTransactions (RefreshDatabase EMAS — dev bazasi ustida ishlaymiz).
 */
abstract class YoshlarTestCase extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, string> */
    protected array $connectionsToTransact = ['pgsql', 'auth', 'master', 'yoshlar'];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function yoshlarSystemId(): string
    {
        $auth = DB::connection('auth');
        $id = $auth->table('systems')->where('code', YoshlarAccess::SYSTEM_CODE)->value('id');
        if ($id !== null) {
            return (string) $id;
        }

        $id = (string) Str::uuid();
        $auth->table('systems')->insert([
            'id' => $id, 'code' => YoshlarAccess::SYSTEM_CODE, 'name' => 'Ёшлар',
            'is_active' => true, 'sort_order' => 7, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** yoshlar foydalanuvchisi: auth.users + user_system_access + yoshlar.staff. */
    protected function makeUser(string $role, ?string $orgId = null, bool $canPatronage = false): User
    {
        $userId = (string) Str::uuid();
        $now = now();

        DB::connection('auth')->table('users')->insert([
            'id' => $userId, 'login' => 'ysh_'.substr($userId, 0, 8), 'password' => bcrypt('secret'),
            'name' => 'Sinov '.substr($userId, 0, 4), 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::connection('auth')->table('user_system_access')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $userId, 'system_id' => $this->yoshlarSystemId(),
            'role' => $role, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        if ($orgId !== null) {
            Staff::query()->create([
                'user_id' => $userId, 'org_id' => $orgId,
                'position' => 'Sinov lavozimi', 'can_patronage' => $canPatronage, 'is_active' => true,
            ]);
        }

        return User::on('auth')->findOrFail($userId);
    }

    /** Rolsiz (yoshlar bo'lmagan) user — 403 tekshiruvi uchun. */
    protected function makeOutsider(): User
    {
        $userId = (string) Str::uuid();
        DB::connection('auth')->table('users')->insert([
            'id' => $userId, 'login' => 'out_'.substr($userId, 0, 8), 'password' => bcrypt('secret'),
            'name' => 'Begona', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return User::on('auth')->findOrFail($userId);
    }

    /** @param array<string, mixed> $attrs */
    protected function makeOrganization(string $type, array $attrs = []): Organization
    {
        $name = 'TEST-'.Str::random(8);

        return Organization::query()->create(array_merge([
            'type' => $type,
            'name_cyr' => $name,
            'name_lat' => $name,
            'is_active' => true,
        ], $attrs));
    }

    protected function someDistrictId(): string
    {
        return (string) DB::connection('master')->table('districts')
            ->orderBy('sort_order')->value('id');
    }

    /** Boshqa tuman — IDOR testlari uchun. */
    protected function otherDistrictId(string $exceptId): string
    {
        return (string) DB::connection('master')->table('districts')
            ->where('id', '!=', $exceptId)->orderBy('sort_order')->value('id');
    }

    protected function someMahallaId(?string $districtId = null): string
    {
        $query = DB::connection('master')->table('mahallas');
        if ($districtId !== null) {
            $query->where('district_id', $districtId);
        }

        return (string) $query->orderBy('sort_order')->value('id');
    }
}
```

- [ ] **Step 6: Gvardiya testini yozish (avval FAIL bo'ladi)**

`tests/Feature/Yoshlar/YoshlarAccessTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Yoshlar;

use App\Domains\Yoshlar\Models\Organization;

/**
 * RBAC: yoshlar gvardiyasi (401/403), rol ruxsatlari, `/context` javobi.
 */
class YoshlarAccessTest extends YoshlarTestCase
{
    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/yoshlar/context')->assertStatus(401);
    }

    public function test_outsider_gets_403(): void
    {
        $this->actingAs($this->makeOutsider(), 'sanctum')
            ->getJson('/api/yoshlar/context')->assertStatus(403);
    }

    public function test_admin_sees_context(): void
    {
        $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->getJson('/api/yoshlar/context')
            ->assertOk()
            ->assertJsonPath('role', 'yoshlar_admin')
            ->assertJsonPath('sees_everything', true)
            ->assertJsonStructure([
                'user' => ['id', 'name', 'login'],
                'role', 'role_name', 'permissions', 'sees_everything', 'viewer_only',
                'scope' => ['org_id', 'org_name', 'org_type', 'district_id', 'sector_id'],
            ]);
    }

    public function test_district_role_scope_is_reported(): void
    {
        $districtId = $this->someDistrictId();
        $org = $this->makeOrganization(Organization::TYPE_TUMAN_YOSHLAR, ['district_id' => $districtId]);

        $this->actingAs($this->makeUser('yoshlar_bolim', $org->id), 'sanctum')
            ->getJson('/api/yoshlar/context')
            ->assertOk()
            ->assertJsonPath('scope.org_id', $org->id)
            ->assertJsonPath('scope.district_id', $districtId)
            ->assertJsonPath('scope.org_type', Organization::TYPE_TUMAN_YOSHLAR)
            ->assertJsonPath('sees_everything', false);
    }

    public function test_hokim_orinbosari_is_viewer_only(): void
    {
        $this->actingAs($this->makeUser('yoshlar_hokim_orinbosari'), 'sanctum')
            ->getJson('/api/yoshlar/context')
            ->assertOk()
            ->assertJsonPath('viewer_only', true)
            ->assertJsonMissing(['permissions' => ['yoshlar.pii.reveal']]);
    }
}
```

- [ ] **Step 7: Testni ishga tushirib FAIL ekanini ko'rish**

Run: `C:/php84/php.exe artisan test --filter=YoshlarAccessTest`
Expected: FAIL — `Route [api/yoshlar/context] not defined` yoki 404 (agar 4–5-qadamlar bajarilmagan bo'lsa). Barcha 3–6-qadamlar bajarilgan bo'lsa bu qadam PASS beradi; shunda keyingi qadamga o'ting.

- [ ] **Step 8: Testni yashil holatga keltirish**

Run: `C:/php84/php.exe artisan optimize:clear && C:/php84/php.exe artisan test --filter=YoshlarAccessTest`
Expected: `OK (5 tests)`

- [ ] **Step 9: Commit**

```bash
git add app/Domains/Yoshlar bootstrap/app.php routes/api.php routes/api/yoshlar.php tests/Feature/Yoshlar
git commit -m "feat(yoshlar): gvardiya, marshrutlar va /context poydevori"
```

---

## Task 4: Tashkilot yaxlitligi va seed

**Files:**
- Create: `app/Domains/Yoshlar/Services/OrganizationService.php`
- Create: `app/Domains/Yoshlar/Database/Seeders/YoshlarReferenceSeeder.php`
- Create: `app/Domains/Yoshlar/Support/Translit.php`
- Test: `tests/Feature/Yoshlar/YoshlarOrganizationTest.php`

**Interfaces:**
- Consumes: `Organization`, `Sector` (Task 2), `master.districts`.
- Produces:
  - `OrganizationService::create(array $data): Organization` — yaxlitlik qoidalari bilan
  - `OrganizationService::update(Organization $org, array $data): Organization`
  - `Translit::toCyr(string $lat): string`, `Translit::normalize(string $text): string`
  - Seeder: 9 sektor + 1 viloyat yoshlar boshqarmasi + 13 tuman yoshlar bo'limi

- [ ] **Step 1: Yaxlitlik testini yozish (FAIL)**

`tests/Feature/Yoshlar/YoshlarOrganizationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Yoshlar;

use App\Domains\Yoshlar\Models\Organization;
use App\Domains\Yoshlar\Models\Sector;
use App\Domains\Yoshlar\Services\OrganizationService;
use Illuminate\Validation\ValidationException;

/**
 * Tashkilot yaxlitligi: daraja/tuman/sektor mosligi. Bu qoidalar buzilsa
 * scope noto'g'ri ishlaydi — shuning uchun servis darajasida qattiq tekshiriladi.
 */
class YoshlarOrganizationTest extends YoshlarTestCase
{
    public function test_district_organization_requires_district_id(): void
    {
        $this->expectException(ValidationException::class);

        app(OrganizationService::class)->create([
            'type' => Organization::TYPE_TUMAN_YOSHLAR,
            'name_lat' => 'Tuman bolimi',
            'parent_id' => $this->makeOrganization(Organization::TYPE_VILOYAT_YOSHLAR)->id,
        ]);
    }

    public function test_district_organization_requires_parent(): void
    {
        $this->expectException(ValidationException::class);

        app(OrganizationService::class)->create([
            'type' => Organization::TYPE_TUMAN_YOSHLAR,
            'name_lat' => 'Tuman bolimi',
            'district_id' => $this->someDistrictId(),
        ]);
    }

    public function test_sector_organization_requires_sector_id(): void
    {
        $this->expectException(ValidationException::class);

        app(OrganizationService::class)->create([
            'type' => Organization::TYPE_VILOYAT_SEKTOR,
            'name_lat' => 'Viloyat bandlik boshqarmasi',
        ]);
    }

    public function test_child_sector_must_match_parent_sector(): void
    {
        $bandlik = Sector::query()->create(['code' => 'test_bandlik_'.uniqid(), 'name_cyr' => 'Бандлик', 'name_lat' => 'Bandlik']);
        $talim = Sector::query()->create(['code' => 'test_talim_'.uniqid(), 'name_cyr' => 'Таълим', 'name_lat' => 'Talim']);

        $parent = $this->makeOrganization(Organization::TYPE_VILOYAT_SEKTOR, ['sector_id' => $bandlik->id]);

        $this->expectException(ValidationException::class);

        app(OrganizationService::class)->create([
            'type' => Organization::TYPE_TUMAN_SEKTOR,
            'name_lat' => 'Tuman talim bolimi',
            'district_id' => $this->someDistrictId(),
            'parent_id' => $parent->id,
            'sector_id' => $talim->id,   // otasi bandlik — mos emas
        ]);
    }

    public function test_valid_district_sector_organization_is_created(): void
    {
        $sector = Sector::query()->create(['code' => 'test_s_'.uniqid(), 'name_cyr' => 'Синов', 'name_lat' => 'Sinov']);
        $parent = $this->makeOrganization(Organization::TYPE_VILOYAT_SEKTOR, ['sector_id' => $sector->id]);
        $districtId = $this->someDistrictId();

        $org = app(OrganizationService::class)->create([
            'type' => Organization::TYPE_TUMAN_SEKTOR,
            'name_lat' => 'Tuman sinov bolimi',
            'district_id' => $districtId,
            'parent_id' => $parent->id,
            'sector_id' => $sector->id,
        ]);

        $this->assertSame($districtId, $org->district_id);
        $this->assertSame('Туман синов болими', $org->name_cyr, 'name_cyr avtomatik to‘ldirilmadi');
    }
}
```

- [ ] **Step 2: Testni ishga tushirib FAIL ko'rish**

Run: `C:/php84/php.exe artisan test --filter=YoshlarOrganizationTest`
Expected: FAIL — `Class "App\Domains\Yoshlar\Services\OrganizationService" not found`

- [ ] **Step 3: `Translit` yozish**

`app/Domains/Yoshlar/Support/Translit.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Support;

/**
 * Lotin -> kirill (ko'rsatish uchun) va normalizatsiya (qidiruv uchun).
 *
 * NEGA FAQAT BIR YO'NALISH: o'zbek lotin->kirill deyarli deterministik
 * (sh->ш, o'->ў), teskarisi esa emas (ц -> ts/s). Shuning uchun lotin
 * YAGONA MANBA, kirill undan hosil qilinadi.
 *
 * Tartib muhim: ko'p harfli birikmalar (sh, ch, yo) bir harflilardan OLDIN.
 */
class Translit
{
    /** @var array<string, string> */
    private const LAT_TO_CYR = [
        'shch' => 'щ', 'Shch' => 'Щ', 'SHCH' => 'Щ',
        'yo' => 'ё', 'Yo' => 'Ё', 'YO' => 'Ё',
        'yu' => 'ю', 'Yu' => 'Ю', 'YU' => 'Ю',
        'ya' => 'я', 'Ya' => 'Я', 'YA' => 'Я',
        'ye' => 'е', 'Ye' => 'Е', 'YE' => 'Е',
        'ch' => 'ч', 'Ch' => 'Ч', 'CH' => 'Ч',
        'sh' => 'ш', 'Sh' => 'Ш', 'SH' => 'Ш',
        'ng' => 'нг', 'Ng' => 'Нг',
        'o‘' => 'ў', 'O‘' => 'Ў', "o'" => 'ў', "O'" => 'Ў',
        'g‘' => 'ғ', 'G‘' => 'Ғ', "g'" => 'ғ', "G'" => 'Ғ',
        'ts' => 'ц', 'Ts' => 'Ц',
        'a' => 'а', 'b' => 'б', 'd' => 'д', 'e' => 'е', 'f' => 'ф', 'g' => 'г',
        'h' => 'ҳ', 'i' => 'и', 'j' => 'ж', 'k' => 'к', 'l' => 'л', 'm' => 'м',
        'n' => 'н', 'o' => 'о', 'p' => 'п', 'q' => 'қ', 'r' => 'р', 's' => 'с',
        't' => 'т', 'u' => 'у', 'v' => 'в', 'x' => 'х', 'y' => 'й', 'z' => 'з',
        'A' => 'А', 'B' => 'Б', 'D' => 'Д', 'E' => 'Е', 'F' => 'Ф', 'G' => 'Г',
        'H' => 'Ҳ', 'I' => 'И', 'J' => 'Ж', 'K' => 'К', 'L' => 'Л', 'M' => 'М',
        'N' => 'Н', 'O' => 'О', 'P' => 'П', 'Q' => 'Қ', 'R' => 'Р', 'S' => 'С',
        'T' => 'Т', 'U' => 'У', 'V' => 'В', 'X' => 'Х', 'Y' => 'Й', 'Z' => 'З',
        'ʼ' => 'ъ', '‘' => 'ъ', "'" => 'ъ',
    ];

    /** @var array<string, string> Kirill -> lotin: faqat qidiruvni normallashtirish uchun. */
    private const CYR_TO_LAT = [
        'ё' => 'yo', 'ю' => 'yu', 'я' => 'ya', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch',
        'ў' => "o'", 'ғ' => "g'", 'қ' => 'q', 'ҳ' => 'h', 'ц' => 'ts', 'ъ' => "'", 'ь' => '',
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e',
        'ж' => 'j', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l',
        'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's',
        'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'x', 'ы' => 'i', 'э' => 'e',
    ];

    public static function toCyr(string $lat): string
    {
        return strtr($lat, self::LAT_TO_CYR);
    }

    public static function toLat(string $cyr): string
    {
        return strtr(mb_strtolower($cyr), self::CYR_TO_LAT);
    }

    /**
     * Qidiruv kaliti: kirill bo'lsa lotinga o'giriladi, apostrof/qo'sh bo'shliq
     * tozalanadi, UPPER qilinadi. `youth.full_name_norm` shu shaklda saqlanadi.
     */
    public static function normalize(string $text): string
    {
        if (preg_match('/\p{Cyrillic}/u', $text) === 1) {
            $text = self::toLat($text);
        }

        $text = str_replace(['‘', '’', 'ʼ', "'"], '', $text);
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? '';

        return mb_strtoupper($text);
    }
}
```

- [ ] **Step 4: `OrganizationService` yozish**

`app/Domains/Yoshlar/Services/OrganizationService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Services;

use App\Domains\Yoshlar\Models\Organization;
use App\Domains\Yoshlar\Support\Translit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tashkilot yaxlitligi. Bu qoidalar DB CHECK emas, servisda — chunki
 * kelajakda yangi tur qo'shilganda migratsiya emas, kod o'zgaradi.
 *
 * MUHIM: qoidalar buzilsa YoshlarScope noto'g'ri ishlaydi (tuman tashkiloti
 * district_id siz -> foydalanuvchi hech narsa ko'rmaydi yoki hammasini ko'radi).
 */
class OrganizationService
{
    /** @param array<string, mixed> $data */
    public function create(array $data): Organization
    {
        $data = $this->validated($data);

        return Organization::query()->create($data);
    }

    /** @param array<string, mixed> $data */
    public function update(Organization $org, array $data): Organization
    {
        $merged = $this->validated(array_merge($org->only([
            'type', 'parent_id', 'district_id', 'sector_id', 'name_cyr', 'name_lat', 'short_name',
        ]), $data));

        $org->update($merged);

        return $org->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validated(array $data): array
    {
        $type = (string) ($data['type'] ?? '');

        if (! in_array($type, Organization::TYPES, true)) {
            throw ValidationException::withMessages([
                'type' => "«{$type}» — noto‘g‘ri tashkilot turi.",
            ]);
        }

        $isDistrict = in_array($type, [Organization::TYPE_TUMAN_YOSHLAR, Organization::TYPE_TUMAN_SEKTOR], true);
        $isSector = in_array($type, [Organization::TYPE_VILOYAT_SEKTOR, Organization::TYPE_TUMAN_SEKTOR], true);

        if ($isDistrict && empty($data['district_id'])) {
            throw ValidationException::withMessages([
                'district_id' => 'Tuman darajasidagi tashkilot uchun tuman majburiy.',
            ]);
        }

        if ($isDistrict && empty($data['parent_id'])) {
            throw ValidationException::withMessages([
                'parent_id' => 'Tuman tashkiloti viloyat tashkilotiga bog‘lanishi shart.',
            ]);
        }

        if ($isSector && empty($data['sector_id'])) {
            throw ValidationException::withMessages([
                'sector_id' => 'Sektoral tashkilot uchun sektor majburiy.',
            ]);
        }

        if (! $isDistrict) {
            $data['district_id'] = null;
        }

        if (! $isSector) {
            $data['sector_id'] = null;
        }

        if (! empty($data['district_id']) && ! $this->districtExists((string) $data['district_id'])) {
            throw ValidationException::withMessages(['district_id' => 'Bunday tuman topilmadi.']);
        }

        if (! empty($data['parent_id'])) {
            $this->assertParentMatches($type, (string) $data['parent_id'], $data['sector_id'] ?? null);
        }

        $data['name_lat'] = trim((string) ($data['name_lat'] ?? ''));
        if ($data['name_lat'] === '') {
            throw ValidationException::withMessages(['name_lat' => 'Nom bo‘sh bo‘lishi mumkin emas.']);
        }

        // Kirill nomi berilmasa — lotin nomidan hosil qilinadi (keyin tahrirlanadi).
        $data['name_cyr'] = trim((string) ($data['name_cyr'] ?? '')) ?: Translit::toCyr($data['name_lat']);

        return $data;
    }

    private function districtExists(string $districtId): bool
    {
        return DB::connection('master')->table('districts')->where('id', $districtId)->exists();
    }

    private function assertParentMatches(string $type, string $parentId, ?string $sectorId): void
    {
        $parent = Organization::query()->find($parentId);

        if ($parent === null) {
            throw ValidationException::withMessages(['parent_id' => 'Yuqori tashkilot topilmadi.']);
        }

        $expected = $type === Organization::TYPE_TUMAN_SEKTOR
            ? Organization::TYPE_VILOYAT_SEKTOR
            : Organization::TYPE_VILOYAT_YOSHLAR;

        if ($parent->type !== $expected) {
            throw ValidationException::withMessages([
                'parent_id' => "Yuqori tashkilot turi «{$expected}» bo‘lishi kerak.",
            ]);
        }

        if ($type === Organization::TYPE_TUMAN_SEKTOR && $parent->sector_id !== $sectorId) {
            throw ValidationException::withMessages([
                'sector_id' => 'Tuman bo‘limining sektori yuqori boshqarma sektoriga mos emas.',
            ]);
        }
    }
}
```

- [ ] **Step 5: Testni yashil holatga keltirish**

Run: `C:/php84/php.exe artisan test --filter=YoshlarOrganizationTest`
Expected: `OK (5 tests)`

- [ ] **Step 6: Seeder yozish**

`app/Domains/Yoshlar/Database/Seeders/YoshlarReferenceSeeder.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Database\Seeders;

use App\Domains\Yoshlar\Models\Organization;
use App\Domains\Yoshlar\Models\Sector;
use App\Domains\Yoshlar\Support\Translit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Spravochnik urug'i: 9 sektor + yoshlar vertikali (1 viloyat + 13 tuman).
 * Idempotent: `code` / (type + district_id) bo'yicha mavjud bo'lsa o'tkazib yuboriladi.
 */
class YoshlarReferenceSeeder extends Seeder
{
    /** @var array<int, array{code: string, cyr: string, lat: string}> */
    private const SECTORS = [
        ['code' => 'bandlik', 'cyr' => 'Бандлик', 'lat' => 'Bandlik'],
        ['code' => 'talim', 'cyr' => 'Халқ таълими', 'lat' => 'Xalq ta‘limi'],
        ['code' => 'oliy_talim', 'cyr' => 'Олий таълим', 'lat' => 'Oliy ta‘lim'],
        ['code' => 'sogliq', 'cyr' => 'Соғлиқни сақлаш', 'lat' => 'Sog‘liqni saqlash'],
        ['code' => 'soliq', 'cyr' => 'Солиқ', 'lat' => 'Soliq'],
        ['code' => 'iib', 'cyr' => 'ИИБ (профилактика)', 'lat' => 'IIB (profilaktika)'],
        ['code' => 'madaniyat', 'cyr' => 'Маданият', 'lat' => 'Madaniyat'],
        ['code' => 'sport', 'cyr' => 'Спорт', 'lat' => 'Sport'],
        ['code' => 'mahalla_oila', 'cyr' => 'Маҳалла ва оила', 'lat' => 'Mahalla va oila'],
    ];

    public function run(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        foreach (self::SECTORS as $i => $sector) {
            Sector::query()->firstOrCreate(
                ['code' => $sector['code']],
                ['name_cyr' => $sector['cyr'], 'name_lat' => $sector['lat'], 'sort_order' => $i + 1],
            );
        }

        $viloyat = Organization::query()->firstOrCreate(
            ['type' => Organization::TYPE_VILOYAT_YOSHLAR],
            [
                'name_cyr' => 'Хоразм вилояти ёшлар ишлари бошқармаси',
                'name_lat' => 'Xorazm viloyati yoshlar ishlari boshqarmasi',
                'short_name' => 'Viloyat yoshlar boshqarmasi',
                'is_active' => true,
            ],
        );

        $districts = DB::connection('master')->table('districts')
            ->orderBy('sort_order')->get(['id', 'name_cyr', 'name_lat']);

        foreach ($districts as $district) {
            $lat = ((string) $district->name_lat).' tuman yoshlar bo‘limi';

            Organization::query()->firstOrCreate(
                ['type' => Organization::TYPE_TUMAN_YOSHLAR, 'district_id' => $district->id],
                [
                    'parent_id' => $viloyat->id,
                    'name_cyr' => Translit::toCyr($lat),
                    'name_lat' => $lat,
                    'is_active' => true,
                ],
            );
        }
    }
}
```

- [ ] **Step 7: Seeder'ni ishga tushirish va natijani tekshirish**

Run:
```bash
C:/php84/php.exe artisan db:seed --class="App\Domains\Yoshlar\Database\Seeders\YoshlarReferenceSeeder"
C:/php84/php.exe artisan tinker --execute="echo App\Domains\Yoshlar\Models\Sector::count().' sektor, '.App\Domains\Yoshlar\Models\Organization::count().' tashkilot';"
```
Expected: `9 sektor, 14 tashkilot`

Qayta ishga tushiring — son o'zgarmasligi kerak (idempotent).

- [ ] **Step 8: Commit**

```bash
git add app/Domains/Yoshlar tests/Feature/Yoshlar/YoshlarOrganizationTest.php
git commit -m "feat(yoshlar): tashkilot yaxlitligi, translit va spravochnik seed"
```

---

## Task 5: `YoshlarScope` — ikki o'lchovli fail-closed doira

**Files:**
- Create: `app/Domains/Yoshlar/Support/YoshlarScope.php`
- Test: `tests/Feature/Yoshlar/YoshlarScopeTest.php`

**Interfaces:**
- Consumes: `YoshlarAccess::staffFor()`, `Organization`.
- Produces:
  - `YoshlarScope::districtIds(User): ?array` — `null` = cheklovsiz
  - `YoshlarScope::orgIds(User): ?array` — `null` = cheklovsiz
  - `YoshlarScope::applyYouth(Builder $q, User $u): Builder`
  - `YoshlarScope::canTouchDistrict(User $u, ?string $districtId): bool`

- [ ] **Step 1: Scope testini yozish (FAIL)**

`tests/Feature/Yoshlar/YoshlarScopeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Yoshlar;

use App\Domains\Yoshlar\Models\Organization;
use App\Domains\Yoshlar\Support\YoshlarScope;

/**
 * Ko'rish doirasi. Eng muhim tekshiruv — FAIL-CLOSED: profil topilmasa
 * foydalanuvchi HECH NARSA ko'rmaydi (hammasini emas).
 */
class YoshlarScopeTest extends YoshlarTestCase
{
    public function test_staffless_role_sees_nothing(): void
    {
        $user = $this->makeUser('yoshlar_bolim');   // staff yozuvi YO'Q

        $this->assertSame([], app(YoshlarScope::class)->districtIds($user));
    }

    public function test_province_roles_are_unrestricted(): void
    {
        foreach (['yoshlar_admin', 'yoshlar_hokim_orinbosari', 'yoshlar_boshqarma'] as $role) {
            $this->assertNull(
                app(YoshlarScope::class)->districtIds($this->makeUser($role)),
                "«{$role}» viloyat darajasida cheklanmasligi kerak",
            );
        }
    }

    public function test_district_role_is_limited_to_own_district(): void
    {
        $districtId = $this->someDistrictId();
        $org = $this->makeOrganization(Organization::TYPE_TUMAN_YOSHLAR, ['district_id' => $districtId]);
        $user = $this->makeUser('yoshlar_bolim', $org->id);

        $this->assertSame([$districtId], app(YoshlarScope::class)->districtIds($user));
        $this->assertTrue(app(YoshlarScope::class)->canTouchDistrict($user, $districtId));
        $this->assertFalse(
            app(YoshlarScope::class)->canTouchDistrict($user, $this->otherDistrictId($districtId)),
        );
    }

    public function test_sector_boshqarma_sees_all_districts_but_own_org_subtree(): void
    {
        $parent = $this->makeOrganization(Organization::TYPE_VILOYAT_SEKTOR);
        $child = $this->makeOrganization(Organization::TYPE_TUMAN_SEKTOR, [
            'parent_id' => $parent->id,
            'district_id' => $this->someDistrictId(),
        ]);
        $user = $this->makeUser('sektor_boshqarma', $parent->id);

        // Reyestr geo bo'yicha cheklanmaydi — yosh sektorga tegishli emas.
        $this->assertNull(app(YoshlarScope::class)->districtIds($user));

        $orgIds = app(YoshlarScope::class)->orgIds($user);
        $this->assertContains($parent->id, $orgIds);
        $this->assertContains($child->id, $orgIds);
    }

    public function test_inactive_staff_sees_nothing(): void
    {
        $org = $this->makeOrganization(Organization::TYPE_TUMAN_SEKTOR, [
            'district_id' => $this->someDistrictId(),
        ]);
        $user = $this->makeUser('sektor_bolim', $org->id);

        \App\Domains\Yoshlar\Models\Staff::query()
            ->where('user_id', $user->id)->update(['is_active' => false]);

        $this->assertSame([], app(YoshlarScope::class)->districtIds($user));
    }
}
```

- [ ] **Step 2: Testni ishga tushirib FAIL ko'rish**

Run: `C:/php84/php.exe artisan test --filter=YoshlarScopeTest`
Expected: FAIL — `Class "App\Domains\Yoshlar\Support\YoshlarScope" not found`

- [ ] **Step 3: `YoshlarScope` yozish**

`app/Domains/Yoshlar/Support/YoshlarScope.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Support;

use App\Domains\Yoshlar\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ko'rish doirasi — IKKI O'LCHOV:
 *   GEO (district) — reyestr uchun: yosh mahallaga tegishli, sektorga emas.
 *   ORG (subtree)  — topshiriq/case uchun (F2+).
 *
 * FAIL-CLOSED: faol `staff` yozuvi topilmasa BO'SH massiv qaytadi (`[]`),
 * `null` EMAS. `null` — «cheklovsiz» degani; noto'g'ri sozlangan hisob
 * hammasini ko'rib qolmasligi uchun farq ataylab qilingan.
 */
class YoshlarScope
{
    public function __construct(private readonly YoshlarAccess $access) {}

    /** @return array<int, string>|null null = butun viloyat */
    public function districtIds(User $user): ?array
    {
        $role = $this->access->roleFor($user);

        if (in_array($role, ['yoshlar_admin', 'yoshlar_hokim_orinbosari', 'yoshlar_boshqarma', 'sektor_boshqarma'], true)) {
            return null;
        }

        $districtId = $this->access->staffFor($user)?->organization?->district_id;

        return $districtId === null ? [] : [$districtId];
    }

    /** @return array<int, string>|null null = barcha tashkilot */
    public function orgIds(User $user): ?array
    {
        $role = $this->access->roleFor($user);

        if (in_array($role, ['yoshlar_admin', 'yoshlar_hokim_orinbosari', 'yoshlar_boshqarma'], true)) {
            return null;
        }

        $staff = $this->access->staffFor($user);
        if ($staff === null) {
            return [];
        }

        $ids = [$staff->org_id];

        if ($role === 'sektor_boshqarma') {
            $ids = array_merge($ids, Organization::query()
                ->where('parent_id', $staff->org_id)
                ->pluck('id')->all());
        }

        return $ids;
    }

    /**
     * Reyestr so'roviga geo doirani qo'llaydi.
     *
     * @param  Builder<\App\Domains\Yoshlar\Models\Youth>  $query
     * @return Builder<\App\Domains\Yoshlar\Models\Youth>
     */
    public function applyYouth(Builder $query, User $user): Builder
    {
        $districts = $this->districtIds($user);

        if ($districts === null) {
            return $query;
        }

        if ($districts === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('district_id', $districts);
    }

    /** Bitta yozuv tekshiruvi: shu tumanga tegishli amal qila oladimi. */
    public function canTouchDistrict(User $user, ?string $districtId): bool
    {
        $districts = $this->districtIds($user);

        if ($districts === null) {
            return true;
        }

        return $districtId !== null && in_array($districtId, $districts, true);
    }
}
```

- [ ] **Step 4: Testni yashil holatga keltirish**

Run: `C:/php84/php.exe artisan test --filter=YoshlarScopeTest`
Expected: `OK (5 tests)`

- [ ] **Step 5: Commit**

```bash
git add app/Domains/Yoshlar/Support/YoshlarScope.php tests/Feature/Yoshlar/YoshlarScopeTest.php
git commit -m "feat(yoshlar): ikki o'lchovli fail-closed scope"
```

---

## Task 6: `Youth` modeli va PII himoyasi

**Files:**
- Create: `app/Domains/Yoshlar/Models/Youth.php`
- Test: `tests/Feature/Yoshlar/YoshlarYouthModelTest.php`

**Interfaces:**
- Consumes: `Translit::normalize()` (Task 4).
- Produces:
  - `Youth` modeli: `$hidden = ['pinfl','pinfl_hash','passport_series','passport_number']`
  - `Youth::hashPinfl(string $plain): string` — deterministik HMAC-SHA256
  - `Youth::scopeAgeBetween(Builder, int $min, int $max): Builder`
  - `Youth::getAgeAttribute(): int` (appends: `age`, `full_name`)
  - Konstanta: `Youth::EDUCATION_STATUSES`, `::EMPLOYMENT_STATUSES`, `::REGISTRY_STATUSES`, `::VERIFICATION_STATUSES`

- [ ] **Step 1: Model testini yozish (FAIL)**

`tests/Feature/Yoshlar/YoshlarYouthModelTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Yoshlar;

use App\Domains\Yoshlar\Models\Youth;
use Illuminate\Database\QueryException;

/**
 * PII: shifrlash, yashirish, dublikat to'sig'i va yosh hisoblanishi.
 */
class YoshlarYouthModelTest extends YoshlarTestCase
{
    public function test_pinfl_is_hidden_from_serialization(): void
    {
        $youth = $this->makeYouth(['pinfl' => '31234567890123']);

        $array = $youth->toArray();

        $this->assertArrayNotHasKey('pinfl', $array);
        $this->assertArrayNotHasKey('pinfl_hash', $array);
        $this->assertArrayNotHasKey('passport_series', $array);
        $this->assertArrayNotHasKey('passport_number', $array);
    }

    public function test_pinfl_is_stored_encrypted_but_readable_via_model(): void
    {
        $youth = $this->makeYouth(['pinfl' => '31234567890123']);

        $raw = \Illuminate\Support\Facades\DB::connection('yoshlar')
            ->table('youth')->where('id', $youth->id)->value('pinfl');

        $this->assertNotSame('31234567890123', $raw, 'PINFL ochiq matnda saqlanibdi');
        $this->assertSame('31234567890123', $youth->fresh()->pinfl);
    }

    public function test_duplicate_pinfl_is_rejected(): void
    {
        $this->makeYouth(['pinfl' => '31234567890999']);

        $this->expectException(QueryException::class);

        $this->makeYouth(['pinfl' => '31234567890999']);
    }

    public function test_full_name_norm_is_generated_and_searchable_in_cyrillic(): void
    {
        $youth = $this->makeYouth(['last_name' => 'Sharipov', 'first_name' => 'Оtabek']);

        $this->assertStringContainsString('SHARIPOV', $youth->full_name_norm);

        // Kirillcha qidiruv lotin normga o'giriladi.
        $key = \App\Domains\Yoshlar\Support\Translit::normalize('Шарипов');
        $this->assertStringContainsString($key, $youth->full_name_norm);
    }

    public function test_age_between_scope_respects_boundaries(): void
    {
        $young = $this->makeYouth(['birth_date' => now()->subYears(14)->toDateString()]);
        $old = $this->makeYouth(['birth_date' => now()->subYears(31)->subDay()->toDateString()]);

        $ids = Youth::query()->ageBetween(14, 30)->pluck('id')->all();

        $this->assertContains($young->id, $ids);
        $this->assertNotContains($old->id, $ids);
    }

    /** @param array<string, mixed> $attrs */
    private function makeYouth(array $attrs = []): Youth
    {
        $districtId = $this->someDistrictId();

        return Youth::query()->create(array_merge([
            'last_name' => 'Testov',
            'first_name' => 'Test',
            'birth_date' => now()->subYears(20)->toDateString(),
            'gender' => 'erkak',
            'district_id' => $districtId,
            'mahalla_id' => $this->someMahallaId($districtId),
        ], $attrs));
    }
}
```

- [ ] **Step 2: Testni ishga tushirib FAIL ko'rish**

Run: `C:/php84/php.exe artisan test --filter=YoshlarYouthModelTest`
Expected: FAIL — `Class "App\Domains\Yoshlar\Models\Youth" not found`

- [ ] **Step 3: `Youth` modelini yozish**

`app/Domains/Yoshlar/Models/Youth.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Models;

use App\Domains\Yoshlar\Support\Translit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Yoshlar reyestri — barcha modul suyanadigan jadval.
 *
 * PII: `pinfl`/`passport_*` shifrlangan cast bilan saqlanadi VA `$hidden` —
 * `encrypted` cast ochiq matnni qaytargani uchun, `$hidden` bo'lmasa ro'yxat
 * javobida ham PINFL brauzerga ketardi. Bitta yozuvni ochish kerak bo'lganda
 * kontrollerda `makeVisible()` ishlatiladi (PiiGuard orqali, jurnal bilan).
 */
class Youth extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $connection = 'yoshlar';

    protected $table = 'youth';

    /** @var array<int, string> */
    public const EDUCATION_STATUSES = ['maktab', 'kollej', 'otm', 'bitiruvchi', 'oqimaydi'];

    /** @var array<int, string> */
    public const EMPLOYMENT_STATUSES = ['band', 'band_emas', 'oqiydi', 'tadbirkor', 'migratsiya'];

    /** @var array<int, string> */
    public const REGISTRY_STATUSES = ['active', 'archived_age', 'moved', 'deceased'];

    /** @var array<int, string> */
    public const VERIFICATION_STATUSES = ['pending', 'verified', 'rejected'];

    public const MIN_AGE = 14;

    public const MAX_AGE = 30;

    protected $fillable = [
        'last_name', 'first_name', 'middle_name', 'birth_date', 'gender',
        'district_id', 'mahalla_id', 'address', 'phone',
        'pinfl', 'passport_series', 'passport_number',
        'education_status', 'education_place', 'employment_status', 'workplace',
        'is_neet', 'is_graduate_unemployed', 'in_patronage', 'has_open_case',
        'in_youth_book', 'is_entrepreneur',
        'registry_status', 'verification_status', 'verified_by', 'verified_at', 'reject_reason',
        'created_by_org_id', 'created_by', 'updated_by',
    ];

    /** @var array<int, string> */
    protected $hidden = ['pinfl', 'pinfl_hash', 'passport_series', 'passport_number'];

    /** @var array<int, string> */
    protected $appends = ['age', 'full_name'];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'verified_at' => 'datetime',
            'pinfl' => 'encrypted',
            'passport_series' => 'encrypted',
            'passport_number' => 'encrypted',
            'is_neet' => 'boolean',
            'is_graduate_unemployed' => 'boolean',
            'in_patronage' => 'boolean',
            'has_open_case' => 'boolean',
            'in_youth_book' => 'boolean',
            'is_entrepreneur' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Youth $youth): void {
            // PINFL shifrlangani uchun (har safar boshqa shifrmatn) DB unique
            // indeks uni ushlay olmaydi — deterministik HMAC hash saqlaymiz.
            $plain = $youth->pinfl;
            $youth->attributes['pinfl_hash'] = ($plain !== null && $plain !== '')
                ? self::hashPinfl((string) $plain)
                : null;

            $youth->attributes['full_name_norm'] = Translit::normalize(
                trim($youth->last_name.' '.$youth->first_name.' '.($youth->middle_name ?? '')),
            );
        });
    }

    /** Deterministik HMAC-SHA256 — unikallik tekshiruvi uchun (HR naqshi). */
    public static function hashPinfl(string $plain): string
    {
        return hash_hmac('sha256', trim($plain), (string) config('app.key'));
    }

    /**
     * Yosh oralig'i bo'yicha filtr.
     *
     * `age()` PostgreSQL'da STABLE (immutable emas) — generated ustunga ham,
     * indeksga ham yaramaydi. Shuning uchun filtr SANA oralig'iga aylantiriladi:
     * `birth_date` indeksi to'liq ishlaydi.
     *
     * @param  Builder<Youth>  $query
     * @return Builder<Youth>
     */
    public function scopeAgeBetween(Builder $query, int $min, int $max): Builder
    {
        $today = CarbonImmutable::today();

        return $query
            ->where('birth_date', '<=', $today->subYears($min)->toDateString())
            ->where('birth_date', '>', $today->subYears($max + 1)->toDateString());
    }

    /** @param Builder<Youth> $query */
    public function scopeVisibleInRegistry(Builder $query): Builder
    {
        return $query->where('verification_status', 'verified');
    }

    public function getAgeAttribute(): int
    {
        return (int) $this->birth_date?->diffInYears(now());
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->last_name.' '.$this->first_name.' '.($this->middle_name ?? ''));
    }
}
```

- [ ] **Step 4: Testni yashil holatga keltirish**

Run: `C:/php84/php.exe artisan test --filter=YoshlarYouthModelTest`
Expected: `OK (5 tests)`

- [ ] **Step 5: Commit**

```bash
git add app/Domains/Yoshlar/Models/Youth.php tests/Feature/Yoshlar/YoshlarYouthModelTest.php
git commit -m "feat(yoshlar): Youth modeli — shifrlangan PII, HMAC hash, yosh filtri"
```

---

## Task 7: Reyestr API — ro'yxat, filtr, qidiruv, CRUD

**Files:**
- Create: `app/Domains/Yoshlar/Services/YouthService.php`
- Create: `app/Domains/Yoshlar/Http/Controllers/Api/YouthController.php`
- Create: `app/Domains/Yoshlar/Http/Requests/YouthStoreRequest.php`, `YouthUpdateRequest.php`
- Modify: `routes/api/yoshlar.php`
- Test: `tests/Feature/Yoshlar/YoshlarYouthApiTest.php`

**Interfaces:**
- Consumes: `Youth` (Task 6), `YoshlarScope` (Task 5), `YoshlarAccess` (Task 2).
- Produces:
  - `GET /api/yoshlar/youth` — sahifalangan ro'yxat (`data`, `meta`)
  - `POST|GET|PATCH|DELETE /api/yoshlar/youth[/{youth}]`
  - `YouthService::create(User, array): Youth`, `update(User, Youth, array): Youth`,
    `possibleDuplicates(array): int`

- [ ] **Step 1: API testini yozish (FAIL)**

`tests/Feature/Yoshlar/YoshlarYouthApiTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Yoshlar;

use App\Domains\Yoshlar\Models\Organization;
use App\Domains\Yoshlar\Models\Youth;
use App\Models\User;

/**
 * Reyestr API: doira (IDOR), yozish huquqi, filtr va PII sizmasligi.
 */
class YoshlarYouthApiTest extends YoshlarTestCase
{
    public function test_district_role_does_not_see_other_district_youth(): void
    {
        $ownDistrict = $this->someDistrictId();
        $otherDistrict = $this->otherDistrictId($ownDistrict);

        $mine = $this->makeYouth($ownDistrict);
        $foreign = $this->makeYouth($otherDistrict);

        $ids = $this->actingAs($this->districtUser('yoshlar_bolim', $ownDistrict), 'sanctum')
            ->getJson('/api/yoshlar/youth?per_page=200')
            ->assertOk()
            ->json('data.*.id');

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($foreign->id, $ids);
    }

    public function test_district_role_cannot_open_foreign_youth(): void
    {
        $ownDistrict = $this->someDistrictId();
        $foreign = $this->makeYouth($this->otherDistrictId($ownDistrict));

        $this->actingAs($this->districtUser('yoshlar_bolim', $ownDistrict), 'sanctum')
            ->getJson("/api/yoshlar/youth/{$foreign->id}")
            ->assertStatus(404);
    }

    public function test_staffless_role_sees_empty_registry(): void
    {
        $this->makeYouth($this->someDistrictId());

        $this->actingAs($this->makeUser('yoshlar_bolim'), 'sanctum')
            ->getJson('/api/yoshlar/youth')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_pii_never_appears_in_list_response(): void
    {
        $district = $this->someDistrictId();
        $this->makeYouth($district, ['pinfl' => '3121212'.random_int(1000000, 9999999)]);

        $response = $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->getJson('/api/yoshlar/youth?per_page=200')
            ->assertOk();

        $this->assertStringNotContainsString('pinfl', $response->getContent());
    }

    public function test_district_role_can_create_verified_youth(): void
    {
        $district = $this->someDistrictId();

        $this->actingAs($this->districtUser('yoshlar_bolim', $district), 'sanctum')
            ->postJson('/api/yoshlar/youth', $this->payload($district))
            ->assertCreated()
            ->assertJsonPath('data.verification_status', 'verified');
    }

    public function test_sector_bolim_creates_pending_youth(): void
    {
        $district = $this->someDistrictId();

        $id = $this->actingAs($this->districtUser('sektor_bolim', $district), 'sanctum')
            ->postJson('/api/yoshlar/youth', $this->payload($district))
            ->assertCreated()
            ->assertJsonPath('data.verification_status', 'pending')
            ->json('data.id');

        // Tasdiqlanmagan yozuv umumiy reyestrda ko'rinmaydi.
        $ids = $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->getJson('/api/yoshlar/youth?per_page=200')->json('data.*.id');

        $this->assertNotContains($id, $ids);
    }

    public function test_sector_bolim_cannot_update_verified_youth(): void
    {
        $district = $this->someDistrictId();
        $youth = $this->makeYouth($district);

        $this->actingAs($this->districtUser('sektor_bolim', $district), 'sanctum')
            ->patchJson("/api/yoshlar/youth/{$youth->id}", ['phone' => '+998901234567'])
            ->assertStatus(403);
    }

    public function test_hokim_orinbosari_cannot_create(): void
    {
        $district = $this->someDistrictId();

        $this->actingAs($this->makeUser('yoshlar_hokim_orinbosari'), 'sanctum')
            ->postJson('/api/yoshlar/youth', $this->payload($district))
            ->assertStatus(403);
    }

    public function test_age_filter_uses_boundaries(): void
    {
        $district = $this->someDistrictId();
        $teen = $this->makeYouth($district, ['birth_date' => now()->subYears(15)->toDateString()]);
        $adult = $this->makeYouth($district, ['birth_date' => now()->subYears(29)->toDateString()]);

        $ids = $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->getJson('/api/yoshlar/youth?age_min=25&age_max=30&per_page=200')
            ->assertOk()->json('data.*.id');

        $this->assertContains($adult->id, $ids);
        $this->assertNotContains($teen->id, $ids);
    }

    public function test_duplicate_warning_when_pinfl_is_absent(): void
    {
        $district = $this->someDistrictId();
        $payload = $this->payload($district);
        $payload['last_name'] = 'Takrorov';
        $payload['first_name'] = 'Yosh';

        $user = $this->districtUser('yoshlar_bolim', $district);

        // Birinchi yozuv — ogohlantirish yo'q.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/yoshlar/youth', $payload)
            ->assertCreated()
            ->assertJsonPath('duplicate_warning', 0);

        // Ikkinchisi — bir xil FIO + sana + mahalla: ogohlantiradi, LEKIN saqlaydi.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/yoshlar/youth', $payload)
            ->assertCreated()
            ->assertJsonPath('duplicate_warning', 1);
    }

    public function test_search_works_in_cyrillic_for_latin_data(): void
    {
        $district = $this->someDistrictId();
        $youth = $this->makeYouth($district, ['last_name' => 'Qodirov', 'first_name' => 'Sardor']);

        $ids = $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->getJson('/api/yoshlar/youth?search=Қодиров&per_page=200')
            ->assertOk()->json('data.*.id');

        $this->assertContains($youth->id, $ids);
    }

    private function districtUser(string $role, string $districtId): User
    {
        $type = $role === 'sektor_bolim'
            ? Organization::TYPE_TUMAN_SEKTOR
            : Organization::TYPE_TUMAN_YOSHLAR;

        return $this->makeUser($role, $this->makeOrganization($type, ['district_id' => $districtId])->id);
    }

    /** @return array<string, mixed> */
    private function payload(string $districtId): array
    {
        return [
            'last_name' => 'Yangi',
            'first_name' => 'Yosh',
            'birth_date' => now()->subYears(19)->toDateString(),
            'gender' => 'erkak',
            'district_id' => $districtId,
            'mahalla_id' => $this->someMahallaId($districtId),
            'education_status' => 'oqimaydi',
            'employment_status' => 'band_emas',
        ];
    }

    /** @param array<string, mixed> $attrs */
    private function makeYouth(string $districtId, array $attrs = []): Youth
    {
        return Youth::query()->create(array_merge([
            'last_name' => 'Testov',
            'first_name' => 'Test',
            'birth_date' => now()->subYears(20)->toDateString(),
            'gender' => 'erkak',
            'district_id' => $districtId,
            'mahalla_id' => $this->someMahallaId($districtId),
        ], $attrs));
    }
}
```

- [ ] **Step 2: Testni ishga tushirib FAIL ko'rish**

Run: `C:/php84/php.exe artisan test --filter=YoshlarYouthApiTest`
Expected: FAIL — 404 (marshrut yo'q).

- [ ] **Step 3: `YouthService` yozish**

`app/Domains/Yoshlar/Services/YouthService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Services;

use App\Domains\Yoshlar\Models\Youth;
use App\Domains\Yoshlar\Support\Translit;
use App\Domains\Yoshlar\Support\YoshlarAccess;
use App\Domains\Yoshlar\Support\YoshlarScope;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reyestr biznes qoidalari.
 *
 * TASDIQLASH: reyestrga yoshlar vertikali egalik qiladi. Sektor bo'limi yangi
 * yosh qo'sha oladi, lekin yozuv `pending` bo'lib tushadi va tuman yoshlar
 * bo'limi tasdiqlamaguncha umumiy reyestrga kirmaydi.
 */
class YouthService
{
    public function __construct(
        private readonly YoshlarAccess $access,
        private readonly YoshlarScope $scope,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<Youth>
     */
    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        $query = $this->scope->applyYouth(Youth::query(), $user);

        $this->applyFilters($query, $filters, $user);

        $perPage = min((int) ($filters['per_page'] ?? 25), 200);

        return $query->orderBy('last_name')->orderBy('first_name')->paginate($perPage);
    }

    /** Bitta yozuv — doiradan tashqarida bo'lsa `null` (kontroller 404 qaytaradi). */
    public function find(User $user, string $id): ?Youth
    {
        return $this->scope->applyYouth(Youth::query(), $user)->where('id', $id)->first();
    }

    /** @param array<string, mixed> $data */
    public function create(User $user, array $data): Youth
    {
        $staff = $this->access->staffFor($user);
        $role = $this->access->roleFor($user);

        // Sektor bo'limi TAKLIF kiritadi — tasdiqlanmaguncha reyestrga kirmaydi.
        $data['verification_status'] = $role === 'sektor_bolim' ? 'pending' : 'verified';
        $data['created_by'] = $user->id;
        $data['created_by_org_id'] = $staff?->org_id;
        $data['registry_status'] = 'active';

        $youth = Youth::query()->create($data);

        $this->audit->log($user, 'youth.create', 'youth', $youth->id, [
            'verification_status' => $data['verification_status'],
        ]);

        return $youth;
    }

    /** @param array<string, mixed> $data */
    public function update(User $user, Youth $youth, array $data): Youth
    {
        $data['updated_by'] = $user->id;
        $before = $youth->only(array_keys($data));

        $youth->update($data);

        $this->audit->log($user, 'youth.update', 'youth', $youth->id, [
            'before' => $this->withoutPii($before),
            'after' => $this->withoutPii($data),
        ]);

        return $youth->refresh();
    }

    /**
     * PINFLsiz yozuv uchun ehtimoliy dublikatlar soni (bloklamaydi, ogohlantiradi).
     *
     * @param  array<string, mixed>  $data
     */
    public function possibleDuplicates(array $data): int
    {
        $norm = Translit::normalize(
            trim(((string) ($data['last_name'] ?? '')).' '.((string) ($data['first_name'] ?? ''))),
        );

        if ($norm === '') {
            return 0;
        }

        return Youth::query()
            ->where('full_name_norm', 'like', $norm.'%')
            ->where('birth_date', $data['birth_date'] ?? null)
            ->where('mahalla_id', $data['mahalla_id'] ?? null)
            ->count();
    }

    /**
     * @param  Builder<Youth>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters, User $user): void
    {
        // Tasdiqlanmagan yozuvlar umumiy ro'yxatga KIRMAYDI — ular alohida
        // «tasdiq navbati» so'rovi bilan olinadi (?verification_status=pending).
        $verification = (string) ($filters['verification_status'] ?? 'verified');
        if (in_array($verification, Youth::VERIFICATION_STATUSES, true)) {
            $query->where('verification_status', $verification);
        }

        $query->where('registry_status', (string) ($filters['registry_status'] ?? 'active'));

        foreach (['district_id', 'mahalla_id', 'gender', 'education_status', 'employment_status'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        foreach (['is_neet', 'is_graduate_unemployed', 'in_youth_book', 'is_entrepreneur'] as $flag) {
            if (array_key_exists($flag, $filters) && $filters[$flag] !== '') {
                $query->where($flag, filter_var($filters[$flag], FILTER_VALIDATE_BOOL));
            }
        }

        $min = (int) ($filters['age_min'] ?? Youth::MIN_AGE);
        $max = (int) ($filters['age_max'] ?? Youth::MAX_AGE);
        $query->ageBetween($min, $max);

        if (! empty($filters['search'])) {
            $key = Translit::normalize((string) $filters['search']);
            $query->where('full_name_norm', 'like', '%'.$key.'%');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withoutPii(array $data): array
    {
        foreach (['pinfl', 'passport_series', 'passport_number'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = '***';   // fakt yoziladi, qiymat emas
            }
        }

        return $data;
    }
}
```

- [ ] **Step 4: `AuditLogger` yozish**

`app/Domains/Yoshlar/Services/AuditLogger.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

/**
 * Audit jurnali — hukumat hisobdorligi uchun. PII QIYMATLARI yozilmaydi:
 * jurnal o'zi maxfiy ma'lumot omboriga aylanib qolmasligi kerak.
 */
class AuditLogger
{
    /** @param array<string, mixed>|null $changes */
    public function log(User $user, string $action, string $entityType, ?string $entityId, ?array $changes = null): void
    {
        DB::connection('yoshlar')->table('audit_log')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'changes' => $changes === null ? null : json_encode($changes, JSON_UNESCAPED_UNICODE),
            'ip' => Request::ip(),
            'created_at' => now(),
        ]);
    }
}
```

- [ ] **Step 5: Form Request'lar**

`app/Domains/Yoshlar/Http/Requests/YouthStoreRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Http\Requests;

use App\Domains\Yoshlar\Models\Youth;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class YouthStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // ruxsat kontrollerda YoshlarAccess orqali tekshiriladi
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'last_name' => ['required', 'string', 'max:120'],
            'first_name' => ['required', 'string', 'max:120'],
            'middle_name' => ['nullable', 'string', 'max:120'],
            'birth_date' => ['required', 'date', 'before:today'],
            'gender' => ['required', Rule::in(['erkak', 'ayol'])],
            'district_id' => ['required', 'uuid'],
            'mahalla_id' => ['required', 'uuid'],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:30'],
            'pinfl' => ['nullable', 'string', 'size:14'],
            'passport_series' => ['nullable', 'string', 'max:10'],
            'passport_number' => ['nullable', 'string', 'max:20'],
            'education_status' => ['required', Rule::in(Youth::EDUCATION_STATUSES)],
            'education_place' => ['nullable', 'string', 'max:300'],
            'employment_status' => ['required', Rule::in(Youth::EMPLOYMENT_STATUSES)],
            'workplace' => ['nullable', 'string', 'max:300'],
            'is_neet' => ['boolean'],
            'is_graduate_unemployed' => ['boolean'],
            'in_youth_book' => ['boolean'],
            'is_entrepreneur' => ['boolean'],
        ];
    }
}
```

`app/Domains/Yoshlar/Http/Requests/YouthUpdateRequest.php` — bir xil qoidalar,
lekin barcha maydon `sometimes` bilan:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Http\Requests;

use App\Domains\Yoshlar\Models\Youth;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class YouthUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'last_name' => ['sometimes', 'string', 'max:120'],
            'first_name' => ['sometimes', 'string', 'max:120'],
            'middle_name' => ['nullable', 'string', 'max:120'],
            'birth_date' => ['sometimes', 'date', 'before:today'],
            'gender' => ['sometimes', Rule::in(['erkak', 'ayol'])],
            'district_id' => ['sometimes', 'uuid'],
            'mahalla_id' => ['sometimes', 'uuid'],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:30'],
            'pinfl' => ['nullable', 'string', 'size:14'],
            'passport_series' => ['nullable', 'string', 'max:10'],
            'passport_number' => ['nullable', 'string', 'max:20'],
            'education_status' => ['sometimes', Rule::in(Youth::EDUCATION_STATUSES)],
            'education_place' => ['nullable', 'string', 'max:300'],
            'employment_status' => ['sometimes', Rule::in(Youth::EMPLOYMENT_STATUSES)],
            'workplace' => ['nullable', 'string', 'max:300'],
            'registry_status' => ['sometimes', Rule::in(Youth::REGISTRY_STATUSES)],
            'is_neet' => ['boolean'],
            'is_graduate_unemployed' => ['boolean'],
            'in_youth_book' => ['boolean'],
            'is_entrepreneur' => ['boolean'],
        ];
    }
}
```

- [ ] **Step 6: `YouthController` yozish**

`app/Domains/Yoshlar/Http/Controllers/Api/YouthController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Http\Controllers\Api;

use App\Domains\Yoshlar\Http\Requests\YouthStoreRequest;
use App\Domains\Yoshlar\Http\Requests\YouthUpdateRequest;
use App\Domains\Yoshlar\Models\Youth;
use App\Domains\Yoshlar\Services\YouthService;
use App\Domains\Yoshlar\Support\YoshlarAccess;
use App\Domains\Yoshlar\Support\YoshlarScope;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class YouthController extends Controller
{
    public function __construct(
        private readonly YouthService $service,
        private readonly YoshlarAccess $access,
        private readonly YoshlarScope $scope,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAction($request, 'yoshlar.view');

        $page = $this->service->paginate($request->user(), $request->query());

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(Request $request, string $youth): JsonResponse
    {
        $this->authorizeAction($request, 'yoshlar.view');

        $model = $this->service->find($request->user(), $youth);

        // Doiradan tashqaridagi yozuv uchun 404 — 403 emas: 403 yozuv MAVJUDLIGINI
        // oshkor qilardi (mavjudlik ham ma'lumot).
        abort_if($model === null, 404, 'Yozuv topilmadi.');

        return response()->json(['data' => $model]);
    }

    public function store(YouthStoreRequest $request): JsonResponse
    {
        $this->authorizeAction($request, 'yoshlar.youth.create');

        $data = $request->validated();

        abort_unless(
            $this->scope->canTouchDistrict($request->user(), $data['district_id']),
            403,
            'Bu tuman sizning doirangizda emas.',
        );

        $youth = $this->service->create($request->user(), $data);

        return response()->json([
            'data' => $youth,
            'duplicate_warning' => empty($data['pinfl'])
                ? max(0, $this->service->possibleDuplicates($data) - 1)
                : 0,
        ], 201);
    }

    public function update(YouthUpdateRequest $request, string $youth): JsonResponse
    {
        $user = $request->user();
        $model = $this->service->find($user, $youth);
        abort_if($model === null, 404, 'Yozuv topilmadi.');

        $this->authorizeUpdate($request, $model);

        return response()->json(['data' => $this->service->update($user, $model, $request->validated())]);
    }

    public function destroy(Request $request, string $youth): JsonResponse
    {
        $this->authorizeAction($request, 'yoshlar.youth.delete');

        $model = $this->service->find($request->user(), $youth);
        abort_if($model === null, 404, 'Yozuv topilmadi.');

        $model->delete();

        return response()->json(['status' => 'ok']);
    }

    /**
     * Yozish huquqi. `sektor_bolim` istisno: u FAQAT o'zi kiritgan va hali
     * tasdiqlanmagan (`pending`/`rejected`) yozuvni tuzatishi mumkin — rad
     * etilgan taklifni qayta yuborish uchun.
     */
    private function authorizeUpdate(Request $request, Youth $youth): void
    {
        $user = $request->user();

        if ($this->access->can($user, 'yoshlar.youth.update')) {
            abort_unless(
                $this->scope->canTouchDistrict($user, $youth->district_id),
                403,
                'Bu tuman sizning doirangizda emas.',
            );

            return;
        }

        $isOwnPending = $this->access->roleFor($user) === 'sektor_bolim'
            && $youth->created_by === $user->id
            && in_array($youth->verification_status, ['pending', 'rejected'], true);

        abort_unless($isOwnPending, 403, 'Bu yozuvni tahrirlash huquqingiz yo‘q.');
    }

    private function authorizeAction(Request $request, string $permission): void
    {
        abort_unless($this->access->can($request->user(), $permission), 403, 'Ruxsat yo‘q.');
    }
}
```

- [ ] **Step 7: Marshrutlarni qo'shish**

`routes/api/yoshlar.php` da `context` qatoridan keyin:

```php
        // Yoshlar reyestri.
        Route::get('/youth', [YouthController::class, 'index'])->name('youth.index');
        Route::post('/youth', [YouthController::class, 'store'])->name('youth.store');
        Route::get('/youth/{youth}', [YouthController::class, 'show'])->name('youth.show');
        Route::patch('/youth/{youth}', [YouthController::class, 'update'])->name('youth.update');
        Route::delete('/youth/{youth}', [YouthController::class, 'destroy'])->name('youth.destroy');
```

Fayl boshiga `use App\Domains\Yoshlar\Http\Controllers\Api\YouthController;` qo'shing.

- [ ] **Step 8: Testni yashil holatga keltirish**

Run: `C:/php84/php.exe artisan optimize:clear && C:/php84/php.exe artisan test --filter=YoshlarYouthApiTest`
Expected: `OK (11 tests)`

- [ ] **Step 9: Commit**

```bash
git add app/Domains/Yoshlar routes/api/yoshlar.php tests/Feature/Yoshlar/YoshlarYouthApiTest.php
git commit -m "feat(yoshlar): reyestr API — doira, filtr, kirillcha qidiruv, CRUD"
```

---

## Task 8: Tasdiqlash sikli (`verify` / `reject`)

**Files:**
- Modify: `app/Domains/Yoshlar/Services/YouthService.php`, `app/Domains/Yoshlar/Http/Controllers/Api/YouthController.php`, `routes/api/yoshlar.php`
- Test: `tests/Feature/Yoshlar/YoshlarVerificationTest.php`

**Interfaces:**
- Consumes: `YouthService` (Task 7).
- Produces:
  - `POST /api/yoshlar/youth/{youth}/verify`, `POST /api/yoshlar/youth/{youth}/reject`
  - `YouthService::verify(User, Youth): Youth`, `reject(User, Youth, string $reason): Youth`
  - `GET /api/yoshlar/youth?verification_status=pending` — tasdiq navbati

- [ ] **Step 1: Test yozish (FAIL)**

`tests/Feature/Yoshlar/YoshlarVerificationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Yoshlar;

use App\Domains\Yoshlar\Models\Organization;
use App\Domains\Yoshlar\Models\Youth;

/**
 * Tasdiqlash sikli: kim tasdiqlaydi, kim tasdiqlay olmaydi, natija nima.
 */
class YoshlarVerificationTest extends YoshlarTestCase
{
    public function test_district_youth_office_can_verify(): void
    {
        $district = $this->someDistrictId();
        $youth = $this->pendingYouth($district);
        $user = $this->makeUser('yoshlar_bolim', $this->makeOrganization(
            Organization::TYPE_TUMAN_YOSHLAR, ['district_id' => $district],
        )->id);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/yoshlar/youth/{$youth->id}/verify")
            ->assertOk()
            ->assertJsonPath('data.verification_status', 'verified');

        $this->assertSame($user->id, $youth->fresh()->verified_by);
    }

    public function test_admin_cannot_verify(): void
    {
        $youth = $this->pendingYouth($this->someDistrictId());

        $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->postJson("/api/yoshlar/youth/{$youth->id}/verify")
            ->assertStatus(403);
    }

    public function test_reject_requires_reason_and_stores_it(): void
    {
        $district = $this->someDistrictId();
        $youth = $this->pendingYouth($district);
        $user = $this->makeUser('yoshlar_bolim', $this->makeOrganization(
            Organization::TYPE_TUMAN_YOSHLAR, ['district_id' => $district],
        )->id);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/yoshlar/youth/{$youth->id}/reject", [])
            ->assertStatus(422);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/yoshlar/youth/{$youth->id}/reject", ['reason' => 'Mahalla noto‘g‘ri'])
            ->assertOk()
            ->assertJsonPath('data.verification_status', 'rejected');

        $this->assertSame('Mahalla noto‘g‘ri', $youth->fresh()->reject_reason);
    }

    public function test_pending_queue_is_visible_to_district_office(): void
    {
        $district = $this->someDistrictId();
        $youth = $this->pendingYouth($district);
        $user = $this->makeUser('yoshlar_bolim', $this->makeOrganization(
            Organization::TYPE_TUMAN_YOSHLAR, ['district_id' => $district],
        )->id);

        $ids = $this->actingAs($user, 'sanctum')
            ->getJson('/api/yoshlar/youth?verification_status=pending&per_page=200')
            ->assertOk()->json('data.*.id');

        $this->assertContains($youth->id, $ids);
    }

    public function test_verify_outside_own_district_is_forbidden(): void
    {
        $own = $this->someDistrictId();
        $youth = $this->pendingYouth($this->otherDistrictId($own));
        $user = $this->makeUser('yoshlar_bolim', $this->makeOrganization(
            Organization::TYPE_TUMAN_YOSHLAR, ['district_id' => $own],
        )->id);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/yoshlar/youth/{$youth->id}/verify")
            ->assertStatus(404);
    }

    private function pendingYouth(string $districtId): Youth
    {
        return Youth::query()->create([
            'last_name' => 'Kutilayotgan',
            'first_name' => 'Yosh',
            'birth_date' => now()->subYears(18)->toDateString(),
            'gender' => 'ayol',
            'district_id' => $districtId,
            'mahalla_id' => $this->someMahallaId($districtId),
            'verification_status' => 'pending',
        ]);
    }
}
```

- [ ] **Step 2: FAIL ko'rish**

Run: `C:/php84/php.exe artisan test --filter=YoshlarVerificationTest`
Expected: FAIL — 404 (marshrut yo'q).

- [ ] **Step 3: `YouthService` ga tasdiqlash metodlarini qo'shish**

`YouthService` klassiga qo'shing:

```php
    public function verify(User $user, Youth $youth): Youth
    {
        $youth->update([
            'verification_status' => 'verified',
            'verified_by' => $user->id,
            'verified_at' => now(),
            'reject_reason' => null,
        ]);

        $this->audit->log($user, 'youth.verify', 'youth', $youth->id);

        return $youth->refresh();
    }

    public function reject(User $user, Youth $youth, string $reason): Youth
    {
        $youth->update([
            'verification_status' => 'rejected',
            'verified_by' => $user->id,
            'verified_at' => now(),
            'reject_reason' => $reason,
        ]);

        $this->audit->log($user, 'youth.reject', 'youth', $youth->id, ['reason' => $reason]);

        return $youth->refresh();
    }
```

- [ ] **Step 4: Kontrollerga amallarni qo'shish**

`YouthController` ga qo'shing:

```php
    public function verify(Request $request, string $youth): JsonResponse
    {
        $model = $this->findVerifiable($request, $youth);

        return response()->json(['data' => $this->service->verify($request->user(), $model)]);
    }

    public function reject(Request $request, string $youth): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $model = $this->findVerifiable($request, $youth);

        return response()->json([
            'data' => $this->service->reject($request->user(), $model, $validated['reason']),
        ]);
    }

    private function findVerifiable(Request $request, string $youth): Youth
    {
        $this->authorizeAction($request, 'yoshlar.youth.verify');

        $model = $this->service->find($request->user(), $youth);
        abort_if($model === null, 404, 'Yozuv topilmadi.');

        return $model;
    }
```

- [ ] **Step 5: Marshrutlarni qo'shish**

`routes/api/yoshlar.php` da youth marshrutlaridan keyin:

```php
        Route::post('/youth/{youth}/verify', [YouthController::class, 'verify'])->name('youth.verify');
        Route::post('/youth/{youth}/reject', [YouthController::class, 'reject'])->name('youth.reject');
```

- [ ] **Step 6: Testni yashil holatga keltirish**

Run: `C:/php84/php.exe artisan optimize:clear && C:/php84/php.exe artisan test --filter=YoshlarVerificationTest`
Expected: `OK (5 tests)`

- [ ] **Step 7: Commit**

```bash
git add app/Domains/Yoshlar routes/api/yoshlar.php tests/Feature/Yoshlar/YoshlarVerificationTest.php
git commit -m "feat(yoshlar): reyestr tasdiqlash sikli (verify/reject)"
```

---

## Task 9: PII ochish va jurnal

**Files:**
- Create: `app/Domains/Yoshlar/Services/PiiGuard.php`
- Create: `app/Domains/Yoshlar/Models/PiiAccessLog.php`
- Modify: `app/Domains/Yoshlar/Http/Controllers/Api/YouthController.php`, `routes/api/yoshlar.php`
- Test: `tests/Feature/Yoshlar/YoshlarPiiTest.php`

**Interfaces:**
- Consumes: `Youth`, `YoshlarAccess`, `AuditLogger`.
- Produces:
  - `POST /api/yoshlar/youth/{youth}/reveal-pii` -> `{pinfl, passport_series, passport_number}`
  - `PiiGuard::reveal(User, Youth): array{pinfl: ?string, passport_series: ?string, passport_number: ?string}`

- [ ] **Step 1: Test yozish (FAIL)**

`tests/Feature/Yoshlar/YoshlarPiiTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Yoshlar;

use App\Domains\Yoshlar\Models\Youth;
use Illuminate\Support\Facades\DB;

/**
 * PII: kim ocha oladi, ochilish jurnalga tushadimi, ruxsatsizga sizadimi.
 */
class YoshlarPiiTest extends YoshlarTestCase
{
    public function test_authorized_role_reveals_pinfl_and_it_is_logged(): void
    {
        $youth = $this->youthWithPinfl();
        $user = $this->makeUser('yoshlar_boshqarma');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/yoshlar/youth/{$youth->id}/reveal-pii")
            ->assertOk()
            ->assertJsonPath('pinfl', $youth->pinfl);

        $logged = DB::connection('yoshlar')->table('pii_access_log')
            ->where('user_id', $user->id)->where('youth_id', $youth->id)->count();

        $this->assertSame(1, $logged, 'PII ochilishi jurnalga tushmadi');
    }

    public function test_hokim_orinbosari_cannot_reveal(): void
    {
        $youth = $this->youthWithPinfl();

        $this->actingAs($this->makeUser('yoshlar_hokim_orinbosari'), 'sanctum')
            ->postJson("/api/yoshlar/youth/{$youth->id}/reveal-pii")
            ->assertStatus(403);
    }

    public function test_sector_bolim_cannot_reveal(): void
    {
        $youth = $this->youthWithPinfl();

        $this->actingAs($this->makeUser('sektor_bolim'), 'sanctum')
            ->postJson("/api/yoshlar/youth/{$youth->id}/reveal-pii")
            ->assertStatus(403);
    }

    public function test_show_endpoint_never_returns_pii(): void
    {
        $youth = $this->youthWithPinfl();

        $body = $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->getJson("/api/yoshlar/youth/{$youth->id}")
            ->assertOk()->getContent();

        $this->assertStringNotContainsString($youth->pinfl, $body);
    }

    public function test_audit_log_does_not_store_pii_values(): void
    {
        $youth = $this->youthWithPinfl();
        $user = $this->makeUser('yoshlar_admin');

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/yoshlar/youth/{$youth->id}", ['pinfl' => '31111111111111'])
            ->assertOk();

        $changes = DB::connection('yoshlar')->table('audit_log')
            ->where('entity_id', $youth->id)->where('action', 'youth.update')
            ->value('changes');

        $this->assertStringNotContainsString('31111111111111', (string) $changes);
    }

    private function youthWithPinfl(): Youth
    {
        $districtId = $this->someDistrictId();

        return Youth::query()->create([
            'last_name' => 'Maxfiy',
            'first_name' => 'Yosh',
            'birth_date' => now()->subYears(22)->toDateString(),
            'gender' => 'erkak',
            'district_id' => $districtId,
            'mahalla_id' => $this->someMahallaId($districtId),
            'pinfl' => '3'.random_int(1000000000000, 9999999999999),
        ]);
    }
}
```

- [ ] **Step 2: FAIL ko'rish**

Run: `C:/php84/php.exe artisan test --filter=YoshlarPiiTest`
Expected: FAIL — 404 (`reveal-pii` marshruti yo'q).

- [ ] **Step 3: `PiiAccessLog` modeli**

`app/Domains/Yoshlar/Models/PiiAccessLog.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Maxfiy maydon ochilishi jurnali — faqat yoziladi, hech qachon o'chirilmaydi. */
class PiiAccessLog extends Model
{
    use HasUuids;

    protected $connection = 'yoshlar';

    protected $table = 'pii_access_log';

    public $timestamps = false;

    protected $fillable = ['user_id', 'youth_id', 'fields', 'ip', 'created_at'];
}
```

- [ ] **Step 4: `PiiGuard` yozish**

`app/Domains/Yoshlar/Services/PiiGuard.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Services;

use App\Domains\Yoshlar\Models\PiiAccessLog;
use App\Domains\Yoshlar\Models\Youth;
use App\Domains\Yoshlar\Support\YoshlarAccess;
use App\Models\User;
use Illuminate\Support\Facades\Request;

/**
 * Maxfiy maydonlarni ochish — YAGONA yo'l. Har ochilish jurnalga tushadi.
 *
 * NEGA ALOHIDA SERVIS: `$hidden` ni kontrollerda `makeVisible()` bilan yechish
 * mumkin, lekin unda jurnalni unutib qo'yish oson bo'lardi. Bu yerda ochilish
 * va jurnal bitta amalda — birini bajarib, ikkinchisini o'tkazib bo'lmaydi.
 */
class PiiGuard
{
    public function __construct(
        private readonly YoshlarAccess $access,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{pinfl: ?string, passport_series: ?string, passport_number: ?string} */
    public function reveal(User $user, Youth $youth): array
    {
        abort_unless(
            $this->access->can($user, 'yoshlar.pii.reveal'),
            403,
            'Maxfiy ma’lumotni ko‘rish huquqingiz yo‘q.',
        );

        PiiAccessLog::query()->create([
            'user_id' => $user->id,
            'youth_id' => $youth->id,
            'fields' => 'pinfl,passport',
            'ip' => Request::ip(),
            'created_at' => now(),
        ]);

        $this->audit->log($user, 'youth.pii_reveal', 'youth', $youth->id);

        return [
            'pinfl' => $youth->pinfl,
            'passport_series' => $youth->passport_series,
            'passport_number' => $youth->passport_number,
        ];
    }
}
```

- [ ] **Step 5: Kontroller va marshrut**

`YouthController` ga qo'shing (konstruktorga `private readonly PiiGuard $pii` parametrini ham qo'shing va `use App\Domains\Yoshlar\Services\PiiGuard;` import qiling):

```php
    public function revealPii(Request $request, string $youth): JsonResponse
    {
        $model = $this->service->find($request->user(), $youth);
        abort_if($model === null, 404, 'Yozuv topilmadi.');

        return response()->json($this->pii->reveal($request->user(), $model));
    }
```

`routes/api/yoshlar.php`:

```php
        Route::post('/youth/{youth}/reveal-pii', [YouthController::class, 'revealPii'])->name('youth.reveal_pii');
```

- [ ] **Step 6: Testni yashil holatga keltirish**

Run: `C:/php84/php.exe artisan optimize:clear && C:/php84/php.exe artisan test --filter=YoshlarPiiTest`
Expected: `OK (5 tests)`

- [ ] **Step 7: Commit**

```bash
git add app/Domains/Yoshlar routes/api/yoshlar.php tests/Feature/Yoshlar/YoshlarPiiTest.php
git commit -m "feat(yoshlar): PII ochish darvozasi va ochilish jurnali"
```

---

## Task 10: Hisob yaratish — buyruq va admin API

**Files:**
- Create: `app/Domains/Yoshlar/Services/UserAdminService.php`
- Create: `app/Domains/Yoshlar/Console/Commands/MakeYoshlarUserCommand.php`
- Create: `app/Domains/Yoshlar/Http/Controllers/Api/AdminController.php`
- Modify: `routes/api/yoshlar.php`
- Test: `tests/Feature/Yoshlar/YoshlarUserAdminTest.php`

**Interfaces:**
- Consumes: `Organization::ROLE_TYPE`, `YoshlarAccess::ORG_OPTIONAL_ROLES`.
- Produces:
  - `UserAdminService::create(string $login, string $name, string $role, ?string $orgId, ?string $position, bool $canPatronage): array{user_id: string, password: string}`
  - `php artisan yoshlar:make-user`
  - `GET|POST /api/yoshlar/admin/users`, `PATCH /api/yoshlar/admin/users/{user}`

- [ ] **Step 1: Test yozish (FAIL)**

`tests/Feature/Yoshlar/YoshlarUserAdminTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Yoshlar;

use App\Domains\Yoshlar\Models\Organization;
use App\Domains\Yoshlar\Models\Staff;
use App\Domains\Yoshlar\Services\UserAdminService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hisob ochish: fail-closed tuzoqni oldini olish (org majburiyligi) va
 * rol/tashkilot turi mosligi.
 */
class YoshlarUserAdminTest extends YoshlarTestCase
{
    public function test_district_role_requires_organization(): void
    {
        $this->expectException(ValidationException::class);

        app(UserAdminService::class)->create('t_'.uniqid(), 'Sinov', 'yoshlar_bolim', null, null, false);
    }

    public function test_role_must_match_organization_type(): void
    {
        $viloyat = $this->makeOrganization(Organization::TYPE_VILOYAT_YOSHLAR);

        $this->expectException(ValidationException::class);

        // tuman roli — viloyat tashkilotiga biriktirilmoqda
        app(UserAdminService::class)->create('t_'.uniqid(), 'Sinov', 'yoshlar_bolim', $viloyat->id, null, false);
    }

    public function test_created_user_gets_staff_and_system_access(): void
    {
        $org = $this->makeOrganization(Organization::TYPE_TUMAN_YOSHLAR, [
            'district_id' => $this->someDistrictId(),
        ]);
        $login = 't_'.uniqid();

        $result = app(UserAdminService::class)->create($login, 'Sinov Xodim', 'yoshlar_bolim', $org->id, 'Boshliq', false);

        $this->assertNotEmpty($result['password']);
        $this->assertTrue(Staff::query()->where('user_id', $result['user_id'])->where('is_active', true)->exists());

        $role = DB::connection('auth')->table('user_system_access as usa')
            ->join('systems as s', 's.id', '=', 'usa.system_id')
            ->where('usa.user_id', $result['user_id'])->where('s.code', 'yoshlar')
            ->value('usa.role');

        $this->assertSame('yoshlar_bolim', $role);
    }

    public function test_duplicate_login_is_rejected(): void
    {
        $org = $this->makeOrganization(Organization::TYPE_TUMAN_YOSHLAR, [
            'district_id' => $this->someDistrictId(),
        ]);
        $login = 't_'.uniqid();

        app(UserAdminService::class)->create($login, 'Birinchi', 'yoshlar_bolim', $org->id, null, false);

        $this->expectException(ValidationException::class);

        app(UserAdminService::class)->create($login, 'Ikkinchi', 'yoshlar_bolim', $org->id, null, false);
    }

    public function test_only_admin_can_call_user_endpoint(): void
    {
        $this->actingAs($this->makeUser('yoshlar_boshqarma'), 'sanctum')
            ->getJson('/api/yoshlar/admin/users')
            ->assertStatus(403);

        $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->getJson('/api/yoshlar/admin/users')
            ->assertOk();
    }
}
```

- [ ] **Step 2: FAIL ko'rish**

Run: `C:/php84/php.exe artisan test --filter=YoshlarUserAdminTest`
Expected: FAIL — `Class "App\Domains\Yoshlar\Services\UserAdminService" not found`

- [ ] **Step 3: `UserAdminService` yozish**

`app/Domains/Yoshlar/Services/UserAdminService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Services;

use App\Domains\Yoshlar\Models\Organization;
use App\Domains\Yoshlar\Models\Staff;
use App\Domains\Yoshlar\Support\YoshlarAccess;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Hisob ochish. Ikkita yozuv ATOMAR bog'lanishi kerak: `yoshlar.staff` (doira)
 * va `auth.user_system_access` (kirish huquqi).
 *
 * TARTIB MUHIM: avval staff, keyin kirish huquqi. Teskarisi bo'lsa, profilsiz-u
 * kira oladigan hisob qolib ketardi va u fail-closed tuzoqqa tushib «hech narsa
 * ko'rmaydigan» foydalanuvchiga aylanardi.
 */
class UserAdminService
{
    /** @return array{user_id: string, password: string} */
    public function create(
        string $login,
        string $name,
        string $role,
        ?string $orgId,
        ?string $position,
        bool $canPatronage,
    ): array {
        $login = trim($login);
        $name = trim($name);

        if ($login === '' || $name === '') {
            throw ValidationException::withMessages(['login' => 'Login va ism bo‘sh bo‘lishi mumkin emas.']);
        }

        if (! in_array($role, YoshlarAccess::ROLES, true)) {
            throw ValidationException::withMessages(['role' => "«{$role}» — noto‘g‘ri rol."]);
        }

        // `withTrashed()` — users.login UNIQUE cheklovi soft-delete qatorlarni ham
        // hisobga oladi; tekshirmasak INSERT tushunarsiz xato bilan qulaydi.
        if (User::withTrashed()->where('login', $login)->exists()) {
            throw ValidationException::withMessages(['login' => "«{$login}» logini allaqachon band."]);
        }

        $org = $this->resolveOrganization($role, $orgId);

        $systemId = DB::connection('auth')->table('systems')
            ->where('code', YoshlarAccess::SYSTEM_CODE)->value('id');

        if ($systemId === null) {
            throw ValidationException::withMessages([
                'role' => '«yoshlar» tizimi auth.systems jadvalida topilmadi (SystemsSeeder ishga tushirilmagan).',
            ]);
        }

        $password = Str::password(20);

        $user = User::query()->create([
            'login' => $login,
            'name' => $name,
            'password' => bcrypt($password),
            'is_active' => true,
        ]);

        if ($org !== null) {
            Staff::query()->create([
                'user_id' => $user->id,
                'org_id' => $org->id,
                'position' => $position,
                'can_patronage' => $canPatronage,
                'is_active' => true,
            ]);
        }

        DB::connection('auth')->table('user_system_access')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'system_id' => $systemId,
            'role' => $role,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['user_id' => $user->id, 'password' => $password];
    }

    public function setActive(User $user, bool $active): void
    {
        $user->update(['is_active' => $active]);

        DB::connection('auth')->table('user_system_access')
            ->where('user_id', $user->id)->update(['is_active' => $active, 'updated_at' => now()]);

        Staff::query()->where('user_id', $user->id)->update(['is_active' => $active]);
    }

    private function resolveOrganization(string $role, ?string $orgId): ?Organization
    {
        if ($orgId === null || $orgId === '') {
            if (! in_array($role, YoshlarAccess::ORG_OPTIONAL_ROLES, true)) {
                throw ValidationException::withMessages([
                    'org_id' => "«{$role}» roli uchun tashkilot majburiy: usiz foydalanuvchi hech narsa ko‘rmaydi.",
                ]);
            }

            return null;
        }

        $org = Organization::query()
            ->where('id', $orgId)
            ->orWhere('name_lat', $orgId)
            ->orWhere('name_cyr', $orgId)
            ->first();

        if ($org === null) {
            throw ValidationException::withMessages(['org_id' => 'Tashkilot topilmadi.']);
        }

        $expected = Organization::ROLE_TYPE[$role] ?? null;

        if ($expected !== null && $org->type !== $expected) {
            throw ValidationException::withMessages([
                'org_id' => "«{$role}» roli «{$expected}» turidagi tashkilotga biriktiriladi, «{$org->type}» ga emas.",
            ]);
        }

        return $org;
    }
}
```

- [ ] **Step 4: Konsol buyrug'i**

`app/Domains/Yoshlar/Console/Commands/MakeYoshlarUserCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Console\Commands;

use App\Domains\Yoshlar\Services\UserAdminService;
use App\Domains\Yoshlar\Support\YoshlarAccess;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Yoshlar tizimi foydalanuvchisini yaratadi.
 *
 * Parol argument sifatida QABUL QILINMAYDI — shell tarixiga tushmasin;
 * buyruq ichida generatsiya qilinadi va bir marta ko'rsatiladi.
 */
class MakeYoshlarUserCommand extends Command
{
    protected $signature = 'yoshlar:make-user
        {login : Kirish logini}
        {name : To‘liq ismi}
        {role : Rol ('.'yoshlar_hokim_orinbosari|yoshlar_admin|yoshlar_boshqarma|yoshlar_bolim|sektor_boshqarma|sektor_bolim)}
        {--org= : Tashkilot nomi yoki ID (tuman/sektor rollari uchun MAJBURIY)}
        {--position= : Lavozimi}
        {--can-patronage : Otaliq huquqi}';

    protected $description = 'Yoshlar tizimi foydalanuvchisini yaratadi (rol + tashkilot doirasi)';

    public function handle(UserAdminService $service): int
    {
        try {
            $result = $service->create(
                (string) $this->argument('login'),
                (string) $this->argument('name'),
                (string) $this->argument('role'),
                $this->option('org') === null ? null : (string) $this->option('org'),
                $this->option('position') === null ? null : (string) $this->option('position'),
                (bool) $this->option('can-patronage'),
            );
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }
            $this->line('Mumkin bo‘lgan rollar: '.implode(', ', YoshlarAccess::ROLES));

            return self::FAILURE;
        }

        $this->info('Foydalanuvchi yaratildi.');
        $this->line('  ID:     '.$result['user_id']);
        $this->line('  Login:  '.$this->argument('login'));
        $this->line('  Rol:    '.$this->argument('role'));
        $this->line('  Parol:  '.$result['password']);
        $this->newLine();
        $this->warn('Parol BIR MARTA ko‘rsatildi — xavfsiz joyga yozib qo‘ying.');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: `AdminController` va marshrutlar**

`app/Domains/Yoshlar/Http/Controllers/Api/AdminController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Http\Controllers\Api;

use App\Domains\Yoshlar\Services\AuditLogger;
use App\Domains\Yoshlar\Services\UserAdminService;
use App\Domains\Yoshlar\Support\YoshlarAccess;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function __construct(
        private readonly YoshlarAccess $access,
        private readonly UserAdminService $service,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $rows = DB::connection('auth')->table('users as u')
            ->join('user_system_access as usa', 'usa.user_id', '=', 'u.id')
            ->join('systems as s', 's.id', '=', 'usa.system_id')
            ->where('s.code', YoshlarAccess::SYSTEM_CODE)
            ->whereNull('u.deleted_at')
            ->orderBy('u.name')
            ->get(['u.id', 'u.login', 'u.name', 'u.is_active', 'usa.role', 'u.last_login_at']);

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'login' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:200'],
            'role' => ['required', 'string'],
            'org_id' => ['nullable', 'uuid'],
            'position' => ['nullable', 'string', 'max:200'],
            'can_patronage' => ['boolean'],
        ]);

        $result = $this->service->create(
            $data['login'], $data['name'], $data['role'],
            $data['org_id'] ?? null, $data['position'] ?? null,
            (bool) ($data['can_patronage'] ?? false),
        );

        $this->audit->log($request->user(), 'user.create', 'user', $result['user_id'], ['role' => $data['role']]);

        return response()->json($result, 201);
    }

    public function update(Request $request, string $user): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $target = User::on('auth')->findOrFail($user);

        $this->service->setActive($target, $data['is_active']);
        $this->audit->log($request->user(), 'user.update', 'user', $user, $data);

        return response()->json(['status' => 'ok']);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($this->access->can($request->user(), 'yoshlar.user.manage'), 403, 'Ruxsat yo‘q.');
    }
}
```

`routes/api/yoshlar.php` (import qo'shib):

```php
        // Hisob boshqaruvi (faqat admin).
        Route::get('/admin/users', [AdminController::class, 'index'])->name('admin.users.index');
        Route::post('/admin/users', [AdminController::class, 'store'])->name('admin.users.store');
        Route::patch('/admin/users/{user}', [AdminController::class, 'update'])->name('admin.users.update');
```

- [ ] **Step 6: Testni yashil holatga keltirish**

Run: `C:/php84/php.exe artisan optimize:clear && C:/php84/php.exe artisan test --filter=YoshlarUserAdminTest`
Expected: `OK (5 tests)`

- [ ] **Step 7: Buyruqni qo'lda tekshirish**

Run:
```bash
C:/php84/php.exe artisan yoshlar:make-user test_bolim "Sinov Xodim" yoshlar_bolim
```
Expected: xato — `«yoshlar_bolim» roli uchun tashkilot majburiy...`

Run (tashkilot bilan — seeder yaratgan tuman bo'limi nomini ishlating):
```bash
C:/php84/php.exe artisan yoshlar:make-user test_bolim "Sinov Xodim" yoshlar_bolim --org="Xiva tuman yoshlar bo‘limi"
```
Expected: `Foydalanuvchi yaratildi.` + parol ko'rsatiladi.

- [ ] **Step 8: Commit**

```bash
git add app/Domains/Yoshlar routes/api/yoshlar.php tests/Feature/Yoshlar/YoshlarUserAdminTest.php
git commit -m "feat(yoshlar): hisob yaratish — servis, buyruq va admin API"
```

---

## Task 11: To'liq `/context`, statistika, spravochnik API va reyestr yangilash

**Files:**
- Modify: `app/Domains/Yoshlar/Http/Controllers/Api/ContextController.php`
- Create: `app/Domains/Yoshlar/Http/Controllers/Api/{OrganizationController,SectorController,StaffController,AuditController}.php`
- Create: `app/Domains/Yoshlar/Console/Commands/RefreshRegistryCommand.php`
- Modify: `routes/api/yoshlar.php`
- Test: `tests/Feature/Yoshlar/YoshlarContextTest.php`, `tests/Feature/Yoshlar/YoshlarRegistryRefreshTest.php`

**Interfaces:**
- Consumes: hamma oldingi task.
- Produces:
  - `/context` javobi: `reference {districts, mahallas, sectors, organizations, education_statuses, employment_statuses}` + `badges {pending_youth}`
  - `GET /api/yoshlar/youth/stats`
  - `GET|POST|PATCH /api/yoshlar/{organizations,sectors,staff}`, `GET /api/yoshlar/audit`
  - `php artisan yoshlar:refresh-registry [--dry-run]`

- [ ] **Step 1: Kontekst testini yozish (FAIL)**

`tests/Feature/Yoshlar/YoshlarContextTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Yoshlar;

use App\Domains\Yoshlar\Models\Organization;
use App\Domains\Yoshlar\Models\Youth;

class YoshlarContextTest extends YoshlarTestCase
{
    public function test_context_returns_reference_data(): void
    {
        $response = $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->getJson('/api/yoshlar/context')
            ->assertOk()
            ->assertJsonStructure([
                'reference' => [
                    'districts', 'sectors', 'organizations',
                    'education_statuses', 'employment_statuses',
                ],
                'badges' => ['pending_youth'],
            ]);

        $this->assertNotEmpty($response->json('reference.districts'));
    }

    public function test_pending_badge_counts_only_own_scope(): void
    {
        $own = $this->someDistrictId();
        $other = $this->otherDistrictId($own);

        $this->pendingYouth($own);
        $this->pendingYouth($other);

        $user = $this->makeUser('yoshlar_bolim', $this->makeOrganization(
            Organization::TYPE_TUMAN_YOSHLAR, ['district_id' => $own],
        )->id);

        $badge = $this->actingAs($user, 'sanctum')
            ->getJson('/api/yoshlar/context')->assertOk()->json('badges.pending_youth');

        $this->assertSame(1, $badge);
    }

    public function test_stats_endpoint_returns_district_breakdown(): void
    {
        $this->actingAs($this->makeUser('yoshlar_admin'), 'sanctum')
            ->getJson('/api/yoshlar/youth/stats')
            ->assertOk()
            ->assertJsonStructure(['total', 'neet', 'pending', 'by_district']);
    }

    private function pendingYouth(string $districtId): Youth
    {
        return Youth::query()->create([
            'last_name' => 'Navbat', 'first_name' => 'Yosh',
            'birth_date' => now()->subYears(17)->toDateString(), 'gender' => 'erkak',
            'district_id' => $districtId, 'mahalla_id' => $this->someMahallaId($districtId),
            'verification_status' => 'pending',
        ]);
    }
}
```

- [ ] **Step 2: FAIL ko'rish**

Run: `C:/php84/php.exe artisan test --filter=YoshlarContextTest`
Expected: FAIL — `reference` kaliti yo'q.

- [ ] **Step 3: `ContextController` ni to'ldirish**

`ContextController::__invoke` javobiga qo'shing (`YoshlarScope $scope` parametrini ham qabul qiling):

```php
            'badges' => [
                'pending_youth' => $scope->applyYouth(Youth::query(), $user)
                    ->where('verification_status', 'pending')->count(),
            ],
            'reference' => [
                'districts' => DB::connection('master')->table('districts')
                    ->orderBy('sort_order')->get(['id', 'name_lat', 'name_cyr', 'soato_code'])->all(),
                'mahallas' => DB::connection('master')->table('mahallas')
                    ->where('is_active', true)->orderBy('sort_order')
                    ->get(['id', 'district_id', 'name_lat', 'name_cyr'])->all(),
                'sectors' => Sector::query()->where('is_active', true)
                    ->orderBy('sort_order')->get(['id', 'code', 'name_lat', 'name_cyr'])->all(),
                'organizations' => Organization::query()->where('is_active', true)
                    ->orderBy('name_lat')
                    ->get(['id', 'type', 'parent_id', 'district_id', 'sector_id', 'name_lat', 'name_cyr'])->all(),
                'education_statuses' => Youth::EDUCATION_STATUSES,
                'employment_statuses' => Youth::EMPLOYMENT_STATUSES,
                'registry_statuses' => Youth::REGISTRY_STATUSES,
                'roles' => YoshlarAccess::ROLE_NAMES,
            ],
```

Kerakli importlar: `App\Domains\Yoshlar\Models\{Organization, Sector, Youth}`,
`App\Domains\Yoshlar\Support\YoshlarScope`, `Illuminate\Support\Facades\DB`.

- [ ] **Step 4: `stats` endpointi**

`YouthController` ga qo'shing:

```php
    public function stats(Request $request): JsonResponse
    {
        $this->authorizeAction($request, 'yoshlar.view');

        $user = $request->user();
        $base = fn () => $this->scope->applyYouth(Youth::query(), $user)
            ->where('registry_status', 'active');

        return response()->json([
            'total' => $base()->visibleInRegistry()->count(),
            'neet' => $base()->visibleInRegistry()->where('is_neet', true)->count(),
            'pending' => $base()->where('verification_status', 'pending')->count(),
            'by_district' => $base()->visibleInRegistry()
                ->selectRaw('district_id, count(*) as total')
                ->groupBy('district_id')->pluck('total', 'district_id'),
        ]);
    }
```

**DIQQAT:** `/youth/stats` marshruti `/youth/{youth}` dan OLDIN e'lon qilinishi
shart — aks holda `stats` so'zi `{youth}` parametri sifatida ushlanadi:

```php
        Route::get('/youth/stats', [YouthController::class, 'stats'])->name('youth.stats');
```

- [ ] **Step 5: Spravochnik va audit kontrollerlari**

`OrganizationController` (`SectorController`, `StaffController` shu naqshda):

```php
<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Http\Controllers\Api;

use App\Domains\Yoshlar\Models\Organization;
use App\Domains\Yoshlar\Services\AuditLogger;
use App\Domains\Yoshlar\Services\OrganizationService;
use App\Domains\Yoshlar\Support\YoshlarAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    public function __construct(
        private readonly YoshlarAccess $access,
        private readonly OrganizationService $service,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'yoshlar.view'), 403, 'Ruxsat yo‘q.');

        return response()->json([
            'data' => Organization::query()->orderBy('type')->orderBy('name_lat')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $org = $this->service->create($request->all());
        $this->audit->log($request->user(), 'organization.create', 'organization', $org->id, $org->only(['type', 'name_lat']));

        return response()->json(['data' => $org], 201);
    }

    public function update(Request $request, string $organization): JsonResponse
    {
        $this->authorizeManage($request);

        $org = Organization::query()->findOrFail($organization);
        $updated = $this->service->update($org, $request->all());
        $this->audit->log($request->user(), 'organization.update', 'organization', $org->id, $request->all());

        return response()->json(['data' => $updated]);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($this->access->can($request->user(), 'yoshlar.org.manage'), 403, 'Ruxsat yo‘q.');
    }
}
```

`AuditController`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Http\Controllers\Api;

use App\Domains\Yoshlar\Support\YoshlarAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditController extends Controller
{
    public function __invoke(Request $request, YoshlarAccess $access): JsonResponse
    {
        abort_unless($access->can($request->user(), 'yoshlar.audit.view'), 403, 'Ruxsat yo‘q.');

        $rows = DB::connection('yoshlar')->table('audit_log')
            ->orderByDesc('created_at')
            ->limit(min((int) $request->query('limit', '100'), 500))
            ->get();

        return response()->json(['data' => $rows]);
    }
}
```

Marshrutlar:

```php
        Route::get('/organizations', [OrganizationController::class, 'index'])->name('organizations.index');
        Route::post('/organizations', [OrganizationController::class, 'store'])->name('organizations.store');
        Route::patch('/organizations/{organization}', [OrganizationController::class, 'update'])->name('organizations.update');
        Route::get('/sectors', [SectorController::class, 'index'])->name('sectors.index');
        Route::post('/sectors', [SectorController::class, 'store'])->name('sectors.store');
        Route::patch('/sectors/{sector}', [SectorController::class, 'update'])->name('sectors.update');
        Route::get('/staff', [StaffController::class, 'index'])->name('staff.index');
        Route::post('/staff', [StaffController::class, 'store'])->name('staff.store');
        Route::patch('/staff/{staff}', [StaffController::class, 'update'])->name('staff.update');
        Route::get('/audit', AuditController::class)->name('audit');
```

`app/Domains/Yoshlar/Http/Controllers/Api/SectorController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Http\Controllers\Api;

use App\Domains\Yoshlar\Models\Sector;
use App\Domains\Yoshlar\Support\Translit;
use App\Domains\Yoshlar\Support\YoshlarAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SectorController extends Controller
{
    public function __construct(private readonly YoshlarAccess $access) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'yoshlar.view'), 403, 'Ruxsat yo‘q.');

        return response()->json(['data' => Sector::query()->orderBy('sort_order')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'unique:yoshlar.sectors,code'],
            'name_lat' => ['required', 'string', 'max:300'],
            'name_cyr' => ['nullable', 'string', 'max:300'],
            'sort_order' => ['integer'],
        ]);

        // Kirill nomi berilmasa lotindan hosil qilinadi (keyin tahrirlanadi).
        $data['name_cyr'] = $data['name_cyr'] ?? Translit::toCyr($data['name_lat']);

        return response()->json(['data' => Sector::query()->create($data)], 201);
    }

    public function update(Request $request, string $sector): JsonResponse
    {
        $this->authorizeManage($request);

        $model = Sector::query()->findOrFail($sector);
        $data = $request->validate([
            'name_lat' => ['sometimes', 'string', 'max:300'],
            'name_cyr' => ['sometimes', 'string', 'max:300'],
            'sort_order' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $model->update($data);

        return response()->json(['data' => $model->refresh()]);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($this->access->can($request->user(), 'yoshlar.org.manage'), 403, 'Ruxsat yo‘q.');
    }
}
```

`app/Domains/Yoshlar/Http/Controllers/Api/StaffController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Http\Controllers\Api;

use App\Domains\Yoshlar\Models\Staff;
use App\Domains\Yoshlar\Services\AuditLogger;
use App\Domains\Yoshlar\Support\YoshlarAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffController extends Controller
{
    public function __construct(
        private readonly YoshlarAccess $access,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'yoshlar.view'), 403, 'Ruxsat yo‘q.');

        return response()->json([
            'data' => Staff::query()->with('organization:id,name_lat,type')->orderBy('created_at')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $data = $request->validate([
            'user_id' => ['required', 'uuid'],
            'org_id' => ['required', 'uuid'],
            'position' => ['nullable', 'string', 'max:200'],
            'can_patronage' => ['boolean'],
        ]);

        // Partial unique indeks (bitta faol xodim — bitta tashkilot) buzilib
        // 500 qaytmasin: oldindan tekshirib tushunarli 422 beramiz.
        $exists = Staff::query()->where('user_id', $data['user_id'])->where('is_active', true)->exists();
        abort_if($exists, 422, 'Bu foydalanuvchi allaqachon boshqa tashkilotga biriktirilgan.');

        $staff = Staff::query()->create($data + ['is_active' => true]);
        $this->audit->log($request->user(), 'staff.create', 'staff', $staff->id, $data);

        return response()->json(['data' => $staff], 201);
    }

    public function update(Request $request, string $staff): JsonResponse
    {
        $this->authorizeManage($request);

        $model = Staff::query()->findOrFail($staff);
        $data = $request->validate([
            'position' => ['sometimes', 'nullable', 'string', 'max:200'],
            'can_patronage' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $model->update($data);
        $this->audit->log($request->user(), 'staff.update', 'staff', $model->id, $data);

        return response()->json(['data' => $model->refresh()]);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($this->access->can($request->user(), 'yoshlar.staff.manage'), 403, 'Ruxsat yo‘q.');
    }
}
```

- [ ] **Step 6: `yoshlar:refresh-registry` buyrug'i**

`app/Domains/Yoshlar/Console/Commands/RefreshRegistryCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Console\Commands;

use App\Domains\Yoshlar\Models\Youth;
use Illuminate\Console\Command;

/**
 * Yosh chegarasidan chiqqanlarni arxivga o'tkazadi.
 *
 * NEGA O'CHIRMAYMIZ: yozuv KPI va tarixda qatnashadi (o'tgan yil bandligi,
 * hal etilgan muammolar). Arxiv holati ularni reyestrdan chiqaradi, lekin
 * hisobotda saqlaydi.
 */
class RefreshRegistryCommand extends Command
{
    protected $signature = 'yoshlar:refresh-registry {--dry-run : Faqat sonini ko‘rsatadi}';

    protected $description = 'Yosh chegarasidan chiqqan yozuvlarni arxivga o‘tkazadi';

    public function handle(): int
    {
        $query = Youth::query()
            ->where('registry_status', 'active')
            ->where('birth_date', '<=', now()->subYears(Youth::MAX_AGE + 1)->toDateString());

        $count = $query->count();

        if ((bool) $this->option('dry-run')) {
            $this->info("Arxivga o‘tkaziladi: {$count} ta yozuv (dry-run).");

            return self::SUCCESS;
        }

        $query->update(['registry_status' => 'archived_age']);
        $this->info("Arxivga o‘tkazildi: {$count} ta yozuv.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 7: Reyestr yangilash testi**

`tests/Feature/Yoshlar/YoshlarRegistryRefreshTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Yoshlar;

use App\Domains\Yoshlar\Models\Youth;

class YoshlarRegistryRefreshTest extends YoshlarTestCase
{
    public function test_over_age_youth_is_archived_not_deleted(): void
    {
        $districtId = $this->someDistrictId();

        $old = Youth::query()->create([
            'last_name' => 'Katta', 'first_name' => 'Yosh',
            'birth_date' => now()->subYears(32)->toDateString(), 'gender' => 'erkak',
            'district_id' => $districtId, 'mahalla_id' => $this->someMahallaId($districtId),
        ]);
        $young = Youth::query()->create([
            'last_name' => 'Yosh', 'first_name' => 'Bola',
            'birth_date' => now()->subYears(20)->toDateString(), 'gender' => 'erkak',
            'district_id' => $districtId, 'mahalla_id' => $this->someMahallaId($districtId),
        ]);

        $this->artisan('yoshlar:refresh-registry')->assertSuccessful();

        $this->assertSame('archived_age', $old->fresh()->registry_status);
        $this->assertSame('active', $young->fresh()->registry_status);
        $this->assertNotNull(Youth::query()->find($old->id), 'Yozuv o‘chirib yuborilgan');
    }

    public function test_dry_run_changes_nothing(): void
    {
        $districtId = $this->someDistrictId();
        $old = Youth::query()->create([
            'last_name' => 'Katta', 'first_name' => 'Yosh',
            'birth_date' => now()->subYears(35)->toDateString(), 'gender' => 'ayol',
            'district_id' => $districtId, 'mahalla_id' => $this->someMahallaId($districtId),
        ]);

        $this->artisan('yoshlar:refresh-registry --dry-run')->assertSuccessful();

        $this->assertSame('active', $old->fresh()->registry_status);
    }
}
```

- [ ] **Step 8: Barcha testlarni ishga tushirish**

Run: `C:/php84/php.exe artisan optimize:clear && C:/php84/php.exe artisan test --filter=Yoshlar`
Expected: barcha Yoshlar testlari yashil (taxminan 45+ test).

- [ ] **Step 9: Butun to'plamni tekshirish (regressiya yo'qligi)**

Run: `C:/php84/php.exe artisan test`
Expected: mavjud domenlar testlari ham yashil — yangi domen ularni buzmagan.

- [ ] **Step 10: Commit**

```bash
git add app/Domains/Yoshlar routes/api/yoshlar.php tests/Feature/Yoshlar
git commit -m "feat(yoshlar): to'liq kontekst, statistika, spravochnik API va reyestr yangilash"
```

---

## F1 backend «tayyor» mezoni

- [ ] `C:/php84/php.exe artisan test --filter=Yoshlar` — hammasi yashil
- [ ] `C:/php84/php.exe artisan test` — regressiya yo'q
- [ ] `C:/php84/php.exe artisan route:list --path=yoshlar` — 20+ marshrut ko'rinadi
- [ ] `yoshlar:make-user` bilan har 6 rol uchun sinov hisobi ochiladi
- [ ] Migratsiya ikkinchi marta ishga tushganda xato bermaydi
- [ ] Hech bir javobda `pinfl` maydoni yo'q (reveal-pii dan tashqari)
- [ ] Deploy QILINMAGAN (foydalanuvchi topshirig'igacha)
