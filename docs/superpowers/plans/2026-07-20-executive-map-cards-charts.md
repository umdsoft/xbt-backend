# Rahbariyat dashboard'i — xarita, KPI cardlar, mahalla statistikasi (reja)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rahbariyat dashboard'iga o'zgarishlar xaritasi, 4 ta KPI card va mahalla sahifasiga uchta statistika bloki qo'shish.

**Architecture:** Backend'da `ExecutiveStats` ga `summary` qo'shiladi, mahalla statistikasi uchun yangi `ExecutiveMahallaStats` servisi va GeoJSON uchun alohida kontroller yoziladi. Frontend'da Leaflet faqat bitta komponentda (`ChangeMap.vue`) ishlatiladi, diagrammalar esa mavjud uslubda qo'lda SVG/CSS bilan yoziladi.

**Tech Stack:** Laravel 13 / PHP 8.4 / PostgreSQL 16 + PostGIS · Vue 3.5 + TypeScript + Pinia + Tailwind v4 · Leaflet 1.9 · PHPUnit

**Spec:** `docs/superpowers/specs/2026-07-20-executive-map-cards-charts-design.md`

## Global Constraints

- PHP har doim `C:\php84\php.exe`. Backend: `D:\kadr\platform`. Frontend: `D:\kadr\mahalla` (**git repo EMAS** — commit qilinmaydi).
- Branch: `feat/mahalla-executive-dashboard`. `git push` YO'Q.
- **`kbt` — UMUMIY DEV BAZASI.** `migrate`, `migrate:fresh`, `db:wipe`, `migrate:rollback` HECH QACHON yurgizilmaydi. Testlar `DatabaseTransactions` bilan; `tests/TestCase.php` dagi darvoza `RefreshDatabase` ni to'sadi.
- Ko'rsatiladigan barcha matn — **kirill o'zbek**. Kod izohlari — o'zbek lotin, NEGA-ni tushuntiradi.
- Zona kodlari: `facade`, `kitchen`, `toilet`, `yard`. Statuslar: `needs_work`, `in_progress`, `completed`, `good`.
- Davrlar `Asia/Tashkent`, hafta dushanbadan. `observed_at` bazada UTC (`timestamp without time zone`).
- Sanoq har doim `COUNT(DISTINCT house_id)`, filtr `is_change = true`.
- Schema'lararo `JOIN` yo'q: `master` va `mahalla` so'rovlari alohida.
- Diagramma/UI kutubxonasi qo'shilmaydi. Yagona istisno — `leaflet` va `@types/leaflet` (faqat xarita uchun).
- Barcha yangi endpointlar `mahalla.viewer` gvardiyasida.

## Fayl tuzilishi

**Yangi (backend):**
- `app/Domains/Mahalla/Services/ExecutiveMahallaStats.php` — mahalla sahifasi uchun uch mustaqil so'rov (dinamika, zona holati, so'nggi o'zgarishlar). `ExecutiveStats` tuman kesimi bilan band, aralashtirilmaydi.
- `app/Domains/Mahalla/Http/Controllers/Api/Executive/DistrictGeoJsonController.php`

**Yangi (frontend):**
- `src/components/executive/KpiCard.vue` — bitta ko'rsatkich kartasi
- `src/components/executive/ChangeMap.vue` — **Leaflet'ning yagona ishlatilish joyi**
- `src/components/executive/DynamicsChart.vue` — 30 kunlik ustunli diagramma
- `src/components/executive/ZoneStatusBars.vue` — zona holati to'plangan chiziqlari

**O'zgaradi:**
- `app/Domains/Mahalla/Services/ExecutiveStats.php` — `district()` ga `summary` qo'shiladi
- `Api/Executive/DistrictDashboardController.php`, `MahallaDashboardController.php`
- `routes/api/mahalla.php`, `tests/Feature/Mahalla/ExecutiveDashboardTest.php`
- `src/types/index.ts`, `src/stores/executive.ts`
- `src/pages/executive/ExecutiveDistrict.vue`, `ExecutiveMahalla.vue`
- `package.json`

**Vazifalar tartibi:** 1 (summary) → 2 (GeoJSON) → 3 (mahalla stats) → 4 (frontend poydevor) → 5 (cardlar + xarita) → 6 (mahalla diagrammalari). Backend to'liq tugagach frontend boshlanadi.

---

## Task 1: KPI cardlar uchun `summary`

**Files:**
- Modify: `app/Domains/Mahalla/Services/ExecutiveStats.php`
- Modify: `app/Domains/Mahalla/Http/Controllers/Api/Executive/DistrictDashboardController.php`
- Modify: `tests/Feature/Mahalla/ExecutiveDashboardTest.php`

**Interfaces:**
- Consumes: `ExecutiveStats::period()`, `::district()` (mavjud); test yordamchilari `districtId()`, `mahallaWithStreet()`, `makeHouse()`, `makeObservation()`, `makeUser()`
- Produces: `district()` javobiga `summary` kaliti: `array{changed_today:int, changed_week:int, active_mahallas:int, total_mahallas:int, pending_reviews:int}`

- [ ] **Step 1: Yiqiladigan testlarni yozish**

`tests/Feature/Mahalla/ExecutiveDashboardTest.php` sinfiga qo'shing:

```php
    public function test_summary_counts_households_once_across_zones(): void
    {
        [$mahallaId, $streetId] = $this->mahallaWithStreet();
        $stats = app(\App\Domains\Mahalla\Services\ExecutiveStats::class);

        $before = $stats->district($this->districtId())['summary'];

        // BITTA uy, IKKI xil zonada o'zgargan. Card "xonadon" sanaydi,
        // shuning uchun natija 2 emas, 1 bo'lishi shart.
        $house = $this->makeHouse($mahallaId, $streetId);
        $this->makeObservation($house, 'yard', now()->subHours(2), isChange: true);
        $this->makeObservation($house, 'facade', now()->subHours(1), isChange: true);

        $after = $stats->district($this->districtId())['summary'];

        $this->assertSame(1, $after['changed_week'] - $before['changed_week'],
            'ikki zonada o\'zgargan bitta uy 1 marta sanaladi');
        $this->assertSame(1, $after['changed_today'] - $before['changed_today']);
    }

    public function test_summary_active_mahallas_counts_distinct_mahallas(): void
    {
        [$mahallaId, $streetId] = $this->mahallaWithStreet();
        $stats = app(\App\Domains\Mahalla\Services\ExecutiveStats::class);

        $before = $stats->district($this->districtId())['summary'];

        // Bitta mahallada IKKI uy o'zgardi -> faol mahalla +1 (2 emas)
        foreach ([1, 2] as $n) {
            $house = $this->makeHouse($mahallaId, $streetId);
            $this->makeObservation($house, 'yard', now()->subHour(), isChange: true);
        }

        $after = $stats->district($this->districtId())['summary'];

        $this->assertSame(1, $after['active_mahallas'] - $before['active_mahallas']);
        $this->assertSame(
            DB::connection('master')->table('mahallas')->where('district_id', $this->districtId())->count(),
            $after['total_mahallas'],
        );
    }

    public function test_summary_pending_reviews_counts_only_unreviewed_flagged(): void
    {
        [$mahallaId, $streetId] = $this->mahallaWithStreet();
        $stats = app(\App\Domains\Mahalla\Services\ExecutiveStats::class);

        $before = $stats->district($this->districtId())['summary']['pending_reviews'];

        $house = $this->makeHouse($mahallaId, $streetId);
        // flagged + tekshirilmagan -> sanaladi
        $this->makeObservation($house, 'toilet', now()->subHour(), isChange: false);
        // auto_confirmed -> sanalmaydi
        $this->makeObservation($house, 'kitchen', now()->subHour(), isChange: true);

        $after = $stats->district($this->districtId())['summary']['pending_reviews'];

        $this->assertSame(1, $after - $before, 'faqat flagged va reviewed_by IS NULL sanaladi');
    }
```

- [ ] **Step 2: Testlarni yurgizib, yiqilishini tasdiqlash**

```powershell
Push-Location D:\kadr\platform
& C:\php84\php.exe artisan test --filter=test_summary_counts_households_once_across_zones
Pop-Location
```

Kutilgan natija: FAIL — `Undefined array key "summary"`

- [ ] **Step 3: `ExecutiveStats` ga `summary` qo'shish**

`app/Domains/Mahalla/Services/ExecutiveStats.php` — `district()` metodining `return` blokini almashtiring:

```php
        return [
            'rows' => $rows,
            'totals' => ['households' => $totalHouseholds, 'zones' => $totals],
            'unassigned_households' => $this->unassignedHouseholds($districtId),
            'summary' => $this->summary($districtId, $period, count($mahallas)),
        ];
```

va sinfga ikkita private metod qo'shing (`unassignedHouseholds()` dan keyin):

```php
    /**
     * KPI cardlar uchun yig'ma ko'rsatkichlar.
     *
     * DIQQAT: `changed_week` ni zona bo'yicha sonlarni qo'shib olib bo'LMAYDI —
     * ikki zonasi o'zgargan bitta uy ikki marta sanalib, xonadon sonidan oshib
     * ketardi. Shuning uchun zonadan qat'i nazar alohida DISTINCT so'rov.
     *
     * @param  array{today_start_utc: Carbon, week_start_utc: Carbon}  $period
     * @return array{changed_today: int, changed_week: int, active_mahallas: int,
     *               total_mahallas: int, pending_reviews: int}
     */
    private function summary(string $districtId, array $period, int $totalMahallas): array
    {
        $row = DB::connection('mahalla')->table('zone_observations as o')
            ->join('houses as h', 'h.id', '=', 'o.house_id')
            ->where('h.district_id', $districtId)
            ->where('o.is_change', true)
            ->where('o.observed_at', '>=', $period['week_start_utc'])
            ->selectRaw('count(distinct o.house_id) as week_count')
            ->selectRaw(
                'count(distinct case when o.observed_at >= ? then o.house_id end) as today_count',
                [$period['today_start_utc']],
            )
            ->selectRaw('count(distinct h.mahalla_id) as active_mahallas')
            ->first();

        return [
            'changed_today' => (int) ($row->today_count ?? 0),
            'changed_week' => (int) ($row->week_count ?? 0),
            'active_mahallas' => (int) ($row->active_mahallas ?? 0),
            'total_mahallas' => $totalMahallas,
            'pending_reviews' => $this->pendingReviews($districtId),
        ];
    }

    /** Masul hodim tekshiruvini kutayotgan kuzatuvlar (tuman bo'yicha). */
    private function pendingReviews(string $districtId): int
    {
        return DB::connection('mahalla')->table('zone_observations as o')
            ->join('houses as h', 'h.id', '=', 'o.house_id')
            ->where('h.district_id', $districtId)
            ->where('o.decision', 'flagged')
            ->whereNull('o.reviewed_by')
            ->count();
    }
```

`district()` ichida `$mahallas` o'zgaruvchisi allaqachon bor (mahallalar ro'yxati) — `count($mahallas)` shundan olinadi.

- [ ] **Step 4: Kontrollerga uzatish**

`Api/Executive/DistrictDashboardController.php` — `response()->json([...])` ichiga `unassigned_households` dan keyin qo'shing:

```php
            'summary' => $data['summary'],
```

- [ ] **Step 5: Testlarni yurgizish**

```powershell
Push-Location D:\kadr\platform
& C:\php84\php.exe artisan test --filter=ExecutiveDashboardTest
Pop-Location
```

Kutilgan natija: PASS — barcha testlar (mavjud 29 + yangi 3 = 32).

- [ ] **Step 6: Haqiqiy ma'lumotda tekshirish**

```powershell
Push-Location D:\kadr\platform
& C:\php84\php.exe artisan tinker --execute="`$d = DB::connection('master')->table('districts')->where('soato_code','1733230')->value('id'); print_r(app(App\Domains\Mahalla\Services\ExecutiveStats::class)->district(`$d)['summary']);"
Pop-Location
```

Kutilgan natija: `total_mahallas => 52`, qolganlari `0` (hali kuzatuv yo'q).

- [ ] **Step 7: Commit**

```bash
cd /d/kadr/platform
git add app/Domains/Mahalla/Services/ExecutiveStats.php app/Domains/Mahalla/Http/Controllers/Api/Executive/DistrictDashboardController.php tests/Feature/Mahalla/ExecutiveDashboardTest.php
git commit -m "feat(mahalla): KPI cardlar uchun summary (xonadon bo'yicha DISTINCT)"
```

---

## Task 2: GeoJSON endpointi

**Files:**
- Create: `app/Domains/Mahalla/Http/Controllers/Api/Executive/DistrictGeoJsonController.php`
- Modify: `routes/api/mahalla.php`
- Modify: `tests/Feature/Mahalla/ExecutiveDashboardTest.php`

**Interfaces:**
- Consumes: `mahalla.viewer` middleware
- Produces: `GET /api/mahalla/executive/districts/{district}/geojson` → GeoJSON `FeatureCollection`, har `Feature.properties` da `id` (string) va `name` (string)

> **`{district}` bu yerda MAJBURIY** (asosiy endpointdagidan farqli). Laravel ixtiyoriy marshrut parametrini faqat URL oxirida qo'llab-quvvatlaydi — `{district?}/geojson` ko'rinishi ishlamaydi. Frontend chegaralarni jadvaldan keyin so'raydi, ya'ni tuman `id` si allaqachon qo'lida bo'ladi.

- [ ] **Step 1: Yiqiladigan testlarni yozish**

```php
    public function test_geojson_returns_feature_collection_matching_table_rows(): void
    {
        $user = $this->makeUser('viloyat');
        $districtId = $this->districtId();

        $geo = $this->actingAs($user, 'sanctum')
            ->getJson("/api/mahalla/executive/districts/{$districtId}/geojson")
            ->assertOk()
            ->json();

        $this->assertSame('FeatureCollection', $geo['type']);

        $expected = DB::connection('master')->table('mahallas')
            ->where('district_id', $districtId)->count();
        $this->assertCount($expected, $geo['features']);

        $first = $geo['features'][0];
        $this->assertSame('Feature', $first['type']);
        $this->assertArrayHasKey('id', $first['properties']);
        $this->assertArrayHasKey('name', $first['properties']);
        $this->assertContains($first['geometry']['type'], ['Polygon', 'MultiPolygon']);

        // Frontend GeoJSON'ni jadval qatorlariga `id` bo'yicha bog'laydi —
        // identifikatorlar mos kelmasa xarita rangsiz qoladi.
        $tableIds = collect($this->actingAs($user, 'sanctum')
            ->getJson("/api/mahalla/executive/districts/{$districtId}")
            ->json('rows'))->pluck('mahalla.id')->sort()->values()->all();
        $geoIds = collect($geo['features'])->pluck('properties.id')->sort()->values()->all();

        $this->assertSame($tableIds, $geoIds);
    }

    public function test_geojson_is_forbidden_for_deputat(): void
    {
        $this->actingAs($this->makeUser('deputat'), 'sanctum')
            ->getJson("/api/mahalla/executive/districts/{$this->districtId()}/geojson")
            ->assertForbidden();
    }
```

- [ ] **Step 2: Testni yurgizib, yiqilishini tasdiqlash**

```powershell
Push-Location D:\kadr\platform
& C:\php84\php.exe artisan test --filter=test_geojson_returns_feature_collection
Pop-Location
```

Kutilgan natija: FAIL — 404.

- [ ] **Step 3: Kontrollerni yozish**

`app/Domains/Mahalla/Http/Controllers/Api/Executive/DistrictGeoJsonController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Controllers\Api\Executive;

use App\Domains\Mahalla\Models\Master\District;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Xarita uchun mahalla chegaralari (GeoJSON).
 *
 * Asosiy javobdan AJRATILGAN: geometriya ~47 KB va kamdan-kam o'zgaradi,
 * o'zgarish raqamlari esa har daqiqada yangilanadi. Ajratilgani uchun
 * geometriya keshi raqamlar yangilanganda ham amal qiladi.
 *
 * `properties` da FAQAT `id` va `name` bor — raqamlar asosiy javobdan keladi
 * va frontendda `id` bo'yicha bog'lanadi.
 */
class DistrictGeoJsonController extends Controller
{
    /**
     * Soddalashtirish toleransi (daraja). 0.0003 ≈ 33 m.
     * O'lchangan: xom 378 KB -> 46.6 KB. 35 km kenglikdagi tumanni 1500px
     * ekranda ko'rsatganda farqi ko'rinmaydi.
     */
    private const SIMPLIFY_TOLERANCE = 0.0003;

    public function __invoke(string $district): JsonResponse
    {
        $model = District::on('master')->findOrFail($district);

        $rows = DB::connection('master')->table('mahallas')
            ->where('district_id', $model->id)
            ->whereNotNull('boundary')
            ->orderBy('sort_order')->orderBy('name_cyr')
            ->selectRaw('id, name_cyr, ST_AsGeoJSON(ST_SimplifyPreserveTopology(boundary, ?)) as geom',
                [self::SIMPLIFY_TOLERANCE])
            ->get();

        $features = [];
        foreach ($rows as $r) {
            $features[] = [
                'type' => 'Feature',
                'properties' => ['id' => $r->id, 'name' => $r->name_cyr],
                'geometry' => json_decode((string) $r->geom, true),
            ];
        }

        return response()->json(['type' => 'FeatureCollection', 'features' => $features]);
    }
}
```

- [ ] **Step 4: Marshrutni qo'shish**

`routes/api/mahalla.php` — `use` blokiga qo'shing:

```php
use App\Domains\Mahalla\Http\Controllers\Api\Executive\DistrictGeoJsonController;
```

va `executive` guruhi ichida, `districts/{district?}` marshrutidan KEYIN qo'shing:

```php
                Route::get('/districts/{district}/geojson', DistrictGeoJsonController::class)
                    ->whereUuid('district')
                    ->name('district.geojson');
```

- [ ] **Step 5: Testlarni yurgizish**

```powershell
Push-Location D:\kadr\platform
& C:\php84\php.exe artisan test --filter=ExecutiveDashboardTest
Pop-Location
```

Kutilgan natija: PASS — 34 ta test.

- [ ] **Step 6: Javob hajmini o'lchash**

```powershell
Push-Location D:\kadr\platform
& C:\php84\php.exe artisan tinker --execute="`$d = DB::connection('master')->table('districts')->where('soato_code','1733230')->value('id'); `$r = DB::connection('master')->table('mahallas')->where('district_id',`$d)->selectRaw('sum(length(ST_AsGeoJSON(ST_SimplifyPreserveTopology(boundary, 0.0003)))) as b')->first(); printf('geometriya: %.1f KB'.PHP_EOL, `$r->b/1024);"
Pop-Location
```

Kutilgan natija: ~46 KB (50 dan oshmasligi kerak).

- [ ] **Step 7: Commit**

```bash
cd /d/kadr/platform
git add app/Domains/Mahalla/Http/Controllers/Api/Executive/DistrictGeoJsonController.php routes/api/mahalla.php tests/Feature/Mahalla/ExecutiveDashboardTest.php
git commit -m "feat(mahalla): xarita uchun GeoJSON endpointi (ST_Simplify 33m)"
```

---

## Task 3: Mahalla statistikasi servisi

**Files:**
- Create: `app/Domains/Mahalla/Services/ExecutiveMahallaStats.php`
- Modify: `app/Domains/Mahalla/Http/Controllers/Api/Executive/MahallaDashboardController.php`
- Modify: `tests/Feature/Mahalla/ExecutiveDashboardTest.php`

**Interfaces:**
- Consumes: `ExecutiveStats::period()`; `MahallaZones::zoneCodes()`, `::zoneLabel()`, `::statusCodes()`, `::statusLabel()`
- Produces:
  - `ExecutiveMahallaStats::dynamics(string $mahallaId, int $days = 30): array<int, array{date:string, count:int}>`
  - `::zoneStatus(string $mahallaId, int $households): array<int, array{zone:string, label:string, households:int, statuses:array, unobserved:int}>`
  - `::recentChanges(string $mahallaId, int $limit = 10): array<int, array{id:string, zone:string, zone_label:string, address:?string, observed_at:string, description:?string}>`

- [ ] **Step 1: Yiqiladigan testlarni yozish**

```php
    public function test_zone_status_reports_unobserved_households(): void
    {
        [$mahallaId, $streetId] = $this->mahallaWithStreet();
        $households = (int) DB::connection('master')->table('buildings')
            ->where('mahalla_id', $mahallaId)->where('type', 'residential')->count();

        $house = $this->makeHouse($mahallaId, $streetId);
        DB::connection('mahalla')->table('house_zone_states')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'house_id' => $house, 'zone' => 'yard', 'status' => 'completed',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $rows = app(\App\Domains\Mahalla\Services\ExecutiveMahallaStats::class)
            ->zoneStatus($mahallaId, $households);

        $yard = collect($rows)->firstWhere('zone', 'yard');

        // Bitta xonadon kuzatilgan -> qolgani "kuzatilmagan". Bu segment bo'lmasa
        // diagramma 100% yashil ko'rsatib rahbarni chalg'itardi.
        $this->assertSame($households - 1, $yard['unobserved']);
        $this->assertSame(1, collect($yard['statuses'])->firstWhere('status', 'completed')['count']);

        // Kuzatuvsiz zona ham qatorda bo'ladi, hammasi kuzatilmagan
        $kitchen = collect($rows)->firstWhere('zone', 'kitchen');
        $this->assertSame($households, $kitchen['unobserved']);
    }

    public function test_dynamics_returns_full_window_with_zero_days(): void
    {
        [$mahallaId] = $this->mahallaWithStreet();

        $rows = app(\App\Domains\Mahalla\Services\ExecutiveMahallaStats::class)
            ->dynamics($mahallaId, 30);

        // Bo'sh kunlar tushib qolsa "har kuni ish bo'lgan" degan yolg'on
        // taassurot qoladi — shuning uchun 30 kunning hammasi qaytadi.
        $this->assertCount(30, $rows);
        $this->assertSame(array_keys($rows), range(0, 29), 'ro\'yxat tartibli bo\'lishi kerak');
        $dates = array_column($rows, 'date');
        $this->assertSame($dates, array_values(array_unique($dates)));
        $this->assertTrue($dates === array_values(collect($dates)->sort()->all()), 'sanalar o\'sish tartibida');
    }

    public function test_dynamics_groups_by_tashkent_day(): void
    {
        [$mahallaId, $streetId] = $this->mahallaWithStreet();
        $tz = 'Asia/Tashkent';

        // Toshkent bo'yicha bugun 00:30 — UTC bo'yicha KECHA 19:30.
        // Guruhlash UTC bo'yicha bo'lsa, bu kuzatuv kechaga tushib qolardi.
        $local = \Illuminate\Support\Carbon::now($tz)->startOfDay()->addMinutes(30);
        \Illuminate\Support\Carbon::setTestNow($local->copy()->addHours(6));

        $house = $this->makeHouse($mahallaId, $streetId);
        $this->makeObservation($house, 'yard', $local->copy()->utc(), isChange: true);

        $rows = app(\App\Domains\Mahalla\Services\ExecutiveMahallaStats::class)
            ->dynamics($mahallaId, 30);

        $today = collect($rows)->firstWhere('date', $local->toDateString());
        $this->assertNotNull($today, 'bugungi kun oynada bo\'lishi kerak');
        $this->assertSame(1, $today['count']);

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_recent_changes_only_confirmed_newest_first(): void
    {
        [$mahallaId, $streetId] = $this->mahallaWithStreet();
        $house = $this->makeHouse($mahallaId, $streetId);

        $this->makeObservation($house, 'facade', now()->subDays(2), isChange: true);
        $this->makeObservation($house, 'toilet', now()->subDay(), isChange: false);
        $this->makeObservation($house, 'yard', now()->subHour(), isChange: true);

        $rows = app(\App\Domains\Mahalla\Services\ExecutiveMahallaStats::class)
            ->recentChanges($mahallaId, 10);

        $zones = array_column($rows, 'zone');
        $this->assertSame(['yard', 'facade'], array_slice($zones, 0, 2), 'yangisi birinchi');
        $this->assertNotContains('toilet', $zones, 'tasdiqlanmagan o\'zgarish kirmaydi');
    }
```

- [ ] **Step 2: Testni yurgizib, yiqilishini tasdiqlash**

```powershell
Push-Location D:\kadr\platform
& C:\php84\php.exe artisan test --filter=test_zone_status_reports_unobserved_households
Pop-Location
```

Kutilgan natija: FAIL — `Class "App\Domains\Mahalla\Services\ExecutiveMahallaStats" does not exist`

- [ ] **Step 3: Servisni yozish**

`app/Domains/Mahalla/Services/ExecutiveMahallaStats.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Services;

use App\Domains\Mahalla\Support\MahallaZones;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bitta mahalla sahifasi uchun statistika: dinamika, zona holati, so'nggi
 * o'zgarishlar. `ExecutiveStats` tuman kesimi bilan band — bu uch mustaqil
 * so'rov o'z faylida tursa tushunarliroq va alohida testlanadi.
 */
final class ExecutiveMahallaStats
{
    public function __construct(private readonly ExecutiveStats $stats)
    {
    }

    /**
     * Kunlar bo'yicha o'zgargan xonadonlar (oxirgi `$days` kun).
     *
     * Oyna MAHALLIY kun chegaralari bo'yicha quriladi va kuzatuv bo'lmagan
     * kunlar ham nol bilan qaytadi — aks holda diagrammada "har kuni ish
     * bo'lgan" degan yolg'on taassurot qolardi.
     *
     * @return array<int, array{date: string, count: int}>
     */
    public function dynamics(string $mahallaId, int $days = 30): array
    {
        $tz = (string) config('mahalla.timezone', 'Asia/Tashkent');
        $from = Carbon::now($tz)->startOfDay()->subDays($days - 1);

        // `observed_at` — timestamp without time zone, ichida UTC. Kunlarga
        // ajratishdan oldin mahalliy vaqtga o'giriladi, aks holda kechqurun
        // 19:00 dan keyingi o'zgarishlar (Toshkent bo'yicha ertangi kun)
        // noto'g'ri kunga tushadi.
        $rows = DB::connection('mahalla')->table('zone_observations as o')
            ->join('houses as h', 'h.id', '=', 'o.house_id')
            ->where('h.mahalla_id', $mahallaId)
            ->where('o.is_change', true)
            ->where('o.observed_at', '>=', $from->copy()->utc())
            ->groupByRaw("to_char(o.observed_at AT TIME ZONE 'UTC' AT TIME ZONE ?, 'YYYY-MM-DD')", [$tz])
            ->selectRaw("to_char(o.observed_at AT TIME ZONE 'UTC' AT TIME ZONE ?, 'YYYY-MM-DD') as kun", [$tz])
            ->selectRaw('count(distinct o.house_id) as soni')
            ->get();

        $byDay = [];
        foreach ($rows as $r) {
            $byDay[$r->kun] = (int) $r->soni;
        }

        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $from->copy()->addDays($i)->toDateString();
            $out[] = ['date' => $date, 'count' => $byDay[$date] ?? 0];
        }

        return $out;
    }

    /**
     * Zona bo'yicha holat taqsimoti + KUZATILMAGAN xonadonlar.
     *
     * `house_zone_states` da qator faqat xonadon birinchi marta kuzatilganda
     * paydo bo'ladi. Shuning uchun "kuzatilmagan" segmentisiz 837 xonadonli
     * mahallada 5 tasi kuzatilgan bo'lsa diagramma 100% yashil ko'rsatib
     * rahbarni chalg'itardi.
     *
     * @return array<int, array<string, mixed>>
     */
    public function zoneStatus(string $mahallaId, int $households): array
    {
        $rows = DB::connection('mahalla')->table('house_zone_states as s')
            ->join('houses as h', 'h.id', '=', 's.house_id')
            ->where('h.mahalla_id', $mahallaId)
            ->groupBy('s.zone', 's.status')
            ->select('s.zone', 's.status')
            ->selectRaw('count(*) as soni')
            ->get();

        $byZone = [];
        foreach ($rows as $r) {
            $byZone[$r->zone][$r->status] = (int) $r->soni;
        }

        $out = [];
        foreach (MahallaZones::zoneCodes() as $zone) {
            $statuses = [];
            $observed = 0;
            foreach (MahallaZones::statusCodes() as $status) {
                $count = $byZone[$zone][$status] ?? 0;
                $observed += $count;
                $statuses[] = [
                    'status' => $status,
                    'label' => MahallaZones::statusLabel($status),
                    'count' => $count,
                ];
            }

            $out[] = [
                'zone' => $zone,
                'label' => MahallaZones::zoneLabel($zone),
                'households' => $households,
                'statuses' => $statuses,
                'unobserved' => max(0, $households - $observed),
            ];
        }

        return $out;
    }

    /**
     * So'nggi tasdiqlangan o'zgarishlar (AI tavsifi bilan).
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentChanges(string $mahallaId, int $limit = 10): array
    {
        $rows = DB::connection('mahalla')->table('zone_observations as o')
            ->join('houses as h', 'h.id', '=', 'o.house_id')
            ->where('h.mahalla_id', $mahallaId)
            ->where('o.is_change', true)
            ->orderByDesc('o.observed_at')
            ->limit($limit)
            ->get(['o.id', 'o.zone', 'o.observed_at', 'o.ai_result', 'h.address']);

        $out = [];
        foreach ($rows as $r) {
            $ai = is_string($r->ai_result) ? json_decode($r->ai_result, true) : (array) $r->ai_result;
            $description = is_array($ai) ? ($ai['change_description'] ?? null) : null;

            $out[] = [
                'id' => $r->id,
                'zone' => $r->zone,
                'zone_label' => MahallaZones::zoneLabel($r->zone),
                'address' => $r->address,
                'observed_at' => (string) $r->observed_at,
                // Tavsif bo'sh bo'lsa ham qator ko'rsatiladi — o'zgarish faktini
                // yashirmaslik kerak, frontend "тавсиф йўқ" deb yozadi.
                'description' => $description !== '' ? $description : null,
            ];
        }

        return $out;
    }
}
```

- [ ] **Step 4: Kontrollerga ulash**

`Api/Executive/MahallaDashboardController.php` — konstruktorni almashtiring:

```php
    public function __construct(
        private readonly ExecutiveStats $stats,
        private readonly ExecutiveMahallaStats $mahallaStats,
    ) {
    }
```

`use` blokiga qo'shing:

```php
use App\Domains\Mahalla\Services\ExecutiveMahallaStats;
```

va `response()->json([...])` ichiga `'rows' => $data['rows'],` dan keyin qo'shing:

```php
            'dynamics' => $this->mahallaStats->dynamics((string) $model->id),
            'zone_status' => $this->mahallaStats->zoneStatus((string) $model->id, $data['households']),
            'recent_changes' => $this->mahallaStats->recentChanges((string) $model->id),
```

- [ ] **Step 5: Testlarni yurgizish**

```powershell
Push-Location D:\kadr\platform
& C:\php84\php.exe artisan test --filter=ExecutiveDashboardTest
Pop-Location
```

Kutilgan natija: PASS — 38 ta test.

- [ ] **Step 6: To'liq to'plam**

```powershell
Push-Location D:\kadr\platform
& C:\php84\php.exe artisan test
Pop-Location
```

Kutilgan natija: barcha testlar yashil.

- [ ] **Step 7: Baza qoldiqsizligini tasdiqlash**

```powershell
Push-Location D:\kadr\platform
& C:\php84\php.exe artisan tinker --execute="echo DB::connection('mahalla')->table('houses')->count().' / '.DB::connection('mahalla')->table('house_zone_states')->count().' / '.DB::connection('auth')->table('users')->where('login','like','test_%')->count().PHP_EOL;"
Pop-Location
```

Kutilgan natija: `1 / 1 / 0`

- [ ] **Step 8: Commit**

```bash
cd /d/kadr/platform
git add app/Domains/Mahalla/Services/ExecutiveMahallaStats.php app/Domains/Mahalla/Http/Controllers/Api/Executive/MahallaDashboardController.php tests/Feature/Mahalla/ExecutiveDashboardTest.php
git commit -m "feat(mahalla): mahalla statistikasi (dinamika, zona holati, so'nggi o'zgarishlar)"
```

---

## Task 4: Frontend poydevor — Leaflet, tiplar, store

**Files:**
- Modify: `D:\kadr\mahalla\package.json` (npm orqali)
- Modify: `D:\kadr\mahalla\src\types\index.ts`
- Modify: `D:\kadr\mahalla\src\stores\executive.ts`

**Interfaces:**
- Consumes: 1-3 vazifalardagi API javob shakli
- Produces: `ExecutiveSummary`, `MahallaGeoJson`, `DynamicsPoint`, `ZoneStatusRow`, `RecentChange` tiplari; `useExecutiveStore().geojson` va `fetchGeoJson(id?)`

- [ ] **Step 1: Leaflet o'rnatish**

```powershell
Push-Location D:\kadr\mahalla
npm install leaflet@^1.9.4
npm install --save-dev @types/leaflet@^1.9.12
Pop-Location
```

Kutilgan natija: `package.json` da `leaflet` (dependencies) va `@types/leaflet` (devDependencies) paydo bo'ladi.

- [ ] **Step 2: Tiplarni qo'shish**

`src/types/index.ts` oxiriga qo'shing:

```ts
// --- Rahbariyat: xarita, cardlar, mahalla statistikasi ---

export interface ExecutiveSummary {
    changed_today: number;
    changed_week: number;
    active_mahallas: number;
    total_mahallas: number;
    pending_reviews: number;
}

/** GeoJSON — `properties` da faqat id va nom; raqamlar asosiy javobdan keladi. */
export interface MahallaFeature {
    type: 'Feature';
    properties: { id: string; name: string };
    geometry: { type: string; coordinates: unknown };
}

export interface MahallaGeoJson {
    type: 'FeatureCollection';
    features: MahallaFeature[];
}

export interface DynamicsPoint {
    date: string;
    count: number;
}

export interface ZoneStatusCount {
    status: string;
    label: string;
    count: number;
}

export interface ZoneStatusRow {
    zone: string;
    label: string;
    households: number;
    statuses: ZoneStatusCount[];
    unobserved: number;
}

export interface RecentChange {
    id: string;
    zone: string;
    zone_label: string;
    address: string | null;
    observed_at: string;
    description: string | null;
}
```

`ExecutiveDistrictResponse` interfeysiga `unassigned_households` dan keyin qo'shing:

```ts
    summary: ExecutiveSummary;
```

`ExecutiveMahallaResponse` interfeysiga `rows` dan keyin qo'shing:

```ts
    dynamics: DynamicsPoint[];
    zone_status: ZoneStatusRow[];
    recent_changes: RecentChange[];
```

- [ ] **Step 3: Store'ga GeoJSON qo'shish**

`src/stores/executive.ts` — `import type` qatoriga `MahallaGeoJson` qo'shing, `ExecutiveState` ga maydon qo'shing:

```ts
interface ExecutiveState {
    district: ExecutiveDistrictResponse | null;
    mahalla: ExecutiveMahallaResponse | null;
    geojson: MahallaGeoJson | null;
    loading: boolean;
}
```

`state` ga `geojson: null,` qo'shing va `actions` ga yangi action qo'shing:

```ts
        /**
         * Chegaralar alohida yuklanadi va BIR MARTA keshlanadi: geometriya
         * kamdan-kam o'zgaradi, raqamlar esa tez-tez. Qayta yuklash jadval
         * yangilanishini sekinlashtirmasligi kerak.
         */
        async fetchGeoJson(districtId: string): Promise<void> {
            if (this.geojson) return;
            const { data } = await api.get<MahallaGeoJson>(
                `/api/mahalla/executive/districts/${districtId}/geojson`,
            );
            this.geojson = data;
        },
```

`districtId` **majburiy** — geojson marshrutida parametr ixtiyoriy emas (Laravel ixtiyoriy parametrni faqat URL oxirida qo'llab-quvvatlaydi). Sahifa uni jadval javobidan oladi.

- [ ] **Step 4: Marshrutlarni tekshirish**

```powershell
Push-Location D:\kadr\platform
& C:\php84\php.exe artisan route:list --path=executive
Pop-Location
```

Kutilgan natija: 3 ta marshrut —
```
api/mahalla/executive/districts/{district?}
api/mahalla/executive/districts/{district}/geojson
api/mahalla/executive/mahallas/{mahalla}
```

- [ ] **Step 5: Build tekshiruvi**

```powershell
Push-Location D:\kadr\mahalla
npx vue-tsc --noEmit
npm run build
Pop-Location
```

Kutilgan natija: ikkalasi ham toza (yangi tiplar hali ishlatilmagan, lekin sintaksis xatolari shu yerda ushlanadi).

---

## Task 5: KPI cardlar va xarita

**Files:**
- Create: `D:\kadr\mahalla\src\components\executive\KpiCard.vue`
- Create: `D:\kadr\mahalla\src\components\executive\ChangeMap.vue`
- Modify: `D:\kadr\mahalla\src\pages\executive\ExecutiveDistrict.vue`

**Interfaces:**
- Consumes: `useExecutiveStore().district.summary`, `.geojson`, `fetchGeoJson()`; `ExecutiveSummary`, `MahallaGeoJson` tiplari (Task 4)
- Produces: `KpiCard` props `{ label: string; value: string | number; hint?: string; tone?: 'default' | 'alert' }`; `ChangeMap` props `{ geojson, counts, todayCounts, names }` va `select` hodisasi

- [ ] **Step 1: `KpiCard` komponentini yozish**

`src/components/executive/KpiCard.vue`:

```vue
<script setup lang="ts">
withDefaults(
    defineProps<{
        label: string;
        value: string | number;
        hint?: string;
        /** `alert` — e'tibor talab qiladigan ko'rsatkich (masalan tekshiruv navbati). */
        tone?: 'default' | 'alert';
    }>(),
    { hint: '', tone: 'default' },
);
</script>

<template>
    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ label }}</p>
        <p
            class="mt-2 text-3xl font-semibold tabular-nums"
            :class="tone === 'alert' ? 'text-amber-600' : 'text-slate-900'"
        >
            {{ value }}
        </p>
        <p v-if="hint" class="mt-1 text-xs text-slate-400">{{ hint }}</p>
    </div>
</template>
```

- [ ] **Step 2: `ChangeMap` komponentini yozish**

`src/components/executive/ChangeMap.vue`:

```vue
<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import type { MahallaGeoJson } from '@/types';

const props = defineProps<{
    geojson: MahallaGeoJson | null;
    /** mahalla_id -> shu haftada o'zgargan xonadonlar */
    counts: Record<string, number>;
    /** mahalla_id -> bugun o'zgargan xonadonlar */
    todayCounts: Record<string, number>;
}>();

const emit = defineEmits<{ (e: 'select', id: string): void }>();

const el = ref<HTMLDivElement | null>(null);
let map: L.Map | null = null;
let layer: L.GeoJSON | null = null;

/** Oraliqlar spec'da belgilangan: 0 / 1-5 / 6-15 / 16+ */
function colorFor(n: number): string {
    if (n >= 16) return '#15803d';
    if (n >= 6) return '#4ade80';
    if (n >= 1) return '#bbf7d0';
    return '#e2e8f0';
}

function styleFor(id: string): L.PathOptions {
    return {
        fillColor: colorFor(props.counts[id] ?? 0),
        fillOpacity: 0.75,
        color: '#94a3b8',
        weight: 1,
    };
}

function render(): void {
    if (!map || !props.geojson) return;

    if (layer) {
        layer.remove();
        layer = null;
    }

    layer = L.geoJSON(props.geojson as unknown as GeoJSON.GeoJsonObject, {
        style: (f) => styleFor(String(f?.properties?.id ?? '')),
        onEachFeature: (feature, lyr) => {
            const id = String(feature.properties?.id ?? '');
            const name = String(feature.properties?.name ?? '');
            const week = props.counts[id] ?? 0;
            const today = props.todayCounts[id] ?? 0;

            lyr.bindTooltip(
                `<strong>${name}</strong><br>Шу ҳафтада: ${week}<br>Бугун: ${today}`,
                { sticky: true },
            );
            lyr.on('mouseover', () => (lyr as L.Path).setStyle({ weight: 3, color: '#334155' }));
            lyr.on('mouseout', () => (lyr as L.Path).setStyle(styleFor(id)));
            lyr.on('click', () => emit('select', id));
        },
    }).addTo(map);

    const bounds = layer.getBounds();
    if (bounds.isValid()) map.fitBounds(bounds, { padding: [12, 12] });
}

onMounted(() => {
    if (!el.value) return;
    map = L.map(el.value, { zoomControl: true });

    // OSM plitkalari internetdan keladi. Uzilsa fon kulrang qoladi, LEKIN
    // poligonlar va ranglar ko'rinaveradi — ular bizning ma'lumotimiz.
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
        attribution: '© OpenStreetMap',
    }).addTo(map);

    render();
});

watch(() => props.geojson, render);
watch(() => props.counts, render, { deep: true });

onBeforeUnmount(() => {
    map?.remove();
    map = null;
    layer = null;
});
</script>

<template>
    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-3">
            <h2 class="text-sm font-semibold text-slate-700">Ўзгаришлар харитаси</h2>
            <div class="flex flex-wrap items-center gap-3 text-xs text-slate-500">
                <span class="inline-flex items-center gap-1">
                    <span class="h-3 w-3 rounded-sm" style="background:#e2e8f0" /> 0
                </span>
                <span class="inline-flex items-center gap-1">
                    <span class="h-3 w-3 rounded-sm" style="background:#bbf7d0" /> 1–5
                </span>
                <span class="inline-flex items-center gap-1">
                    <span class="h-3 w-3 rounded-sm" style="background:#4ade80" /> 6–15
                </span>
                <span class="inline-flex items-center gap-1">
                    <span class="h-3 w-3 rounded-sm" style="background:#15803d" /> 16+
                </span>
            </div>
        </div>
        <div ref="el" class="h-[420px] w-full" />
    </div>
</template>
```

- [ ] **Step 3: Tuman sahifasiga ulash**

`src/pages/executive/ExecutiveDistrict.vue` — `<script setup>` ga import va hisoblanuvchilarni qo'shing:

```ts
import KpiCard from '@/components/executive/KpiCard.vue';
import ChangeMap from '@/components/executive/ChangeMap.vue';
```

`load()` funksiyasini almashtiring (GeoJSON jadvaldan KEYIN yuklanadi — jadval tezroq ko'rinsin):

```ts
async function load(): Promise<void> {
    error.value = null;
    try {
        // Chegaralar jadvaldan KEYIN yuklanadi: jadval tezroq ko'rinsin,
        // ustiga geojson marshruti tuman id'sini majburiy talab qiladi.
        await store.fetchDistrict();
        const id = store.district?.district.id;
        if (id) await store.fetchGeoJson(id);
    } catch {
        error.value = 'Туман маълумотларини юклаб бўлмади.';
        toast.show(error.value, 'error');
    }
}
```

va hisoblanuvchilar qo'shing:

```ts
const summary = computed(() => data.value?.summary ?? null);

/** Xarita poligonlarini raqamlarga bog'lash uchun mahalla_id -> son xaritasi. */
const weekCounts = computed<Record<string, number>>(() => {
    const out: Record<string, number> = {};
    for (const row of data.value?.rows ?? []) {
        out[row.mahalla.id] = Object.values(row.zones).reduce((s, z) => s + z.week, 0);
    }
    return out;
});

const todayCounts = computed<Record<string, number>>(() => {
    const out: Record<string, number> = {};
    for (const row of data.value?.rows ?? []) {
        out[row.mahalla.id] = Object.values(row.zones).reduce((s, z) => s + z.today, 0);
    }
    return out;
});
```

- [ ] **Step 4: Shablonga cardlar va xaritani qo'shish**

`ExecutiveDistrict.vue` shablonida `<div class="mx-auto max-w-7xl">` ni almashtiring:

```html
      <div class="mx-auto max-w-screen-2xl">
```

va `<header>` blokidan KEYIN, `v-if="store.loading"` blokidan OLDIN qo'shing:

```html
        <div v-if="summary" class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <KpiCard label="Бугун ўзгарган" :value="summary.changed_today" hint="хонадон" />
            <KpiCard label="Шу ҳафтада ўзгарган" :value="summary.changed_week" hint="хонадон" />
            <KpiCard
                label="Фаол маҳаллалар"
                :value="`${summary.active_mahallas} / ${summary.total_mahallas}`"
                hint="шу ҳафтада ўзгариш бўлган"
            />
            <KpiCard
                label="Текшириш кутилмоқда"
                :value="summary.pending_reviews"
                hint="кузатув"
                :tone="summary.pending_reviews > 0 ? 'alert' : 'default'"
            />
        </div>

        <ChangeMap
            v-if="store.geojson"
            class="mb-5"
            :geojson="store.geojson"
            :counts="weekCounts"
            :today-counts="todayCounts"
            @select="openMahalla"
        />
```

- [ ] **Step 5: Build va tip tekshiruvi**

```powershell
Push-Location D:\kadr\mahalla
npx vue-tsc --noEmit
npm run build
Pop-Location
```

Kutilgan natija: ikkalasi ham toza.

- [ ] **Step 6: Brauzerda tekshirish**

Backend `http://localhost:8020`, frontend `http://localhost:5173` da ishga tushiring, `viloyat` / (parol alohida beriladi) bilan kiring.

Kutilgan natija:
1. Yuqorida 4 ta card: `0`, `0`, `0 / 52`, `0`
2. Ostida xarita: Shovot tumani ko'rinadi, 52 mahalla poligoni kulrang (o'zgarish yo'q), chegaralar aniq
3. Poligon ustiga kelganda tooltip: mahalla nomi + `Шу ҳафтада: 0` + `Бугун: 0`
4. Poligonga bosilganda mahalla sahifasi ochiladi
5. Ostida avvalgi jadval o'zgarishsiz

---

## Task 6: Mahalla sahifasi diagrammalari

**Files:**
- Create: `D:\kadr\mahalla\src\components\executive\DynamicsChart.vue`
- Create: `D:\kadr\mahalla\src\components\executive\ZoneStatusBars.vue`
- Modify: `D:\kadr\mahalla\src\pages\executive\ExecutiveMahalla.vue`

**Interfaces:**
- Consumes: `ExecutiveMahallaResponse.dynamics`, `.zone_status`, `.recent_changes` (Task 4 tiplari)
- Produces: `DynamicsChart` props `{ points: DynamicsPoint[] }`; `ZoneStatusBars` props `{ rows: ZoneStatusRow[] }`

- [ ] **Step 1: `DynamicsChart` komponentini yozish**

`src/components/executive/DynamicsChart.vue`:

```vue
<script setup lang="ts">
import { computed } from 'vue';
import type { DynamicsPoint } from '@/types';

const props = defineProps<{ points: DynamicsPoint[] }>();

const max = computed(() => Math.max(1, ...props.points.map((p) => p.count)));
const total = computed(() => props.points.reduce((s, p) => s + p.count, 0));

/** Sanadan faqat kun.oy — 30 ta yorliq sig'ishi uchun. */
function shortDate(iso: string): string {
    const [, m, d] = iso.split('-');
    return `${d}.${m}`;
}
</script>

<template>
    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <div class="mb-3 flex items-baseline justify-between">
            <h2 class="text-sm font-semibold text-slate-700">Ўзгаришлар динамикаси (30 кун)</h2>
            <span class="text-xs text-slate-400">жами {{ total }} хонадон</span>
        </div>

        <div class="flex h-32 items-end gap-[2px]">
            <div
                v-for="p in points"
                :key="p.date"
                class="flex-1 rounded-t"
                :class="p.count > 0 ? 'bg-green-500/80' : 'bg-slate-100'"
                :style="{ height: p.count > 0 ? (100 * p.count) / max + '%' : '2px' }"
                :title="`${p.date}: ${p.count}`"
            />
        </div>

        <div class="mt-1 flex justify-between text-[10px] text-slate-400">
            <span>{{ points.length ? shortDate(points[0].date) : '' }}</span>
            <span>{{ points.length ? shortDate(points[points.length - 1].date) : '' }}</span>
        </div>
    </div>
</template>
```

- [ ] **Step 2: `ZoneStatusBars` komponentini yozish**

`src/components/executive/ZoneStatusBars.vue`:

```vue
<script setup lang="ts">
import type { ZoneStatusRow } from '@/types';

defineProps<{ rows: ZoneStatusRow[] }>();

/**
 * `unobserved` ATAYLAB kulrang va oxirgi segment: u "hali ko'rilmagan"
 * degani, holat emas. Rangi holat ranglaridan farq qilishi shart.
 */
const STATUS_COLOR: Record<string, string> = {
    needs_work: 'bg-red-400',
    in_progress: 'bg-amber-400',
    completed: 'bg-green-500',
    good: 'bg-blue-400',
};

function widthOf(count: number, households: number): string {
    return households > 0 ? `${(100 * count) / households}%` : '0%';
}
</script>

<template>
    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <h2 class="mb-3 text-sm font-semibold text-slate-700">Зона ҳолати</h2>

        <div class="space-y-4">
            <div v-for="row in rows" :key="row.zone">
                <div class="mb-1 flex items-baseline justify-between text-sm">
                    <span class="font-medium text-slate-700">{{ row.label }}</span>
                    <span class="text-xs text-slate-400">
                        {{ row.households - row.unobserved }} / {{ row.households }} кузатилган
                    </span>
                </div>

                <div class="flex h-3 overflow-hidden rounded-full bg-slate-100">
                    <div
                        v-for="s in row.statuses"
                        :key="s.status"
                        class="h-full"
                        :class="STATUS_COLOR[s.status] ?? 'bg-slate-300'"
                        :style="{ width: widthOf(s.count, row.households) }"
                        :title="`${s.label}: ${s.count}`"
                    />
                    <div
                        class="h-full bg-slate-200"
                        :style="{ width: widthOf(row.unobserved, row.households) }"
                        :title="`Кузатилмаган: ${row.unobserved}`"
                    />
                </div>
            </div>
        </div>

        <div class="mt-4 flex flex-wrap gap-3 text-xs text-slate-500">
            <span v-for="s in rows[0]?.statuses ?? []" :key="s.status" class="inline-flex items-center gap-1">
                <span class="h-2 w-2 rounded-full" :class="STATUS_COLOR[s.status] ?? 'bg-slate-300'" />
                {{ s.label }}
            </span>
            <span class="inline-flex items-center gap-1">
                <span class="h-2 w-2 rounded-full bg-slate-200" /> Кузатилмаган
            </span>
        </div>
    </div>
</template>
```

- [ ] **Step 3: Mahalla sahifasiga ulash**

`src/pages/executive/ExecutiveMahalla.vue` — `<script setup>` ga qo'shing:

```ts
import DynamicsChart from '@/components/executive/DynamicsChart.vue';
import ZoneStatusBars from '@/components/executive/ZoneStatusBars.vue';

/** Sana + vaqtni qisqa ko'rinishda: 20.07 09:12 */
function shortDateTime(iso: string): string {
    const d = new Date(iso.replace(' ', 'T'));
    const p = (n: number): string => String(n).padStart(2, '0');
    return `${p(d.getDate())}.${p(d.getMonth() + 1)} ${p(d.getHours())}:${p(d.getMinutes())}`;
}
```

- [ ] **Step 4: Shablonni kengaytirish**

`ExecutiveMahalla.vue` — `<div class="mx-auto max-w-3xl">` ni almashtiring:

```html
      <div class="mx-auto max-w-5xl">
```

va jadval `</div>` (`v-else-if="data"` bloki) dan KEYIN, `</div>` (konteyner) dan OLDIN qo'shing:

```html
        <div v-if="data" class="mt-5 grid gap-5 lg:grid-cols-2">
            <DynamicsChart :points="data.dynamics" />
            <ZoneStatusBars :rows="data.zone_status" />
        </div>

        <div v-if="data" class="mt-5 overflow-hidden rounded-xl border border-slate-200 bg-white">
            <h2 class="border-b border-slate-100 px-4 py-3 text-sm font-semibold text-slate-700">
                Сўнгги ўзгаришлар
            </h2>

            <ul v-if="data.recent_changes.length" class="divide-y divide-slate-100">
                <li v-for="c in data.recent_changes" :key="c.id" class="px-4 py-3">
                    <div class="flex flex-wrap items-baseline gap-x-2 text-sm">
                        <span class="tabular-nums text-slate-400">{{ shortDateTime(c.observed_at) }}</span>
                        <span class="font-medium text-slate-700">{{ c.zone_label }}</span>
                        <span class="text-slate-500">{{ c.address ?? '—' }}</span>
                    </div>
                    <p class="mt-1 text-sm" :class="c.description ? 'text-slate-600' : 'text-slate-400'">
                        {{ c.description ?? 'тавсиф йўқ' }}
                    </p>
                </li>
            </ul>

            <p v-else class="px-4 py-8 text-center text-sm text-slate-400">
                Ҳозирча тасдиқланган ўзгариш йўқ.
            </p>
        </div>
```

- [ ] **Step 5: Build va tip tekshiruvi**

```powershell
Push-Location D:\kadr\mahalla
npx vue-tsc --noEmit
npm run build
Pop-Location
```

Kutilgan natija: ikkalasi ham toza.

- [ ] **Step 6: To'liq backend to'plami**

```powershell
Push-Location D:\kadr\platform
& C:\php84\php.exe artisan test
Pop-Location
```

Kutilgan natija: barcha testlar yashil.

- [ ] **Step 7: Brauzerda uchdan-uchgacha tekshirish**

`viloyat` / (parol alohida beriladi) bilan kiring, `/executive` dan istalgan mahallaga o'ting.

Kutilgan natija:
1. Yuqorida 4 qatorli jadval (avvalgidek)
2. Ostida yonma-yon: dinamika diagrammasi (30 ta ustun, hammasi nol — kulrang chiziqlar) va zona holati (4 ta zona, hammasi **to'liq kulrang** — chunki hech biri kuzatilmagan)
3. Ostida «Сўнгги ўзгаришлар» — «Ҳозирча тасдиқланган ўзгариш йўқ»
4. «← Шовот тумани» havolasi ishlaydi
5. Chiqish tugmasi joyida (`AppLayout` ichida)

**Muhim:** zona holati chiziqlari to'liq kulrang bo'lishi — bu TO'G'RI. 837 xonadondan hech biri kuzatilmagan, shuning uchun 100% «кузатилмаган». Agar ular yashil ko'rinsa — `unobserved` hisobida xato bor.

- [ ] **Step 8: Commit (faqat backend)**

```bash
cd /d/kadr/platform
git add docs
git commit -m "docs: xarita/cardlar/statistika rejasi bajarildi"
```

> Frontend `D:\kadr\mahalla` git repo emas — u yerdagi o'zgarishlar commit qilinmaydi.

---

## Bajarilgandan keyin

- Deploy **qilinmaydi** — foydalanuvchi topshirig'ini kutadi.
- Prod'da OSM plitkalariga chiqish (internet) borligini tekshirish kerak; bo'lmasa xarita foni kulrang qoladi, poligonlar ishlashda davom etadi.
- Xarita va diagrammalar barcha raqamlarda `0` ko'rsatishi **kutilgan holat** — deputatlar ish boshlagach to'ladi.
