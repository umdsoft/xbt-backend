# Qurilish F1 — Poydevor: implementatsiya rejasi

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `qurilish` domeni poydevorini qurish — DB ulanishi, schema + 12 jadval, spravochnik seederlari, RBAC (5 rol) va `/api/qurilish/context` endpointi.

**Architecture:** Murojaat/Advisor domen naqshi aynan takrorlanadi: bitta `kbt` PostgreSQL bazasi ichida alohida `qurilish` schema, `search_path = qurilish,master,public`. Cross-schema FK yo'q — `district_id`/`mahalla_id` uuid + index. RBAC markaziy `auth.user_system_access` orqali; domen ichidagi vakolat `QurilishAccess` + `QurilishScope` da.

**Tech Stack:** PHP 8.4 (`C:\php84\php.exe`), Laravel 11, PostgreSQL 16, PHPUnit (DatabaseTransactions).

## Global Constraints

- PHP: **`C:\php84\php.exe`** — default `php` 8.3 va ishlamaydi. Har artisan/phpunit chaqiruvi shu bilan.
- Ish papkasi: `D:\kadr\platform`.
- **`RefreshDatabase`/`DatabaseMigrations`/`DatabaseTruncation` TAQIQLANGAN** — `tests/TestCase.php` darvozasi testni to'xtatadi. Faqat `DatabaseTransactions` + `$connectionsToTransact`.
- Testlar umumiy dev bazasida (`kbt`) yuradi — hech qanday ma'lumot o'chirilmaydi.
- Migratsiya: `if (config('database.default') !== 'pgsql') return;` bilan boshlanadi; `CREATE SCHEMA IF NOT EXISTS`; mavjud jadval → skip (idempotent, forward-only).
- Cross-schema FK YO'Q. `master.*` ga faqat `uuid` + `index`.
- Barcha PHP fayllar `declare(strict_types=1);` bilan.
- Izohlar o'zbek tilida (mavjud domenlar uslubi).
- **Deploy YO'Q.** Lokal commit ruxsat, `git push` TAQIQLANGAN.
- Har task oxirida commit.

## Fayl tuzilishi

| Fayl | Mas'uliyati |
|---|---|
| `config/database.php` (modify) | `qurilish` ulanishi |
| `database/migrations/2026_08_13_100000_create_qurilish_schema.php` (create) | schema + 12 jadval |
| `app/Domains/Qurilish/Support/QurilishAccess.php` (create) | rol → ruxsat xaritasi, profil o'qish |
| `app/Domains/Qurilish/Support/QurilishScope.php` (create) | so'rovga rol scope'ini qo'llash |
| `app/Domains/Qurilish/Http/Middleware/EnsureQurilish.php` (create) | domen gvardiyasi (403) |
| `app/Domains/Qurilish/Models/*.php` (create ×12) | Eloquent modellari |
| `app/Domains/Qurilish/Http/Controllers/Api/ContextController.php` (create) | `/context` |
| `app/Domains/Qurilish/Database/Seeders/QurilishReferenceSeeder.php` (create) | dastur + soha + boshqarma |
| `routes/api/qurilish.php` (create) | domen route'lari |
| `routes/api.php` (modify) | `require` |
| `bootstrap/app.php` (modify) | `'qurilish'` middleware aliasi |
| `database/seeders/SystemsSeeder.php` (modify) | `qurilish` tizimi |
| `tests/Feature/Qurilish/QurilishTestCase.php` (create) | test poydevori |
| `tests/Feature/Qurilish/QurilishFoundationTest.php` (create) | schema + seeder testlari |
| `tests/Feature/Qurilish/QurilishAccessTest.php` (create) | RBAC testlari |

---

### Task 1: `qurilish` DB ulanishi

**Files:**
- Modify: `config/database.php` (murojaat ulanishidan keyin)
- Modify: `.env` (yangi qator)

**Interfaces:**
- Produces: `DB::connection('qurilish')` — `search_path = qurilish,master,public`

- [x] **Step 1: `config/database.php` ga ulanish qo'shish**

`'murojaat' => [...]` blokidan keyin:

```php
        // QURILISH (davlat dasturlari qurilish/ta'mirlash ijrosi) domeni — operatsion.
        // Obyekt reyestri + bosqich workflow + hujjat + ta'mirtalab reyestr.
        // Murojaat/advisor naqshi: bir xil host/DB, alohida schema (qurilish).
        'qurilish' => [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => env('DB_QURILISH_SEARCH_PATH', 'qurilish,master,public'),
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],
```

- [x] **Step 2: `.env` ga qator qo'shish**

`DB_ADVISOR_SEARCH_PATH` qatoridan keyin:

```
DB_QURILISH_SEARCH_PATH=qurilish,master,public
```

- [x] **Step 3: Ulanish ishlashini tekshirish**

Run: `C:\php84\php.exe artisan tinker --execute="echo DB::connection('qurilish')->selectOne('select current_setting(''search_path'') as p')->p;"`
Expected: `qurilish, master, public`

- [x] **Step 4: Commit**

```bash
git add config/database.php
git commit -m "feat(qurilish): qurilish DB ulanishi (alohida schema)"
```

---

### Task 2: Schema + 12 jadval migratsiyasi

**Files:**
- Create: `database/migrations/2026_08_13_100000_create_qurilish_schema.php`

**Interfaces:**
- Produces: `qurilish` schema va jadvallar: `programs`, `sectors`, `organizations`, `organization_aliases`, `objects`, `object_stages`, `object_monthly_plan`, `object_documents`, `object_audit_log`, `repair_needs`, `repair_need_files`, `profiles`, `import_sessions`

- [x] **Step 1: Migratsiya faylini yaratish**

Spec 4-bo'limidagi sxema bo'yicha. Murojaat migratsiyasidagi `create()` yordamchisi naqshi (mavjud jadval → skip).

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * QURILISH domeni poydevori — `qurilish` schema + 13 jadval.
 *
 * Davlat dasturlari asosidagi qurilish/rekonstruksiya/ta'mirlash ijrosi.
 * Murojaat/advisor naqshi AYNAN: schema jadvallardan OLDIN yaratiladi;
 * faqat PostgreSQL; mavjud jadval -> skip (idempotent, forward-only).
 *
 * district_id/mahalla_id -> master.districts/mahallas (cross-schema FK YO'Q;
 * uuid + index — bulk import uchun xavfsiz). auth.users FK yo'q.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        DB::connection('qurilish')->statement('CREATE SCHEMA IF NOT EXISTS qurilish');
        $schema = Schema::connection('qurilish');

        // --- Spravochniklar ---

        // Davlat dasturlari (ПҚ-393, Drayver, Ochiq byudjet, ПҚ-298, DXSh...).
        $this->create($schema, 'programs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code', 40)->unique();
            $t->string('name_cyr', 300);
            $t->string('name_lat', 300);
            $t->string('legal_basis', 60)->nullable();
            $t->smallInteger('year')->nullable();
            $t->integer('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        // Sohalar — obyekt tarmoq turi; boshqarma shu orqali biriktiriladi.
        $this->create($schema, 'sectors', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code', 40)->unique();
            $t->string('name_cyr', 300);
            $t->string('name_lat', 300);
            $t->uuid('default_department_org_id')->nullable()->index();
            $t->integer('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        // Tashkilotlar — buyurtmachi/loyihachi/pudratchi/boshqarma YAGONA reyestrda.
        // Bitta tashkilot bir vaqtda bir necha rolda bo'lishi mumkin (bayroqlar).
        $this->create($schema, 'organizations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name_cyr', 500);
            $t->string('name_lat', 500);
            $t->string('short_name', 200)->nullable();
            $t->string('inn', 20)->nullable();
            $t->boolean('is_customer')->default(false);
            $t->boolean('is_designer')->default(false);
            $t->boolean('is_contractor')->default(false);
            $t->boolean('is_department')->default(false);
            $t->uuid('district_id')->nullable()->index();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        // Import normalizatsiyasi: xom nom -> kanonik tashkilot.
        // alias_norm = tirnoq/bo'shliq/\n tozalangan, translit, UPPER.
        $this->create($schema, 'organization_aliases', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('organization_id')->index();
            $t->text('alias_raw');
            $t->text('alias_norm');
            $t->timestamp('created_at')->nullable();
            $t->unique('alias_raw');
            $t->index('alias_norm');
        });

        // --- Yadro ---

        $this->create($schema, 'objects', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('external_id', 20)->nullable()->unique();
            $t->uuid('program_id')->nullable()->index();
            $t->uuid('sector_id')->nullable()->index();
            $t->uuid('district_id')->nullable()->index();
            $t->uuid('mahalla_id')->nullable()->index();
            $t->text('name');
            $t->string('work_type', 30)->nullable();
            $t->uuid('customer_org_id')->nullable()->index();
            $t->uuid('designer_org_id')->nullable()->index();
            $t->uuid('contractor_org_id')->nullable()->index();
            $t->uuid('department_org_id')->nullable()->index();
            $t->decimal('limit_amount', 18, 3)->default(0);
            $t->decimal('tender_amount', 18, 3)->default(0);
            $t->decimal('contract_amount', 18, 3)->default(0);
            $t->decimal('disbursed_amount', 18, 3)->default(0);
            $t->decimal('financed_amount', 18, 3)->default(0);
            $t->string('deadline_raw', 60)->nullable();
            $t->date('deadline_date')->nullable()->index();
            $t->smallInteger('deadline_year')->nullable();
            $t->boolean('is_carryover')->default(false);
            $t->string('lifecycle', 20)->default('reja')->index();
            $t->string('current_stage', 30)->nullable()->index();
            $t->boolean('handover_planned')->default(false);
            $t->boolean('handover_done')->default(false);
            $t->text('note')->nullable();
            $t->string('source', 10)->default('manual');
            $t->uuid('created_by')->nullable();
            $t->uuid('updated_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        // 8 bosqichli holat mashinasi — har obyekt uchun 8 qator.
        $this->create($schema, 'object_stages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('object_id')->index();
            $t->string('stage_code', 30);
            $t->string('status', 30)->default('boshlanmagan');
            $t->date('started_at')->nullable();
            $t->date('completed_at')->nullable();
            $t->uuid('responsible_user_id')->nullable();
            $t->text('note')->nullable();
            $t->timestamps();
            $t->unique(['object_id', 'stage_code']);
        });

        $this->create($schema, 'object_monthly_plan', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('object_id')->index();
            $t->smallInteger('year');
            $t->smallInteger('month');
            $t->decimal('planned_amount', 18, 3)->default(0);
            $t->decimal('actual_amount', 18, 3)->default(0);
            $t->timestamps();
            $t->unique(['object_id', 'year', 'month']);
        });

        $this->create($schema, 'object_documents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('object_id')->index();
            $t->string('stage_code', 30)->nullable();
            $t->string('category', 40)->default('boshqa');
            $t->string('original_name', 500);
            $t->string('stored_path', 500);
            $t->string('mime', 150);
            $t->bigInteger('size');
            $t->char('sha256', 64)->index();
            $t->integer('version')->default(1);
            $t->uuid('uploaded_by')->nullable();
            $t->timestamp('uploaded_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        $this->create($schema, 'object_audit_log', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('object_id')->index();
            $t->uuid('user_id')->nullable();
            $t->string('action', 40);
            $t->string('field', 60)->nullable();
            $t->text('old_value')->nullable();
            $t->text('new_value')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->nullable()->index();
        });

        // --- Ta'mirtalab reyestr (2-maqsad) ---

        $this->create($schema, 'repair_needs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('department_org_id')->nullable()->index();
            $t->uuid('sector_id')->nullable()->index();
            $t->uuid('district_id')->nullable()->index();
            $t->uuid('mahalla_id')->nullable()->index();
            $t->text('name');
            $t->text('condition_desc')->nullable();
            $t->decimal('estimated_amount', 18, 3)->nullable();
            $t->smallInteger('target_year')->nullable()->index();
            $t->boolean('funding_source_known')->default(false);
            $t->smallInteger('priority')->default(3);
            $t->string('status', 30)->default('yigilgan')->index();
            $t->uuid('promoted_object_id')->nullable()->index();
            $t->uuid('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        $this->create($schema, 'repair_need_files', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('repair_need_id')->index();
            $t->string('category', 40)->default('boshqa');
            $t->string('original_name', 500);
            $t->string('stored_path', 500);
            $t->string('mime', 150);
            $t->bigInteger('size');
            $t->char('sha256', 64)->index();
            $t->integer('version')->default(1);
            $t->uuid('uploaded_by')->nullable();
            $t->timestamp('uploaded_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        // --- Kirish nazorati ---

        // Profil — markaziy auth.users bilan user_id orqali (FK yo'q).
        $this->create($schema, 'profiles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->unique();
            $t->string('role', 20);
            $t->uuid('organization_id')->nullable()->index();
            $t->uuid('district_id')->nullable()->index();
            $t->string('position', 200)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        $this->create($schema, 'import_sessions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('file_name', 500)->nullable();
            $t->integer('records_count')->default(0);
            $t->uuid('imported_by')->nullable();
            $t->boolean('is_active')->default(true)->index();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        // Forward-only: qo'lda tushirish (dev). Ma'lumot yo'qotmaslik uchun no-op.
    }

    private function create(\Illuminate\Database\Schema\Builder $schema, string $table, \Closure $definition): void
    {
        if ($schema->hasTable($table)) {
            return;
        }

        $schema->create($table, $definition);
    }
};
```

- [x] **Step 2: Migratsiyani ishga tushirish**

Run: `C:\php84\php.exe artisan migrate --force`
Expected: `2026_08_13_100000_create_qurilish_schema ... DONE`

- [x] **Step 3: Idempotentlikni tekshirish (qayta ishga tushirish)**

Run: `C:\php84\php.exe artisan migrate:status | Select-String qurilish`
Expected: `Ran`

Jadvallar sanog'i:
Run: `C:\php84\php.exe artisan tinker --execute="echo DB::connection('qurilish')->table('information_schema.tables')->where('table_schema','qurilish')->count();"`
Expected: `13`

- [x] **Step 4: Commit**

```bash
git add database/migrations/2026_08_13_100000_create_qurilish_schema.php
git commit -m "feat(qurilish): schema + 13 jadval migratsiyasi"
```

---

### Task 3: Eloquent modellari

**Files:**
- Create: `app/Domains/Qurilish/Models/{Program,Sector,Organization,OrganizationAlias,ConstructionObject,ObjectStage,ObjectMonthlyPlan,ObjectDocument,ObjectAuditLog,RepairNeed,RepairNeedFile,QurilishProfile,ImportSession}.php`

**Interfaces:**
- Produces: barcha modellar `protected $connection = 'qurilish';`, `$keyType = 'string'`, `$incrementing = false`, uuid avtomatik (`HasUuids`).
- `ConstructionObject::STAGES` — 8 bosqich kodi tartibda (keyingi tasklar ishlatadi).
- `QurilishProfile` — `profiles` jadvali.

**Eslatma:** `Object` PHP'da zaxiralangan so'z — model nomi **`ConstructionObject`**, jadval `objects`.

- [x] **Step 1: Baza modelini yaratish**

`app/Domains/Qurilish/Models/QurilishModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Qurilish domeni modellari uchun umumiy poydevor: `qurilish` ulanishi + uuid kalit.
 */
abstract class QurilishModel extends Model
{
    use HasUuids;

    protected $connection = 'qurilish';

    public $incrementing = false;

    protected $keyType = 'string';
}
```

- [x] **Step 2: Spravochnik modellari**

`Program.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Models;

/** Davlat dasturi (ПҚ-393, Drayver, Ochiq byudjet, ПҚ-298, DXSh...). */
class Program extends QurilishModel
{
    protected $table = 'programs';

    protected $guarded = [];

    protected $casts = [
        'year' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];
}
```

`Sector.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Soha — obyekt tarmoq turi; boshqarma shu orqali biriktiriladi. */
class Sector extends QurilishModel
{
    protected $table = 'sectors';

    protected $guarded = [];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function defaultDepartment(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'default_department_org_id');
    }
}
```

`Organization.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/** Tashkilot — buyurtmachi/loyihachi/pudratchi/boshqarma yagona reyestrda. */
class Organization extends QurilishModel
{
    protected $table = 'organizations';

    protected $guarded = [];

    protected $casts = [
        'is_customer' => 'boolean',
        'is_designer' => 'boolean',
        'is_contractor' => 'boolean',
        'is_department' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function aliases(): HasMany
    {
        return $this->hasMany(OrganizationAlias::class);
    }
}
```

`OrganizationAlias.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Import normalizatsiyasi: xom tashkilot nomi -> kanonik yozuv. */
class OrganizationAlias extends QurilishModel
{
    protected $table = 'organization_aliases';

    public $timestamps = false;

    protected $guarded = [];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
```

- [x] **Step 3: Yadro modellari**

`ConstructionObject.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Qurilish/ta'mirlash obyekti — domen yadrosi.
 *
 * Nom `Object` emas: `object` PHP'da zaxiralangan so'z. Jadval — `objects`.
 * `lifecycle='qoralama'` — boshqarma kiritgan, hali dasturga kirmagan obyekt.
 */
class ConstructionObject extends QurilishModel
{
    use SoftDeletes;

    protected $table = 'objects';

    protected $guarded = [];

    /** 8 bosqich — TARTIB MUHIM: holat mashinasi shu ketma-ketlikka tayanadi. */
    public const STAGES = [
        'designer_selection',
        'design_estimate',
        'urban_planning',
        'complex_expertise',
        'tender',
        'contract',
        'execution',
        'handover',
    ];

    /** @var array<int, string> */
    public const LIFECYCLES = ['qoralama', 'reja', 'jarayonda', 'tugallangan', 'toxtatilgan'];

    /** @var array<int, string> */
    public const WORK_TYPES = [
        'yangi_qurish',
        'rekonstruksiya',
        'mukammal_tamirlash',
        'kapital_tamirlash',
        'joriy_tamirlash',
    ];

    protected $casts = [
        'limit_amount' => 'decimal:3',
        'tender_amount' => 'decimal:3',
        'contract_amount' => 'decimal:3',
        'disbursed_amount' => 'decimal:3',
        'financed_amount' => 'decimal:3',
        'deadline_date' => 'date',
        'deadline_year' => 'integer',
        'is_carryover' => 'boolean',
        'handover_planned' => 'boolean',
        'handover_done' => 'boolean',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'customer_org_id');
    }

    public function designer(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'designer_org_id');
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'contractor_org_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'department_org_id');
    }

    public function stages(): HasMany
    {
        return $this->hasMany(ObjectStage::class, 'object_id');
    }

    public function monthlyPlan(): HasMany
    {
        return $this->hasMany(ObjectMonthlyPlan::class, 'object_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ObjectDocument::class, 'object_id');
    }

    /** Muddat buzilganmi — SAQLANMAYDI, jonli hisoblanadi. */
    public function getIsOverdueAttribute(): bool
    {
        return $this->deadline_date !== null
            && ! $this->handover_done
            && $this->deadline_date->isBefore(now()->startOfDay());
    }
}
```

`ObjectStage.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Obyekt bosqichi — 8 qator/obyekt. Holat mashinasi qatlami. */
class ObjectStage extends QurilishModel
{
    protected $table = 'object_stages';

    protected $guarded = [];

    /** @var array<int, string> */
    public const STATUSES = [
        'talab_etilmaydi',
        'boshlanmagan',
        'jarayonda',
        'yakunlangan',
        'etiroz_bilan_qaytarilgan',
    ];

    protected $casts = [
        'started_at' => 'date',
        'completed_at' => 'date',
    ];

    public function object(): BelongsTo
    {
        return $this->belongsTo(ConstructionObject::class, 'object_id');
    }
}
```

`ObjectMonthlyPlan.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Models;

/** Oylik ijro grafigi — reja va amaldagi qiymat (mln so'm). */
class ObjectMonthlyPlan extends QurilishModel
{
    protected $table = 'object_monthly_plan';

    protected $guarded = [];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'planned_amount' => 'decimal:3',
        'actual_amount' => 'decimal:3',
    ];
}
```

`ObjectDocument.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

/** Obyekt hujjati — versiyalangan, sha256 bilan dedup qilinadi. */
class ObjectDocument extends QurilishModel
{
    use SoftDeletes;

    protected $table = 'object_documents';

    protected $guarded = [];

    /** @var array<int, string> */
    public const CATEGORIES = ['lsd', 'ekspertiza', 'shartnoma', 'dalolatnoma', 'surat', 'boshqa'];

    protected $casts = [
        'size' => 'integer',
        'version' => 'integer',
        'uploaded_at' => 'datetime',
    ];
}
```

`ObjectAuditLog.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Models;

/** Obyekt o'zgarishlari jurnali — kim, qachon, nimani o'zgartirdi. */
class ObjectAuditLog extends QurilishModel
{
    protected $table = 'object_audit_log';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['created_at' => 'datetime'];
}
```

- [x] **Step 4: Ta'mirtalab va profil modellari**

`RepairNeed.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Ta'mirtalab obyekt — kelgusi dasturlar uchun reyestr (2-maqsad). */
class RepairNeed extends QurilishModel
{
    use SoftDeletes;

    protected $table = 'repair_needs';

    protected $guarded = [];

    /** @var array<int, string> */
    public const STATUSES = ['yigilgan', 'korib_chiqilmoqda', 'dasturga_kiritildi', 'rad_etildi'];

    protected $casts = [
        'estimated_amount' => 'decimal:3',
        'target_year' => 'integer',
        'funding_source_known' => 'boolean',
        'priority' => 'integer',
    ];

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'department_org_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(RepairNeedFile::class, 'repair_need_id');
    }
}
```

`RepairNeedFile.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

/** Ta'mirtalab yozuvga biriktirilgan fayl (surat/hujjat). */
class RepairNeedFile extends QurilishModel
{
    use SoftDeletes;

    protected $table = 'repair_need_files';

    protected $guarded = [];

    protected $casts = [
        'size' => 'integer',
        'version' => 'integer',
        'uploaded_at' => 'datetime',
    ];
}
```

`QurilishProfile.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Qurilish domeni foydalanuvchi profili — rol + scope (tashkilot/tuman). */
class QurilishProfile extends QurilishModel
{
    protected $table = 'profiles';

    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
```

`ImportSession.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Models;

/** Har Excel/CSV import urinishi — audit uchun. */
class ImportSession extends QurilishModel
{
    protected $table = 'import_sessions';

    protected $guarded = [];

    protected $casts = [
        'records_count' => 'integer',
        'is_active' => 'boolean',
    ];
}
```

- [x] **Step 5: Modellar yuklanishini tekshirish**

Run: `C:\php84\php.exe artisan tinker --execute="echo App\Domains\Qurilish\Models\ConstructionObject::count().' | '.count(App\Domains\Qurilish\Models\ConstructionObject::STAGES);"`
Expected: `0 | 8`

- [x] **Step 6: Commit**

```bash
git add app/Domains/Qurilish/Models
git commit -m "feat(qurilish): 13 Eloquent modeli (qurilish ulanishi, uuid)"
```

---

### Task 4: RBAC — `QurilishAccess`, `QurilishScope`, `EnsureQurilish`

**Files:**
- Create: `app/Domains/Qurilish/Support/QurilishAccess.php`
- Create: `app/Domains/Qurilish/Support/QurilishScope.php`
- Create: `app/Domains/Qurilish/Http/Middleware/EnsureQurilish.php`
- Modify: `bootstrap/app.php:32-42` (alias ro'yxati)
- Modify: `app/Providers/AppServiceProvider.php` (singleton)

**Interfaces:**
- Produces:
  - `QurilishAccess::SYSTEM_CODE = 'qurilish'`
  - `QurilishAccess::ROLES = ['qurilish_hokimlik','qurilish_prokuratura','qurilish_buyurtmachi','qurilish_boshqarma','qurilish_admin']`
  - `QurilishAccess::roleFor(User): ?string`
  - `QurilishAccess::isQurilish(User): bool`
  - `QurilishAccess::can(User, string $permission): bool`
  - `QurilishAccess::permissionsFor(User): array<int,string>`
  - `QurilishAccess::profileFor(User): ?QurilishProfile`
  - `QurilishScope::apply(Builder $q, User $u, string $ownerColumn = 'customer_org_id'): Builder`

- [x] **Step 1: `QurilishAccess` yaratish**

```php
<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Support;

use App\Domains\Qurilish\Models\QurilishProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * QURILISH domeni RBAC — markaziy identifikatsiyadan rol/ruxsat (advisor naqshi).
 * Rol = user_system_access.role ('qurilish' tizimi); ruxsat = kod xaritasi.
 *
 * Rollar:
 *   qurilish_hokimlik    — viloyat hokimligi: BARCHA loyihani ko'radi, yozmaydi.
 *   qurilish_prokuratura — viloyat prokuraturasi: xuddi shunday, faqat ko'rish.
 *   qurilish_buyurtmachi — o'ziga biriktirilgan loyihalarni yuritadi.
 *   qurilish_boshqarma   — o'z obyektlarini kiritadi + ta'mirtalab reyestr.
 *   qurilish_admin       — hisob/spravochnik/import boshqaruvi (super).
 */
class QurilishAccess
{
    public const SYSTEM_CODE = 'qurilish';

    /** @var array<int, string> */
    public const ROLES = [
        'qurilish_hokimlik',
        'qurilish_prokuratura',
        'qurilish_buyurtmachi',
        'qurilish_boshqarma',
        'qurilish_admin',
    ];

    /** Faqat ko'ruvchi (yozish taqiqlangan) rollar. */
    public const VIEWER_ROLES = ['qurilish_hokimlik', 'qurilish_prokuratura'];

    /** @var array<string, array<int, string>> */
    private const PERMISSIONS = [
        'qurilish_admin' => ['*'],
        'qurilish_hokimlik' => ['qurilish.view', 'qurilish.export'],
        'qurilish_prokuratura' => ['qurilish.view', 'qurilish.export'],
        'qurilish_buyurtmachi' => [
            'qurilish.view', 'qurilish.export',
            'qurilish.object.update', 'qurilish.stage.update', 'qurilish.document.manage',
        ],
        'qurilish_boshqarma' => [
            'qurilish.view', 'qurilish.export',
            'qurilish.object.create', 'qurilish.object.update',
            'qurilish.document.manage', 'qurilish.repair.manage',
        ],
    ];

    /** @var array<string, ?string> */
    private array $roleCache = [];

    /** @var array<string, ?QurilishProfile> */
    private array $profileCache = [];

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

    public function isQurilish(User $user): bool
    {
        return $this->roleFor($user) !== null;
    }

    public function can(User $user, string $permission): bool
    {
        $perms = $this->permissionsFor($user);

        return in_array('*', $perms, true) || in_array($permission, $perms, true);
    }

    /** @return array<int, string> */
    public function permissionsFor(User $user): array
    {
        $role = $this->roleFor($user);

        return $role === null ? [] : (self::PERMISSIONS[$role] ?? []);
    }

    public function profileFor(User $user): ?QurilishProfile
    {
        if (! array_key_exists($user->id, $this->profileCache)) {
            $this->profileCache[$user->id] = QurilishProfile::query()
                ->where('user_id', $user->id)->where('is_active', true)->first();
        }

        return $this->profileCache[$user->id];
    }

    /** Viloyat darajasi — barcha obyektni ko'radi (scope qo'llanmaydi). */
    public function seesEverything(User $user): bool
    {
        $role = $this->roleFor($user);

        return $role === 'qurilish_admin' || in_array($role, self::VIEWER_ROLES, true);
    }
}
```

- [x] **Step 2: `QurilishScope` yaratish**

```php
<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Rolga qarab so'rovni cheklaydi (IDOR himoyasi).
 *
 *   hokimlik/prokuratura/admin — cheklovsiz (viloyat).
 *   buyurtmachi — objects.customer_org_id = profil tashkiloti.
 *   boshqarma   — objects.department_org_id = profil tashkiloti.
 *
 * Profil yoki tashkilot yo'q bo'lsa — BO'SH natija (fail-closed), cheklovsiz emas.
 */
class QurilishScope
{
    public function __construct(private readonly QurilishAccess $access) {}

    public function apply(Builder $query, User $user): Builder
    {
        if ($this->access->seesEverything($user)) {
            return $query;
        }

        $role = $this->access->roleFor($user);
        $orgId = $this->access->profileFor($user)?->organization_id;

        if ($orgId === null) {
            return $query->whereRaw('1 = 0');
        }

        return match ($role) {
            'qurilish_buyurtmachi' => $query->where('customer_org_id', $orgId),
            'qurilish_boshqarma' => $query->where('department_org_id', $orgId),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /** Ta'mirtalab reyestri uchun scope (department_org_id ustuni). */
    public function applyRepair(Builder $query, User $user): Builder
    {
        if ($this->access->seesEverything($user)) {
            return $query;
        }

        $orgId = $this->access->profileFor($user)?->organization_id;

        return $orgId === null
            ? $query->whereRaw('1 = 0')
            : $query->where('department_org_id', $orgId);
    }
}
```

- [x] **Step 3: `EnsureQurilish` middleware yaratish**

```php
<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Http\Middleware;

use App\Domains\Qurilish\Support\QurilishAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * qurilish domeni gvardiyasi: foydalanuvchi 'qurilish' tizimida (biror rol bilan)
 * ekanini tekshiradi. Rolsiz -> 403. Auth-siz -> 401 (auth:sanctum). advisor naqshi.
 */
class EnsureQurilish
{
    public function __construct(private readonly QurilishAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $this->access->isQurilish($user)) {
            abort(403, 'Бу тизимга рухсат йўқ.');
        }

        return $next($request);
    }
}
```

- [x] **Step 4: Middleware aliasini ro'yxatga olish**

`bootstrap/app.php`, `'murojaat' => ...` qatoridan keyin:

```php
            'qurilish' => \App\Domains\Qurilish\Http\Middleware\EnsureQurilish::class,
```

- [x] **Step 5: `QurilishAccess` ni singleton qilish**

`app/Providers/AppServiceProvider.php` `register()` ichida, `AdvisorAccess` yonida:

```php
        // QurilishAccess — singleton: roleFor()/profileFor() natijasi so'rov davomida
        // keshlanadi (har tekshiruvda auth schema'ga so'rov ketmasin).
        $this->app->singleton(\App\Domains\Qurilish\Support\QurilishAccess::class);
```

- [x] **Step 6: Sintaksis tekshiruvi**

Run: `C:\php84\php.exe artisan route:clear; C:\php84\php.exe artisan config:clear; C:\php84\php.exe -l app/Domains/Qurilish/Support/QurilishAccess.php`
Expected: `No syntax errors detected`

- [x] **Step 7: Commit**

```bash
git add app/Domains/Qurilish/Support app/Domains/Qurilish/Http/Middleware bootstrap/app.php app/Providers/AppServiceProvider.php
git commit -m "feat(qurilish): RBAC — QurilishAccess/Scope + EnsureQurilish gvardiyasi"
```

---

### Task 5: Seederlar — tizim, dasturlar, sohalar, boshqarmalar

**Files:**
- Modify: `database/seeders/SystemsSeeder.php:28-32`
- Create: `app/Domains/Qurilish/Database/Seeders/QurilishReferenceSeeder.php`
- Modify: `routes/console.php` (komanda ro'yxati — sport naqshi)

**Interfaces:**
- Consumes: `Program`, `Sector`, `Organization` modellari (Task 3)
- Produces: `php artisan db:seed --class=...QurilishReferenceSeeder` — 8 dastur, 20 soha, 13 boshqarma yozuvi (idempotent, `code`/`name_cyr` bo'yicha)

- [x] **Step 1: `SystemsSeeder` ga `qurilish` qo'shish**

`private const SYSTEMS` massiviga:

```php
        ['code' => 'murojaat', 'name' => 'Мурожаатлар мониторинги', 'sort_order' => 4],
        ['code' => 'sport', 'name' => 'Спорт ва соғломлаштириш', 'sort_order' => 5],
        ['code' => 'qurilish', 'name' => 'Қурилиш дастурлари ижроси', 'sort_order' => 6],
```

**Diqqat:** mavjud massivda `murojaat`/`sport` bo'lmasa ham qo'shiladi — seeder `code` bo'yicha idempotent, mavjudini o'zgartirmaydi.

- [x] **Step 2: `QurilishReferenceSeeder` yaratish**

```php
<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Database\Seeders;

use App\Domains\Qurilish\Models\Organization;
use App\Domains\Qurilish\Models\Program;
use App\Domains\Qurilish\Models\Sector;
use Illuminate\Database\Seeder;

/**
 * Qurilish domeni spravochniklari: 8 davlat dasturi, 20 soha, boshqarmalar.
 *
 * Idempotent: `code` (dastur/soha) va `name_cyr` (tashkilot) bo'yicha
 * firstOrCreate — qayta ishga tushirish no-op.
 *
 * DIQQAT: boshqarma nomlari BOSHLANG'ICH (spec 13.1) — real Xorazm viloyati
 * boshqarma nomlari bilan keyin almashtiriladi.
 */
class QurilishReferenceSeeder extends Seeder
{
    /** @var array<int, array{code: string, cyr: string, lat: string, basis: ?string}> */
    private const PROGRAMS = [
        ['code' => 'pq393', 'cyr' => 'ПҚ-393-сонли Қарор', 'lat' => 'PQ-393-sonli Qaror', 'basis' => 'ПҚ-393'],
        ['code' => 'drayver', 'cyr' => '«Драйвер лойиҳалар» (1-босқич)', 'lat' => '«Drayver loyihalar» (1-bosqich)', 'basis' => null],
        ['code' => 'open', 'cyr' => '«Ташаббусли бюджет» (1-мавсум)', 'lat' => '«Tashabbusli byudjet» (1-mavsum)', 'basis' => null],
        ['code' => 'ogir_tuman', 'cyr' => '«Оғир» туманлар Дастури', 'lat' => '«Og‘ir» tumanlar Dasturi', 'basis' => 'ПҚ-298'],
        ['code' => 'ogir_mfy', 'cyr' => '«Оғир» маҳаллалар Дастури', 'lat' => '«Og‘ir» mahallalar Dasturi', 'basis' => 'ПҚ-298'],
        ['code' => 'yangi_uzb_tuman', 'cyr' => '«Янги Ўзбекистон қиёфасидаги туман»', 'lat' => '«Yangi O‘zbekiston qiyofasidagi tuman»', 'basis' => 'ПҚ-298'],
        ['code' => 'yangi_uzb_mfy', 'cyr' => '«Янги Ўзбекистон қиёфасидаги маҳалла»', 'lat' => '«Yangi O‘zbekiston qiyofasidagi mahalla»', 'basis' => 'ПҚ-298'],
        ['code' => 'dxsh', 'cyr' => 'Тадбиркорлар маблағлари ҳисобидан ДХШ', 'lat' => 'Tadbirkorlar mablag‘lari hisobidan DXSh', 'basis' => null],
    ];

    /** @var array<int, array{code: string, cyr: string, lat: string, dept: ?string}> */
    private const SECTORS = [
        ['code' => 'umumtalim_maktab', 'cyr' => 'Умумтаълим мактаблари', 'lat' => 'Umumta’lim maktablari', 'dept' => 'talim'],
        ['code' => 'mtt', 'cyr' => 'Мактабгача таълим ташкилотлари', 'lat' => 'Maktabgacha ta’lim tashkilotlari', 'dept' => 'talim'],
        ['code' => 'ijod_maktab', 'cyr' => 'Ижод ва ихтисослаштирилган мактаблар', 'lat' => 'Ijod va ixtisoslashtirilgan maktablar', 'dept' => 'talim'],
        ['code' => 'sogliqni_saqlash', 'cyr' => 'Соғлиқни сақлаш ва тиббий-ижтимоий муассасалар', 'lat' => 'Sog‘liqni saqlash va tibbiy-ijtimoiy muassasalar', 'dept' => 'sogliq'],
        ['code' => 'sport', 'cyr' => 'Спортни ривожлантириш объектлари', 'lat' => 'Sportni rivojlantirish obyektlari', 'dept' => 'sport'],
        ['code' => 'madaniyat', 'cyr' => 'Маданият ва санъат', 'lat' => 'Madaniyat va san’at', 'dept' => 'madaniyat'],
        ['code' => 'turizm', 'cyr' => 'Туризм инфратузилмаси объектлари', 'lat' => 'Turizm infratuzilmasi obyektlari', 'dept' => 'turizm'],
        ['code' => 'madaniy_meros', 'cyr' => 'Маданий мерос', 'lat' => 'Madaniy meros', 'dept' => 'meros'],
        ['code' => 'oliy_talim', 'cyr' => 'Олий таълим муассасалари', 'lat' => 'Oliy ta’lim muassasalari', 'dept' => null],
        ['code' => 'suv_kanalizatsiya', 'cyr' => 'Сув таъминоти ва канализация', 'lat' => 'Suv ta’minoti va kanalizatsiya', 'dept' => 'quykx'],
        ['code' => 'issiqlik', 'cyr' => 'Иссиқлик таъминоти', 'lat' => 'Issiqlik ta’minoti', 'dept' => 'quykx'],
        ['code' => 'avtoyol', 'cyr' => 'Автомобиль йўллари ва кўприклар', 'lat' => 'Avtomobil yo‘llari va ko‘priklar', 'dept' => 'yol'],
        ['code' => 'ichki_yol', 'cyr' => 'Ички йўллар ва кўчалар', 'lat' => 'Ichki yo‘llar va ko‘chalar', 'dept' => 'yol'],
        ['code' => 'irrigatsiya', 'cyr' => 'Ирригация тармоқлари ва иншоотлари', 'lat' => 'Irrigatsiya tarmoqlari va inshootlari', 'dept' => 'suv'],
        ['code' => 'melioratsiya', 'cyr' => 'Мелиорация тармоқлари ва иншоотлари', 'lat' => 'Melioratsiya tarmoqlari va inshootlari', 'dept' => 'suv'],
        ['code' => 'ormon', 'cyr' => 'Ўрмон хўжалиги объектлари', 'lat' => 'O‘rmon xo‘jaligi obyektlari', 'dept' => 'ormon'],
        ['code' => 'mudofaa_huquq', 'cyr' => 'Мудофаа ва ҳуқуқни муҳофаза қилувчи органлар', 'lat' => 'Mudofaa va huquqni muhofaza qiluvchi organlar', 'dept' => null],
        ['code' => 'elektr', 'cyr' => 'Электр таъминоти объектлари', 'lat' => 'Elektr ta’minoti obyektlari', 'dept' => 'elektr'],
        ['code' => 'maxsus_zona', 'cyr' => 'Махсус иқтисодий зоналар', 'lat' => 'Maxsus iqtisodiy zonalar', 'dept' => 'investitsiya'],
        ['code' => 'boshqa', 'cyr' => 'Ободонлаштириш ва бошқа', 'lat' => 'Obodonlashtirish va boshqa', 'dept' => null],
    ];

    /** @var array<string, array{cyr: string, lat: string}> */
    private const DEPARTMENTS = [
        'talim' => ['cyr' => 'Мактабгача ва мактаб таълими бошқармаси', 'lat' => 'Maktabgacha va maktab ta’limi boshqarmasi'],
        'sogliq' => ['cyr' => 'Соғлиқни сақлаш бошқармаси', 'lat' => 'Sog‘liqni saqlash boshqarmasi'],
        'sport' => ['cyr' => 'Жисмоний тарбия ва спорт бошқармаси', 'lat' => 'Jismoniy tarbiya va sport boshqarmasi'],
        'madaniyat' => ['cyr' => 'Маданият бошқармаси', 'lat' => 'Madaniyat boshqarmasi'],
        'turizm' => ['cyr' => 'Туризм бошқармаси', 'lat' => 'Turizm boshqarmasi'],
        'meros' => ['cyr' => 'Маданий мерос агентлиги', 'lat' => 'Madaniy meros agentligi'],
        'quykx' => ['cyr' => 'Қурилиш ва уй-жой коммунал хўжалиги бошқармаси', 'lat' => 'Qurilish va uy-joy kommunal xo‘jaligi boshqarmasi'],
        'yol' => ['cyr' => 'Автомобиль йўллари бошқармаси', 'lat' => 'Avtomobil yo‘llari boshqarmasi'],
        'suv' => ['cyr' => 'Сув хўжалиги бошқармаси', 'lat' => 'Suv xo‘jaligi boshqarmasi'],
        'ormon' => ['cyr' => 'Ўрмон хўжалиги бошқармаси', 'lat' => 'O‘rmon xo‘jaligi boshqarmasi'],
        'elektr' => ['cyr' => '«Ҳудудий электр тармоқлари» АЖ', 'lat' => '«Hududiy elektr tarmoqlari» AJ'],
        'investitsiya' => ['cyr' => 'Инвестициялар ва ташқи савдо бошқармаси', 'lat' => 'Investitsiyalar va tashqi savdo boshqarmasi'],
    ];

    public function run(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        foreach (self::PROGRAMS as $i => $p) {
            Program::query()->firstOrCreate(
                ['code' => $p['code']],
                [
                    'name_cyr' => $p['cyr'],
                    'name_lat' => $p['lat'],
                    'legal_basis' => $p['basis'],
                    'year' => 2026,
                    'sort_order' => $i + 1,
                    'is_active' => true,
                ],
            );
        }

        $deptIds = [];
        foreach (self::DEPARTMENTS as $key => $d) {
            $deptIds[$key] = Organization::query()->firstOrCreate(
                ['name_cyr' => $d['cyr']],
                ['name_lat' => $d['lat'], 'is_department' => true, 'is_active' => true],
            )->id;
        }

        foreach (self::SECTORS as $i => $s) {
            Sector::query()->firstOrCreate(
                ['code' => $s['code']],
                [
                    'name_cyr' => $s['cyr'],
                    'name_lat' => $s['lat'],
                    'default_department_org_id' => $s['dept'] === null ? null : $deptIds[$s['dept']],
                    'sort_order' => $i + 1,
                    'is_active' => true,
                ],
            );
        }
    }
}
```

- [x] **Step 3: Seederlarni ishga tushirish**

Run:
```
C:\php84\php.exe artisan db:seed --class=Database\\Seeders\\SystemsSeeder --force
C:\php84\php.exe artisan db:seed --class=App\\Domains\\Qurilish\\Database\\Seeders\\QurilishReferenceSeeder --force
```
Expected: ikkalasi ham `DONE` (xatosiz)

- [x] **Step 4: Natijani tekshirish**

Run: `C:\php84\php.exe artisan tinker --execute="echo App\Domains\Qurilish\Models\Program::count().'/'.App\Domains\Qurilish\Models\Sector::count().'/'.App\Domains\Qurilish\Models\Organization::where('is_department',true)->count().' sys='.DB::connection('auth')->table('systems')->where('code','qurilish')->count();"`
Expected: `8/20/12 sys=1`

- [x] **Step 5: Idempotentlikni tekshirish (qayta seed)**

Run: `C:\php84\php.exe artisan db:seed --class=App\\Domains\\Qurilish\\Database\\Seeders\\QurilishReferenceSeeder --force`
So'ng: yuqoridagi Step 4 buyrug'i.
Expected: yana `8/20/12 sys=1` (dublikat yo'q)

- [x] **Step 6: Commit**

```bash
git add database/seeders/SystemsSeeder.php app/Domains/Qurilish/Database
git commit -m "feat(qurilish): tizim reyestri + 8 dastur, 20 soha, 12 boshqarma seederi"
```

---

### Task 6: `/api/qurilish/context` endpointi

**Files:**
- Create: `app/Domains/Qurilish/Http/Controllers/Api/ContextController.php`
- Create: `routes/api/qurilish.php`
- Modify: `routes/api.php` (oxirgi `require` dan keyin)

**Interfaces:**
- Consumes: `QurilishAccess` (Task 4), `Program`/`Sector`/`Organization` (Task 3)
- Produces: `GET /api/qurilish/context` → JSON:
  ```
  { user: {id, name, login}, role, permissions[], scope: {organization_id, organization_name, district_id},
    reference: { programs[{id,code,name}], sectors[{id,code,name}], districts[{id,name}],
                 stages[{code,name}], lifecycles[], work_types[] } }
  ```
  Nomlar **lotin** alifbosida (`name_lat`).

- [x] **Step 1: Failing testni yozish**

`tests/Feature/Qurilish/QurilishTestCase.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Qurilish;

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
            'name' => 'Синов '.substr($userId, 0, 4), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
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

    /** Test uchun tashkilot yaratadi. */
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
```

`tests/Feature/Qurilish/QurilishAccessTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Qurilish;

/**
 * RBAC: qurilish gvardiyasi (401/403), rol ruxsatlari, context javobi.
 */
class QurilishAccessTest extends QurilishTestCase
{
    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/qurilish/context')->assertStatus(401);
    }

    public function test_outsider_gets_403(): void
    {
        $this->actingAs($this->makeOutsider(), 'sanctum')
            ->getJson('/api/qurilish/context')->assertStatus(403);
    }

    public function test_hokimlik_sees_context_with_view_permission(): void
    {
        $this->actingAs($this->makeUser('qurilish_hokimlik'), 'sanctum')
            ->getJson('/api/qurilish/context')
            ->assertOk()
            ->assertJsonPath('role', 'qurilish_hokimlik')
            ->assertJsonStructure([
                'user' => ['id', 'name', 'login'],
                'role', 'permissions',
                'scope' => ['organization_id', 'organization_name', 'district_id'],
                'reference' => ['programs', 'sectors', 'districts', 'stages', 'lifecycles', 'work_types'],
            ]);
    }

    public function test_viewer_roles_cannot_write(): void
    {
        foreach (['qurilish_hokimlik', 'qurilish_prokuratura'] as $role) {
            $this->actingAs($this->makeUser($role), 'sanctum')
                ->getJson('/api/qurilish/context')
                ->assertOk()
                ->assertJsonMissing(['permissions' => ['qurilish.object.create']]);
        }
    }

    public function test_buyurtmachi_scope_is_reported(): void
    {
        $orgId = $this->makeOrganization('Синов буюртмачи', ['is_customer' => true]);

        $this->actingAs($this->makeUser('qurilish_buyurtmachi', $orgId), 'sanctum')
            ->getJson('/api/qurilish/context')
            ->assertOk()
            ->assertJsonPath('scope.organization_id', $orgId)
            ->assertJsonPath('scope.organization_name', 'Синов буюртмачи');
    }

    public function test_reference_contains_eight_programs_and_stages(): void
    {
        $res = $this->actingAs($this->makeUser('qurilish_admin'), 'sanctum')
            ->getJson('/api/qurilish/context')->assertOk();

        $this->assertGreaterThanOrEqual(8, count($res->json('reference.programs')));
        $this->assertCount(8, $res->json('reference.stages'));
        $this->assertGreaterThanOrEqual(13, count($res->json('reference.districts')));
    }
}
```

- [x] **Step 2: Testni ishga tushirib, muvaffaqiyatsizligini tasdiqlash**

Run: `C:\php84\php.exe artisan test --filter=QurilishAccessTest`
Expected: FAIL — `404 Not Found` (route hali yo'q)

- [x] **Step 3: `ContextController` yozish**

```php
<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Http\Controllers\Api;

use App\Domains\Qurilish\Models\ConstructionObject;
use App\Domains\Qurilish\Models\ObjectStage;
use App\Domains\Qurilish\Models\Program;
use App\Domains\Qurilish\Models\Sector;
use App\Domains\Qurilish\Support\QurilishAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * `/api/qurilish/context` — SPA ishga tushganda BIR marta chaqiriladi:
 * foydalanuvchi, roli, ruxsatlari, ko'rish doirasi va spravochniklar.
 *
 * Ma'lumot LOTIN alifbosida (name_lat) — spec 9-bo'lim talabi.
 */
class ContextController extends Controller
{
    /** Bosqich kodlari -> lotin nomlari (SPA'da tarjima kerak emas). */
    private const STAGE_NAMES = [
        'designer_selection' => 'Loyihachini aniqlash',
        'design_estimate' => 'Loyiha-smeta hujjatlari',
        'urban_planning' => 'Shaharsozlik hujjatlari ekspertizasi',
        'complex_expertise' => 'Kompleks ekspertiza',
        'tender' => 'Tender savdolari',
        'contract' => 'Shartnoma',
        'execution' => 'Ijro',
        'handover' => 'Topshirish',
    ];

    public function __invoke(Request $request, QurilishAccess $access): JsonResponse
    {
        $user = $request->user();
        $profile = $access->profileFor($user);
        $organization = $profile?->organization;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'login' => $user->login,
            ],
            'role' => $access->roleFor($user),
            'permissions' => $access->permissionsFor($user),
            'sees_everything' => $access->seesEverything($user),
            'scope' => [
                'organization_id' => $profile?->organization_id,
                'organization_name' => $organization?->name_cyr,
                'district_id' => $profile?->district_id,
            ],
            'reference' => [
                'programs' => Program::query()->where('is_active', true)
                    ->orderBy('sort_order')
                    ->get(['id', 'code', 'name_lat as name'])->all(),
                'sectors' => Sector::query()->where('is_active', true)
                    ->orderBy('sort_order')
                    ->get(['id', 'code', 'name_lat as name'])->all(),
                'districts' => DB::connection('master')->table('districts')
                    ->orderBy('sort_order')
                    ->get(['id', 'name_lat as name', 'soato_code'])->all(),
                'stages' => array_map(
                    fn (string $code, int $i): array => [
                        'code' => $code,
                        'order' => $i + 1,
                        'name' => self::STAGE_NAMES[$code],
                    ],
                    ConstructionObject::STAGES,
                    array_keys(ConstructionObject::STAGES),
                ),
                'stage_statuses' => ObjectStage::STATUSES,
                'lifecycles' => ConstructionObject::LIFECYCLES,
                'work_types' => ConstructionObject::WORK_TYPES,
            ],
        ]);
    }
}
```

- [x] **Step 4: Route faylini yaratish**

`routes/api/qurilish.php`:

```php
<?php

declare(strict_types=1);

use App\Domains\Qurilish\Http\Controllers\Api\ContextController;
use Illuminate\Support\Facades\Route;

/*
 * QURILISH domeni API (davlat dasturlari qurilish/ta'mirlash ijrosi).
 * auth:sanctum + qurilish gvardiyasi. Auth-siz -> 401; rolsiz -> 403.
 * Rol ichidagi vakolat (buyurtmachi/boshqarma scope) QurilishScope da.
 */
Route::middleware(['auth:sanctum', 'qurilish'])
    ->prefix('qurilish')
    ->name('api.qurilish.')
    ->group(function () {
        Route::get('/context', ContextController::class)->name('context');
    });
```

- [x] **Step 5: `routes/api.php` ga ulash**

`require __DIR__.'/api/sport.php';` qatoridan keyin (yoki oxirgi `require` dan keyin):

```php
require __DIR__.'/api/qurilish.php';
```

- [x] **Step 6: Testni qayta ishga tushirish**

Run: `C:\php84\php.exe artisan route:clear; C:\php84\php.exe artisan test --filter=QurilishAccessTest`
Expected: `OK (5 tests)` — barchasi PASS

- [x] **Step 7: Commit**

```bash
git add app/Domains/Qurilish/Http/Controllers routes/api/qurilish.php routes/api.php tests/Feature/Qurilish
git commit -m "feat(qurilish): /context endpointi + RBAC feature testlari"
```

---

### Task 7: Poydevor testlari va F1 yakuniy tekshiruvi

**Files:**
- Create: `tests/Feature/Qurilish/QurilishFoundationTest.php`

**Interfaces:**
- Consumes: `QurilishTestCase` (Task 6), barcha modellar (Task 3), seeder (Task 5)

- [x] **Step 1: Failing testni yozish**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Qurilish;

use App\Domains\Qurilish\Models\ConstructionObject;
use App\Domains\Qurilish\Models\ObjectStage;
use App\Domains\Qurilish\Models\Organization;
use App\Domains\Qurilish\Models\Program;
use App\Domains\Qurilish\Models\Sector;
use App\Domains\Qurilish\Support\QurilishAccess;
use App\Domains\Qurilish\Support\QurilishScope;
use Illuminate\Support\Facades\DB;

/**
 * F1 poydevori: schema, spravochniklar, modellar, scope (IDOR) va
 * hisoblanadigan `is_overdue` xossasi.
 */
class QurilishFoundationTest extends QurilishTestCase
{
    public function test_schema_has_all_tables(): void
    {
        $tables = DB::connection('qurilish')->table('information_schema.tables')
            ->where('table_schema', 'qurilish')->pluck('table_name')->all();

        foreach ([
            'programs', 'sectors', 'organizations', 'organization_aliases',
            'objects', 'object_stages', 'object_monthly_plan', 'object_documents',
            'object_audit_log', 'repair_needs', 'repair_need_files',
            'profiles', 'import_sessions',
        ] as $table) {
            $this->assertContains($table, $tables, "Jadval yo'q: {$table}");
        }
    }

    public function test_reference_data_is_seeded(): void
    {
        $this->assertGreaterThanOrEqual(8, Program::query()->count());
        $this->assertGreaterThanOrEqual(20, Sector::query()->count());
        $this->assertGreaterThanOrEqual(12, Organization::query()->where('is_department', true)->count());
    }

    public function test_every_sector_with_department_resolves_it(): void
    {
        $sector = Sector::query()->where('code', 'umumtalim_maktab')->firstOrFail();

        $this->assertNotNull($sector->default_department_org_id);
        $this->assertTrue($sector->defaultDepartment->is_department);
    }

    public function test_object_is_overdue_is_computed_not_stored(): void
    {
        $object = ConstructionObject::query()->create([
            'name' => 'Синов объекти',
            'deadline_date' => now()->subDay()->toDateString(),
            'handover_done' => false,
        ]);

        $this->assertTrue($object->is_overdue);

        $object->update(['handover_done' => true]);
        $this->assertFalse($object->fresh()->is_overdue);

        // Ustun sifatida SAQLANMAYDI.
        $columns = DB::connection('qurilish')->getSchemaBuilder()->getColumnListing('objects');
        $this->assertNotContains('is_overdue', $columns);
    }

    public function test_stage_unique_constraint(): void
    {
        $object = ConstructionObject::query()->create(['name' => 'Синов']);
        ObjectStage::query()->create(['object_id' => $object->id, 'stage_code' => 'tender']);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        ObjectStage::query()->create(['object_id' => $object->id, 'stage_code' => 'tender']);
    }

    public function test_scope_isolates_buyurtmachi_from_other_orgs(): void
    {
        $orgA = $this->makeOrganization('Ташкилот А', ['is_customer' => true]);
        $orgB = $this->makeOrganization('Ташкилот Б', ['is_customer' => true]);

        ConstructionObject::query()->create(['name' => 'A объекти', 'customer_org_id' => $orgA]);
        ConstructionObject::query()->create(['name' => 'Б объекти', 'customer_org_id' => $orgB]);

        $userA = $this->makeUser('qurilish_buyurtmachi', $orgA);
        $scope = app(QurilishScope::class);

        $visible = $scope->apply(ConstructionObject::query(), $userA)->pluck('name')->all();

        $this->assertContains('A объекти', $visible);
        $this->assertNotContains('Б объекти', $visible);
    }

    public function test_scope_without_profile_org_returns_nothing(): void
    {
        ConstructionObject::query()->create(['name' => 'Кўринмаслиги керак']);

        // Tashkilotsiz buyurtmachi — fail-closed.
        $user = $this->makeUser('qurilish_buyurtmachi', null);
        $count = app(QurilishScope::class)->apply(ConstructionObject::query(), $user)->count();

        $this->assertSame(0, $count);
    }

    public function test_viewer_sees_everything(): void
    {
        ConstructionObject::query()->create(['name' => 'Ҳар ким кўради']);

        foreach (['qurilish_hokimlik', 'qurilish_prokuratura', 'qurilish_admin'] as $role) {
            $user = $this->makeUser($role);
            $this->assertTrue(app(QurilishAccess::class)->seesEverything($user));
            $this->assertGreaterThan(
                0,
                app(QurilishScope::class)->apply(ConstructionObject::query(), $user)->count(),
            );
        }
    }
}
```

- [x] **Step 2: Testni ishga tushirish**

Run: `C:\php84\php.exe artisan test --filter=QurilishFoundationTest`
Expected: `OK (8 tests)` — barchasi PASS (kod Task 2–5 da yozilgan)

Agar `test_object_is_overdue_is_computed_not_stored` FAIL bo'lsa — `ConstructionObject::getIsOverdueAttribute()` mavjudligini tekshiring (Task 3, Step 3).

- [x] **Step 3: BUTUN test to'plamini ishga tushirish (regressiya yo'qligini tasdiqlash)**

Run: `C:\php84\php.exe artisan test`
Expected: barcha mavjud testlar (Advisor/Auth/Mahalla/Murojaat/Sport) + yangi Qurilish testlari PASS. Yangi FAIL bo'lmasin.

- [x] **Step 4: Commit**

```bash
git add tests/Feature/Qurilish/QurilishFoundationTest.php
git commit -m "test(qurilish): F1 poydevor testlari (schema, seeder, scope IDOR)"
```

---

## Bajarilgandan keyin

F1 tugagach quyidagilar tayyor bo'ladi:

- `qurilish` schema + 13 jadval (idempotent migratsiya)
- 8 dastur, 20 soha, 12 boshqarma spravochnigi
- 5 rolli RBAC + IDOR-himoyalangan scope
- `/api/qurilish/context` endpointi
- 13 feature test

**Keyingi qadam:** F2 (ETL) rejasi — `docs/superpowers/plans/2026-08-13-qurilish-f2-etl.md`.

**Deploy YO'Q** — barcha o'zgarish lokal commitda qoladi.
