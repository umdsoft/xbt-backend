# «Атроф» (Nearby) backend API — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deputat turgan GPS nuqtasidan N-metr radius ichidagi binolar va tashkilotlarni (+ turgan mahallasini) qaytaradigan HTTP API qurish.

**Architecture:** Yangi `NearbyFinder` servisi `master.buildings.geom` (PostGIS Point, GIST-indeksli) bo'yicha `bbox && + ST_DWithin(geography)` radius so'rovini bajaradi, `master.object_types` bilan tasniflaydi, `mahalla.houses` bilan monitoring holatini bog'laydi va `ST_Contains` bilan joriy mahallani aniqlaydi. Yangi `NearbyController` (role-agnostic, `WorklistController` kabi) natijani JSON qilib beradi; qamrov `MahallaScope` orqali tuman darajasida cheklanadi.

**Tech Stack:** PHP 8.4 / Laravel · PostgreSQL 16 + PostGIS 3.6.2 · PHPUnit (class-based) · Sanctum token auth.

## Global Constraints

- **Schema o'zgarmaydi.** Hech qanday migratsiya yozilmaydi — barcha ustunlar va GIST indekslar allaqachon mavjud.
- **DB ulanishlari:** `master` schema (buildings, mahallas, districts, object_types), `mahalla` schema (houses). So'rov `mahalla` ulanishida bajariladi (uning `search_path` = `mahalla,master,public` — ikkala schemani ko'radi).
- **Test bazasi umumiy dev DB (`kbt`).** `tests/TestCase.php` `RefreshDatabase`/`DatabaseMigrations`/`DatabaseTruncation` ni TAQIQLAYDI (setUp da `fail()` qiladi). Faqat `DatabaseTransactions` + `protected array $connectionsToTransact = ['pgsql', 'auth', 'master', 'mahalla'];`
- **Test ma'lumoti:** `master.*` (buildings/mahallas/districts) REAL mavjud ma'lumotdan o'qiladi (insert QILINMAYDI). Faqat `auth.*` va `mahalla.*` ga test user insert qilinadi — `DB::connection(...)->table(...)->insert(...)` bilan (factory/seeder YO'Q).
- **Pilot tuman:** Shovot — `config('mahalla.executive.default_district_soato')` = `'1733230'`; `master.districts.soato_code` bo'yicha topiladi. Real id: `3b608e0b-c1c9-340f-9bcb-9aeec2671fff` (34 651 residential + 1 617 non_residential).
- **Controller joylashuvi:** `App\Domains\Mahalla\Http\Controllers\Api\NearbyController` — role-agnostic (kodda `Api\Deputat\` namespace YO'Q; deputat so'rovlari `WorklistController`/`HouseController` kabi to'g'ridan-to'g'ri `Api\` da yashaydi va `MahallaAccess::scopeFor()` bilan scope qilinadi).
- **GET query param validatsiyasi:** inline `$request->validate([...])` (bu domenda GET uchun FormRequest ISHLATILMAYDI).
- **Ustun nomlari (aynan):** `master.mahallas.name_cyr` (`name` EMAS), `master.object_types.name_cyr` + `is_social` (`is_active` ustuni YO'Q), `mahalla.houses.status` enum `not_started|in_progress|completed`, `mahalla.houses.building_id` → `master.buildings.id`, `houses` da `deleted_at` (softDeletes) bor.
- **Radius cheklovi:** 200..5000 m (server MAX 5000). **Limit:** default 600, max 1500.

---

## File Structure

| Fayl | Vazifasi |
|---|---|
| `app/Domains/Mahalla/Services/NearbyFinder.php` (yangi) | Barcha PostGIS so'rovlari: radius nuqtalari, joriy mahalla, chegara GeoJSON. Controller'da SQL bo'lmaydi. |
| `app/Domains/Mahalla/Http/Controllers/Api/NearbyController.php` (yangi) | HTTP: validatsiya + scope + JSON javob. Ikki action: `index` (nearby), `boundary`. |
| `routes/api/mahalla.php` (o'zgartirish) | Ikki yangi marshrut asosiy `auth:sanctum`+`system.access:mahalla` guruhida. |
| `tests/Feature/Mahalla/NearbyApiTest.php` (yangi) | Barcha HTTP feature testlari (route orqali — servis darajasida emas). |

---

### Task 1: `GET /api/mahalla/nearby` — radius bo'yicha nuqtalar (poydevor)

**Files:**
- Create: `app/Domains/Mahalla/Services/NearbyFinder.php`
- Create: `app/Domains/Mahalla/Http/Controllers/Api/NearbyController.php`
- Modify: `routes/api/mahalla.php`
- Test: `tests/Feature/Mahalla/NearbyApiTest.php`

**Interfaces:**
- Consumes: `App\Domains\Mahalla\Support\MahallaAccess::scopeFor(User): MahallaScope` (readonly props: `isAdmin`, `districtId`, `mahallaId`, `streetIds` (array<int,string>), `restrictToStreets`, `canSeeAll`).
- Produces: `NearbyFinder::points(float $lat, float $lng, int $radiusM, array $kinds, int $limit, ?string $districtId): array` — har element assoc array: `id, lat, lng, type, address, kadastr, house_number, street, street_id, mahalla_name, category, category_label, is_social, house_id, overall_status, distance_m`. `NearbyFinder::districtIdForPoint(float $lat, float $lng): ?string`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Mahalla/NearbyApiTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Mahalla;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class NearbyApiTest extends TestCase
{
    use DatabaseTransactions;

    /** Ko'p sxemali: har ulanish alohida qaytarilishi kerak. */
    protected array $connectionsToTransact = ['pgsql', 'auth', 'master', 'mahalla'];

    public function test_nearby_returns_points_within_radius_sorted_by_distance(): void
    {
        [$user, $districtId] = $this->makeDeputatInPilotDistrict();
        [$lat, $lng] = $this->denseCenterIn($districtId);

        $body = $this->actingAs($user, 'sanctum')
            ->getJson("/api/mahalla/nearby?lat={$lat}&lng={$lng}&radius_m=1000&layers=monitoring,homes,orgs&limit=50")
            ->assertOk()
            ->assertJsonStructure([
                'center' => ['lat', 'lng'],
                'radius_m',
                'points' => [['id', 'lat', 'lng', 'distance_m', 'kind', 'type']],
            ])
            ->json();

        $this->assertSame(1000, $body['radius_m']);
        $this->assertNotEmpty($body['points'], 'Zich nuqtada 1km radiusda bino topilishi kerak.');
        $this->assertLessThanOrEqual(50, count($body['points']));

        // Hammasi radius ichida
        foreach ($body['points'] as $p) {
            $this->assertLessThanOrEqual(1000, $p['distance_m']);
        }

        // Masofa bo'yicha o'sib boradi
        $distances = array_column($body['points'], 'distance_m');
        $sorted = $distances;
        sort($sorted);
        $this->assertSame($sorted, $distances, 'Nuqtalar masofa bo\'yicha saralangan bo\'lishi kerak.');
    }

    // ── Yordamchilar ────────────────────────────────────────────────────────

    /** Pilot (Shovot) tumanida deputat yaratadi. @return array{0:User,1:string} */
    private function makeDeputatInPilotDistrict(): array
    {
        $districtId = DB::connection('master')->table('districts')
            ->where('soato_code', (string) config('mahalla.executive.default_district_soato'))
            ->value('id');
        $this->assertNotNull($districtId, 'Pilot tuman (soato_code) bazada topilmadi.');

        $mahallaId = DB::connection('master')->table('mahallas')
            ->where('district_id', $districtId)->value('id');
        $this->assertNotNull($mahallaId, 'Pilot tumanda mahalla topilmadi.');

        $userId = (string) Str::uuid();
        $now = now();

        DB::connection('auth')->table('users')->insert([
            'id' => $userId,
            'name' => 'Синов депутат',
            'login' => 'nb_'.substr($userId, 0, 8),
            'password' => bcrypt('secret'),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::connection('auth')->table('user_system_access')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'system_id' => DB::connection('auth')->table('systems')->where('code', 'mahalla')->value('id'),
            'role' => 'deputat',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::connection('mahalla')->table('users')->insert([
            'id' => $userId,
            'name' => 'Синов депутат',
            'login' => 'nb_'.substr($userId, 0, 8),
            'password' => bcrypt('secret'),
            'district_id' => $districtId,
            'mahalla_id' => $mahallaId,
            'position' => 'deputat',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [User::on('auth')->findOrFail($userId), (string) $districtId];
    }

    /** Tumanning eng zich mahallasi markazi (real ma'lumotdan). @return array{0:float,1:float} */
    private function denseCenterIn(string $districtId): array
    {
        $row = DB::connection('master')->table('buildings')
            ->selectRaw('avg(lat) AS lat, avg(lng) AS lng, count(*) AS c')
            ->where('district_id', $districtId)
            ->whereNotNull('mahalla_id')
            ->groupBy('mahalla_id')
            ->orderByDesc('c')
            ->first();

        $this->assertNotNull($row, 'Pilot tumanda koordinatali bino topilmadi.');

        return [(float) $row->lat, (float) $row->lng];
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd D:\kadr\platform && C:\php84\php.exe artisan test --filter=test_nearby_returns_points_within_radius_sorted_by_distance`
Expected: FAIL — 404 (route `/api/mahalla/nearby` mavjud emas).

- [ ] **Step 3: Create the NearbyFinder service**

Create `app/Domains/Mahalla/Services/NearbyFinder.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Services;

use Illuminate\Support\Facades\DB;

/**
 * «Атроф» — jonli GPS nuqtasi atrofidagi binolar/tashkilotlar (PostGIS).
 *
 * Tezlik: `b.geom && ST_Expand(...)` bbox predikati `buildings_geom_gix`
 * (GIST) indeksini ishlatadi, `ST_DWithin(geom::geography, ...)` esa aniq
 * METR radiusini hisoblaydi. O'lchangan: 3 km, limit 600 → ~24 ms.
 */
class NearbyFinder
{
    /** Bino turlari bo'yicha qatlam kodlari. */
    public const KIND_MONITORING = 'monitoring';
    public const KIND_HOME = 'home';
    public const KIND_ORG = 'org';

    /**
     * Radius ichidagi binolar, masofa bo'yicha saralangan.
     *
     * @param  array<int, string>  $kinds  monitoring|home|org (bo'sh bo'lsa — bo'sh natija)
     * @return array<int, array<string, mixed>>
     */
    public function points(
        float $lat,
        float $lng,
        int $radiusM,
        array $kinds,
        int $limit,
        ?string $districtId,
    ): array {
        if ($districtId === null || $kinds === []) {
            return [];
        }

        $conds = [];
        if (in_array(self::KIND_ORG, $kinds, true)) {
            $conds[] = "b.type = 'non_residential'";
        }
        if (in_array(self::KIND_MONITORING, $kinds, true)) {
            $conds[] = "(b.type = 'residential' AND h.id IS NOT NULL)";
        }
        if (in_array(self::KIND_HOME, $kinds, true)) {
            $conds[] = "(b.type = 'residential' AND h.id IS NULL)";
        }
        if ($conds === []) {
            return [];
        }
        $kindSql = '('.implode(' OR ', $conds).')';

        // bbox kengayishi GRADUSDA. Uzunlik gradusi kenglikka bog'liq — eng
        // katta (uzunlik) qiymatni olamiz, shunda kenglik bo'yicha ham qoplaydi.
        $deg = $radiusM / (111320.0 * max(cos(deg2rad($lat)), 0.01));

        $sql = <<<SQL
        WITH pt AS (
            SELECT ST_SetSRID(ST_MakePoint(:lng1, :lat1), 4326) AS gp,
                   ST_SetSRID(ST_MakePoint(:lng2, :lat2), 4326)::geography AS g
        )
        SELECT b.id,
               b.lat,
               b.lng,
               b.type,
               b.address,
               b.kadastr,
               b.house_number,
               b.street,
               b.street_id,
               b.mahalla_name,
               ot.code     AS category,
               ot.name_cyr AS category_label,
               COALESCE(ot.is_social, false) AS is_social,
               h.id        AS house_id,
               h.status    AS overall_status,
               ROUND(ST_Distance(b.geom::geography, pt.g)::numeric)::int AS distance_m
        FROM pt, master.buildings b
        LEFT JOIN master.object_types ot ON ot.id = b.object_type_id
        LEFT JOIN mahalla.houses h ON h.building_id = b.id AND h.deleted_at IS NULL
        WHERE b.district_id = :district_id
          AND b.geom && ST_Expand(pt.gp, :deg)
          AND ST_DWithin(b.geom::geography, pt.g, :radius)
          AND {$kindSql}
        ORDER BY distance_m
        LIMIT {$limit}
        SQL;

        $rows = DB::connection('mahalla')->select($sql, [
            'lng1' => $lng,
            'lat1' => $lat,
            'lng2' => $lng,
            'lat2' => $lat,
            'district_id' => $districtId,
            'deg' => $deg,
            'radius' => $radiusM,
        ]);

        return array_map(static fn ($r) => (array) $r, $rows);
    }

    /** Nuqta qaysi tumanda (chegara poligoni bo'yicha). */
    public function districtIdForPoint(float $lat, float $lng): ?string
    {
        $row = DB::connection('master')->selectOne(
            'SELECT id FROM master.districts
             WHERE boundary IS NOT NULL
               AND ST_Contains(boundary, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326))
             LIMIT 1',
            ['lng' => $lng, 'lat' => $lat],
        );

        return $row?->id;
    }
}
```

- [ ] **Step 4: Create the controller**

Create `app/Domains/Mahalla/Http/Controllers/Api/NearbyController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Controllers\Api;

use App\Domains\Mahalla\Services\NearbyFinder;
use App\Domains\Mahalla\Support\MahallaAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «Атроф» — deputat turgan nuqta atrofidagi binolar/tashkilotlar.
 *
 * Rolga bog'lanmagan (WorklistController kabi): qamrov MahallaAccess scope
 * orqali TUMAN darajasida cheklanadi. Radiusdagi BARCHA bino ko'rinadi
 * (biriktirilgan/biriktirilmagan) — monitoring HARAKATI esa alohida
 * endpointlarda (worklist/observations) baribir scope bilan himoyalangan.
 */
class NearbyController extends Controller
{
    public function __construct(
        private readonly MahallaAccess $access,
        private readonly NearbyFinder $finder,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'radius_m' => ['nullable', 'integer', 'between:200,5000'],
            'limit' => ['nullable', 'integer', 'between:1,1500'],
            'layers' => ['nullable', 'string', 'max:60'],
        ]);

        $lat = (float) $data['lat'];
        $lng = (float) $data['lng'];
        $radiusM = (int) ($data['radius_m'] ?? 3000);
        $limit = (int) ($data['limit'] ?? 600);
        $kinds = $this->parseLayers($data['layers'] ?? null);

        $scope = $this->access->scopeFor($request->user());
        $districtId = $scope->districtId ?? $this->finder->districtIdForPoint($lat, $lng);

        $rows = $this->finder->points($lat, $lng, $radiusM, $kinds, $limit, $districtId);

        return response()->json([
            'center' => ['lat' => $lat, 'lng' => $lng],
            'radius_m' => $radiusM,
            'points' => array_map(fn (array $r) => $this->presentPoint($r), $rows),
        ]);
    }

    /**
     * `layers` csv → kind ro'yxati. Noma'lum qiymatlar tashlab yuboriladi;
     * berilmasa default: monitoring + org (uy-joylar zichlik sababli OFF).
     *
     * @return array<int, string>
     */
    private function parseLayers(?string $raw): array
    {
        $allowed = [NearbyFinder::KIND_MONITORING, NearbyFinder::KIND_HOME, NearbyFinder::KIND_ORG];
        if ($raw === null || trim($raw) === '') {
            return [NearbyFinder::KIND_MONITORING, NearbyFinder::KIND_ORG];
        }

        $req = array_map(
            static fn (string $s) => rtrim(trim($s), 's'), // 'homes' → 'home', 'orgs' → 'org'
            explode(',', $raw),
        );

        return array_values(array_intersect($allowed, $req));
    }

    /** @param array<string, mixed> $r */
    private function presentPoint(array $r): array
    {
        return [
            'id' => (string) $r['id'],
            'lat' => (float) $r['lat'],
            'lng' => (float) $r['lng'],
            'distance_m' => (int) $r['distance_m'],
            'kind' => $this->kindOf($r),
            'type' => (string) $r['type'],
        ];
    }

    /** @param array<string, mixed> $r */
    private function kindOf(array $r): string
    {
        if ($r['type'] === 'non_residential') {
            return NearbyFinder::KIND_ORG;
        }

        return $r['house_id'] !== null ? NearbyFinder::KIND_MONITORING : NearbyFinder::KIND_HOME;
    }
}
```

- [ ] **Step 5: Register the routes**

In `routes/api/mahalla.php`, add the import next to the other `Api\` controller imports:

```php
use App\Domains\Mahalla\Http\Controllers\Api\NearbyController;
```

And inside the main `Route::middleware(['auth:sanctum', 'system.access:mahalla'])->prefix('mahalla')->name('api.mahalla.')->group(...)` body, right after the WORKLIST routes block, add:

```php
        // АТРОФ (xarita) — jonli GPS radiusidagi binolar/tashkilotlar
        Route::get('/nearby', [NearbyController::class, 'index'])
            ->middleware('throttle:60,1')
            ->name('nearby');
```

- [ ] **Step 6: Run test to verify it passes**

Run: `cd D:\kadr\platform && C:\php84\php.exe artisan test --filter=test_nearby_returns_points_within_radius_sorted_by_distance`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
cd D:/kadr/platform
git add app/Domains/Mahalla/Services/NearbyFinder.php app/Domains/Mahalla/Http/Controllers/Api/NearbyController.php routes/api/mahalla.php tests/Feature/Mahalla/NearbyApiTest.php
git commit -m "feat(mahalla): atrof — radius bo'yicha yaqin binolar API (poydevor)"
```

---

### Task 2: Validatsiya va auth himoyasi

**Files:**
- Test: `tests/Feature/Mahalla/NearbyApiTest.php` (qo'shimcha testlar)

**Interfaces:**
- Consumes: Task 1 dagi `/api/mahalla/nearby` marshruti va `makeDeputatInPilotDistrict()` yordamchisi.
- Produces: yangi kod YO'Q — Task 1 validatsiyasini qulflaydigan testlar.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Mahalla/NearbyApiTest.php` (class ichiga, yordamchilardan oldin):

```php
    public function test_nearby_requires_authentication(): void
    {
        $this->getJson('/api/mahalla/nearby?lat=41.67&lng=60.24')
            ->assertUnauthorized();
    }

    public function test_nearby_rejects_missing_and_out_of_range_params(): void
    {
        [$user] = $this->makeDeputatInPilotDistrict();

        // lat/lng majburiy
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/nearby')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lat', 'lng']);

        // radius MAX 5000
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/nearby?lat=41.67&lng=60.24&radius_m=50000')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['radius_m']);

        // koordinata chegarasi
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/nearby?lat=999&lng=60.24')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lat']);
    }

    public function test_nearby_defaults_radius_to_3000(): void
    {
        [$user, $districtId] = $this->makeDeputatInPilotDistrict();
        [$lat, $lng] = $this->denseCenterIn($districtId);

        $body = $this->actingAs($user, 'sanctum')
            ->getJson("/api/mahalla/nearby?lat={$lat}&lng={$lng}&limit=5")
            ->assertOk()
            ->json();

        $this->assertSame(3000, $body['radius_m']);
    }
```

- [ ] **Step 2: Run tests to verify they pass**

Run: `cd D:\kadr\platform && C:\php84\php.exe artisan test --filter=NearbyApiTest`
Expected: PASS (Task 1 kodida validatsiya allaqachon yozilgan — bu testlar uni qulflaydi). Agar `test_nearby_requires_authentication` 500 bersa, `auth:sanctum` middleware JSON 401 qaytarishini tekshiring.

- [ ] **Step 3: Commit**

```bash
cd D:/kadr/platform
git add tests/Feature/Mahalla/NearbyApiTest.php
git commit -m "test(mahalla): atrof API validatsiya va auth testlari"
```

---

### Task 3: Tasnif (tashkilotlar) va qatlam filtri

**Files:**
- Modify: `app/Domains/Mahalla/Http/Controllers/Api/NearbyController.php` (`presentPoint`)
- Test: `tests/Feature/Mahalla/NearbyApiTest.php`

**Interfaces:**
- Consumes: `NearbyFinder::points()` allaqachon `category`, `category_label`, `is_social`, `address`, `kadastr`, `house_number`, `street`, `mahalla_name` ustunlarini qaytaradi (Task 1).
- Produces: `points[]` elementida qo'shimcha maydonlar: `is_social` (bool), `category` (?string), `category_label` (?string), `address`, `kadastr`, `house_number`, `street`, `mahalla`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Mahalla/NearbyApiTest.php`:

```php
    public function test_orgs_layer_returns_only_non_residential_with_category(): void
    {
        [$user, $districtId] = $this->makeDeputatInPilotDistrict();
        [$lat, $lng] = $this->denseCenterIn($districtId);

        $body = $this->actingAs($user, 'sanctum')
            ->getJson("/api/mahalla/nearby?lat={$lat}&lng={$lng}&radius_m=3000&layers=orgs&limit=100")
            ->assertOk()
            ->assertJsonStructure([
                'points' => [['id', 'kind', 'type', 'is_social', 'category', 'category_label', 'address', 'kadastr', 'mahalla']],
            ])
            ->json();

        $this->assertNotEmpty($body['points'], '3km da tashkilot topilishi kerak (Shovot: 244 ta).');

        foreach ($body['points'] as $p) {
            $this->assertSame('non_residential', $p['type']);
            $this->assertSame('org', $p['kind']);
            $this->assertIsBool($p['is_social']);
        }
    }

    public function test_homes_layer_returns_only_unmonitored_residential(): void
    {
        [$user, $districtId] = $this->makeDeputatInPilotDistrict();
        [$lat, $lng] = $this->denseCenterIn($districtId);

        $body = $this->actingAs($user, 'sanctum')
            ->getJson("/api/mahalla/nearby?lat={$lat}&lng={$lng}&radius_m=1000&layers=homes&limit=100")
            ->assertOk()
            ->json();

        $this->assertNotEmpty($body['points']);
        foreach ($body['points'] as $p) {
            $this->assertSame('residential', $p['type']);
            $this->assertSame('home', $p['kind']);
        }
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd D:\kadr\platform && C:\php84\php.exe artisan test --filter=test_orgs_layer_returns_only_non_residential_with_category`
Expected: FAIL — `assertJsonStructure` `is_social`/`category`/`address` maydonlari yo'qligidan yiqiladi.

- [ ] **Step 3: Extend presentPoint**

In `app/Domains/Mahalla/Http/Controllers/Api/NearbyController.php`, replace the `presentPoint` method with:

```php
    /** @param array<string, mixed> $r */
    private function presentPoint(array $r): array
    {
        return [
            'id' => (string) $r['id'],
            'lat' => (float) $r['lat'],
            'lng' => (float) $r['lng'],
            'distance_m' => (int) $r['distance_m'],
            'kind' => $this->kindOf($r),
            'type' => (string) $r['type'],
            'is_social' => (bool) $r['is_social'],
            'category' => $r['category'] !== null ? (string) $r['category'] : null,
            'category_label' => $r['category_label'] !== null ? (string) $r['category_label'] : null,
            'address' => (string) ($r['address'] ?? ''),
            'kadastr' => $r['kadastr'] !== null ? (string) $r['kadastr'] : null,
            'house_number' => $r['house_number'] !== null ? (string) $r['house_number'] : null,
            'street' => $r['street'] !== null ? (string) $r['street'] : null,
            'mahalla' => $r['mahalla_name'] !== null ? (string) $r['mahalla_name'] : null,
        ];
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd D:\kadr\platform && C:\php84\php.exe artisan test --filter=NearbyApiTest`
Expected: PASS (barcha testlar).

- [ ] **Step 5: Commit**

```bash
cd D:/kadr/platform
git add app/Domains/Mahalla/Http/Controllers/Api/NearbyController.php tests/Feature/Mahalla/NearbyApiTest.php
git commit -m "feat(mahalla): atrof — tashkilot tasnifi (object_types) va qatlam filtri"
```

---

### Task 4: Monitoring holati (`monitored`, `overall_status`, `mine`)

**Files:**
- Modify: `app/Domains/Mahalla/Http/Controllers/Api/NearbyController.php` (`index`, `presentPoint`)
- Test: `tests/Feature/Mahalla/NearbyApiTest.php`

**Interfaces:**
- Consumes: `MahallaScope::$streetIds` (array<int,string>), `NearbyFinder::points()` dagi `house_id` va `overall_status` ustunlari.
- Produces: `points[]` da `monitored` (bool), `overall_status` (?string), `mine` (bool).

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Mahalla/NearbyApiTest.php`:

```php
    public function test_monitoring_layer_exposes_status_and_mine_flag(): void
    {
        [$user, $districtId] = $this->makeDeputatInPilotDistrict();

        // Pilot tumanda monitoring ostidagi (houses yozuvi bor) binoni topamiz.
        $row = DB::connection('mahalla')->selectOne(
            'SELECT b.lat, b.lng
             FROM mahalla.houses h
             JOIN master.buildings b ON b.id = h.building_id
             WHERE h.deleted_at IS NULL AND b.district_id = :d
               AND b.lat IS NOT NULL AND b.lng IS NOT NULL
             LIMIT 1',
            ['d' => $districtId],
        );

        if ($row === null) {
            $this->markTestSkipped('Pilot tumanda monitoring ostidagi bino yo\'q.');
        }

        $body = $this->actingAs($user, 'sanctum')
            ->getJson("/api/mahalla/nearby?lat={$row->lat}&lng={$row->lng}&radius_m=500&layers=monitoring&limit=50")
            ->assertOk()
            ->assertJsonStructure([
                'points' => [['id', 'kind', 'monitored', 'overall_status', 'mine']],
            ])
            ->json();

        $this->assertNotEmpty($body['points']);
        foreach ($body['points'] as $p) {
            $this->assertSame('monitoring', $p['kind']);
            $this->assertTrue($p['monitored']);
            $this->assertContains($p['overall_status'], ['not_started', 'in_progress', 'completed']);
            $this->assertIsBool($p['mine']);
        }
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd D:\kadr\platform && C:\php84\php.exe artisan test --filter=test_monitoring_layer_exposes_status_and_mine_flag`
Expected: FAIL — `monitored`/`overall_status`/`mine` maydonlari yo'q.

- [ ] **Step 3: Pass the street scope into presentation**

In `NearbyController::index`, replace the `$rows`/`return` block with:

```php
        $rows = $this->finder->points($lat, $lng, $radiusM, $kinds, $limit, $districtId);
        $myStreets = array_flip($scope->streetIds);

        return response()->json([
            'center' => ['lat' => $lat, 'lng' => $lng],
            'radius_m' => $radiusM,
            'points' => array_map(fn (array $r) => $this->presentPoint($r, $myStreets), $rows),
        ]);
```

- [ ] **Step 4: Extend presentPoint with monitoring fields**

In the same file, replace `presentPoint` with (note the new second parameter):

```php
    /**
     * @param  array<string, mixed>  $r
     * @param  array<string, int>  $myStreets  deputat ko'chalari (street_id => idx)
     */
    private function presentPoint(array $r, array $myStreets): array
    {
        $streetId = $r['street_id'] !== null ? (string) $r['street_id'] : null;

        return [
            'id' => (string) $r['id'],
            'lat' => (float) $r['lat'],
            'lng' => (float) $r['lng'],
            'distance_m' => (int) $r['distance_m'],
            'kind' => $this->kindOf($r),
            'type' => (string) $r['type'],
            'is_social' => (bool) $r['is_social'],
            'category' => $r['category'] !== null ? (string) $r['category'] : null,
            'category_label' => $r['category_label'] !== null ? (string) $r['category_label'] : null,
            'address' => (string) ($r['address'] ?? ''),
            'kadastr' => $r['kadastr'] !== null ? (string) $r['kadastr'] : null,
            'house_number' => $r['house_number'] !== null ? (string) $r['house_number'] : null,
            'street' => $r['street'] !== null ? (string) $r['street'] : null,
            'mahalla' => $r['mahalla_name'] !== null ? (string) $r['mahalla_name'] : null,
            'monitored' => $r['house_id'] !== null,
            'overall_status' => $r['overall_status'] !== null ? (string) $r['overall_status'] : null,
            'mine' => $streetId !== null && isset($myStreets[$streetId]),
        ];
    }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd D:\kadr\platform && C:\php84\php.exe artisan test --filter=NearbyApiTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
cd D:/kadr/platform
git add app/Domains/Mahalla/Http/Controllers/Api/NearbyController.php tests/Feature/Mahalla/NearbyApiTest.php
git commit -m "feat(mahalla): atrof — monitoring holati va 'mine' bayrog'i"
```

---

### Task 5: Joriy mahalla (`ST_Contains`) va `counts`

**Files:**
- Modify: `app/Domains/Mahalla/Services/NearbyFinder.php` (yangi metod)
- Modify: `app/Domains/Mahalla/Http/Controllers/Api/NearbyController.php` (`index`)
- Test: `tests/Feature/Mahalla/NearbyApiTest.php`

**Interfaces:**
- Produces: `NearbyFinder::mahallaForPoint(float $lat, float $lng, ?string $districtId): ?array` → `['id' => string, 'name' => string]` yoki `null`. Javobga `current_mahalla` va `counts` qo'shiladi.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Mahalla/NearbyApiTest.php`:

```php
    public function test_response_includes_current_mahalla_and_counts(): void
    {
        [$user, $districtId] = $this->makeDeputatInPilotDistrict();
        [$lat, $lng] = $this->denseCenterIn($districtId);

        $body = $this->actingAs($user, 'sanctum')
            ->getJson("/api/mahalla/nearby?lat={$lat}&lng={$lng}&radius_m=1000&layers=monitoring,homes,orgs&limit=25")
            ->assertOk()
            ->assertJsonStructure([
                'current_mahalla',
                'counts' => ['monitoring', 'home', 'org', 'returned', 'truncated'],
            ])
            ->json();

        $this->assertSame(count($body['points']), $body['counts']['returned']);
        $this->assertSame(
            $body['counts']['monitoring'] + $body['counts']['home'] + $body['counts']['org'],
            $body['counts']['returned'],
        );
        $this->assertTrue($body['counts']['truncated'], 'limit=25 zich nuqtada kesilgan bo\'lishi kerak.');

        // Zich mahalla markazi polygon ichida — mahalla topilishi kerak.
        $this->assertNotNull($body['current_mahalla']);
        $this->assertArrayHasKey('id', $body['current_mahalla']);
        $this->assertArrayHasKey('name', $body['current_mahalla']);
        $this->assertNotSame('', $body['current_mahalla']['name']);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd D:\kadr\platform && C:\php84\php.exe artisan test --filter=test_response_includes_current_mahalla_and_counts`
Expected: FAIL — `current_mahalla` va `counts` kalitlari yo'q.

- [ ] **Step 3: Add mahallaForPoint to the service**

In `app/Domains/Mahalla/Services/NearbyFinder.php`, add this method after `districtIdForPoint`:

```php
    /**
     * Nuqta qaysi mahallada (chegara poligoni bo'yicha).
     *
     * @return array{id: string, name: string}|null
     */
    public function mahallaForPoint(float $lat, float $lng, ?string $districtId): ?array
    {
        $sql = 'SELECT id, name_cyr
                FROM master.mahallas
                WHERE boundary IS NOT NULL
                  AND ST_Contains(boundary, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326))';
        $bind = ['lng' => $lng, 'lat' => $lat];

        if ($districtId !== null) {
            $sql .= ' AND district_id = :district_id';
            $bind['district_id'] = $districtId;
        }
        $sql .= ' LIMIT 1';

        $row = DB::connection('master')->selectOne($sql, $bind);

        return $row === null ? null : ['id' => (string) $row->id, 'name' => (string) $row->name_cyr];
    }
```

- [ ] **Step 4: Add current_mahalla + counts to the controller**

In `NearbyController::index`, replace the final `return response()->json([...]);` block with:

```php
        $points = array_map(fn (array $r) => $this->presentPoint($r, $myStreets), $rows);

        $counts = [
            NearbyFinder::KIND_MONITORING => 0,
            NearbyFinder::KIND_HOME => 0,
            NearbyFinder::KIND_ORG => 0,
        ];
        foreach ($points as $p) {
            $counts[$p['kind']]++;
        }
        $counts['returned'] = count($points);
        $counts['truncated'] = count($points) >= $limit;

        return response()->json([
            'center' => ['lat' => $lat, 'lng' => $lng],
            'radius_m' => $radiusM,
            'current_mahalla' => $this->finder->mahallaForPoint($lat, $lng, $districtId),
            'counts' => $counts,
            'points' => $points,
        ]);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd D:\kadr\platform && C:\php84\php.exe artisan test --filter=NearbyApiTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
cd D:/kadr/platform
git add app/Domains/Mahalla/Services/NearbyFinder.php app/Domains/Mahalla/Http/Controllers/Api/NearbyController.php tests/Feature/Mahalla/NearbyApiTest.php
git commit -m "feat(mahalla): atrof — joriy mahalla (ST_Contains) va counts"
```

---

### Task 6: Mahalla chegarasi (keshlangan, soddalashtirilgan GeoJSON)

**Files:**
- Modify: `app/Domains/Mahalla/Services/NearbyFinder.php` (yangi metod)
- Modify: `app/Domains/Mahalla/Http/Controllers/Api/NearbyController.php` (`boundary` action)
- Modify: `routes/api/mahalla.php`
- Test: `tests/Feature/Mahalla/NearbyApiTest.php`

**Interfaces:**
- Consumes: `App\Domains\Mahalla\Support\ExecutiveCache::remember(string $key, Closure $fn): mixed` (static).
- Produces: `NearbyFinder::boundaryGeoJson(string $mahallaId): ?array` va `GET /api/mahalla/mahallas/{mahalla}/boundary`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Mahalla/NearbyApiTest.php`:

```php
    public function test_boundary_endpoint_returns_geojson_feature(): void
    {
        [$user, $districtId] = $this->makeDeputatInPilotDistrict();

        $mahallaId = DB::connection('master')->table('mahallas')
            ->where('district_id', $districtId)
            ->whereRaw('boundary IS NOT NULL')
            ->value('id');

        if ($mahallaId === null) {
            $this->markTestSkipped('Pilot tumanda chegarali mahalla yo\'q.');
        }

        $body = $this->actingAs($user, 'sanctum')
            ->getJson("/api/mahalla/mahallas/{$mahallaId}/boundary")
            ->assertOk()
            ->assertJsonStructure(['type', 'properties' => ['id', 'name'], 'geometry'])
            ->json();

        $this->assertSame('Feature', $body['type']);
        $this->assertSame((string) $mahallaId, $body['properties']['id']);
        $this->assertNotEmpty($body['geometry']);
    }

    public function test_boundary_returns_404_for_unknown_mahalla(): void
    {
        [$user] = $this->makeDeputatInPilotDistrict();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/mahalla/mahallas/00000000-0000-0000-0000-000000000000/boundary')
            ->assertNotFound();
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd D:\kadr\platform && C:\php84\php.exe artisan test --filter=test_boundary_endpoint_returns_geojson_feature`
Expected: FAIL — 404 (marshrut yo'q).

- [ ] **Step 3: Add boundaryGeoJson to the service**

In `app/Domains/Mahalla/Services/NearbyFinder.php`, add this method after `mahallaForPoint`:

```php
    /**
     * Mahalla chegarasi — GeoJSON Feature. Geometriya kam o'zgaradi, shuning
     * uchun keshlanadi va ST_SimplifyPreserveTopology bilan yengillashtiriladi
     * (tolerance ~0.0003° ≈ 33 m — DistrictGeoJsonController bilan bir xil).
     *
     * @return array<string, mixed>|null
     */
    public function boundaryGeoJson(string $mahallaId): ?array
    {
        return ExecutiveCache::remember("nearby:boundary:{$mahallaId}", function () use ($mahallaId) {
            $row = DB::connection('master')->selectOne(
                'SELECT id, name_cyr,
                        ST_AsGeoJSON(ST_SimplifyPreserveTopology(boundary, 0.0003)) AS geojson
                 FROM master.mahallas
                 WHERE id = :id AND boundary IS NOT NULL
                 LIMIT 1',
                ['id' => $mahallaId],
            );

            if ($row === null) {
                return null;
            }

            return [
                'type' => 'Feature',
                'properties' => ['id' => (string) $row->id, 'name' => (string) $row->name_cyr],
                'geometry' => json_decode((string) $row->geojson, true),
            ];
        });
    }
```

Also add the import at the top of the file, next to the existing `use` lines:

```php
use App\Domains\Mahalla\Support\ExecutiveCache;
```

- [ ] **Step 4: Add the boundary action**

In `app/Domains/Mahalla/Http/Controllers/Api/NearbyController.php`, add this action right after `index()`:

```php
    /** Joriy mahalla chegarasi (xaritada «siz shu yerdasiz» konturi). */
    public function boundary(string $mahalla): JsonResponse
    {
        $feature = $this->finder->boundaryGeoJson($mahalla);

        abort_if($feature === null, 404, 'Маҳалла чегараси топилмади.');

        return response()->json($feature);
    }
```

- [ ] **Step 5: Register the route**

In `routes/api/mahalla.php`, directly under the `/nearby` route added in Task 1, add:

```php
        Route::get('/mahallas/{mahalla}/boundary', [NearbyController::class, 'boundary'])
            ->whereUuid('mahalla')
            ->name('mahallas.boundary');
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `cd D:\kadr\platform && C:\php84\php.exe artisan test --filter=NearbyApiTest`
Expected: PASS (barcha testlar).

- [ ] **Step 7: Commit**

```bash
cd D:/kadr/platform
git add app/Domains/Mahalla/Services/NearbyFinder.php app/Domains/Mahalla/Http/Controllers/Api/NearbyController.php routes/api/mahalla.php tests/Feature/Mahalla/NearbyApiTest.php
git commit -m "feat(mahalla): atrof — mahalla chegarasi GeoJSON (keshlangan)"
```

---

### Task 7: Yakuniy tekshiruv va hujjat

**Files:**
- Modify: `docs/superpowers/specs/2026-09-13-mahalla-mobile-nearby-map-design.md` (kontraktni real javobga moslash)

**Interfaces:**
- Consumes: Task 1–6 yakunlangan API.
- Produces: yangilangan dizayn hujjati (mobil rejasi shu kontraktga tayanadi).

- [ ] **Step 1: Run the full Mahalla test suite**

Run: `cd D:\kadr\platform && C:\php84\php.exe artisan test --filter=Mahalla`
Expected: PASS — yangi `NearbyApiTest` va mavjud Mahalla testlari yashil (regressiya yo'q).

- [ ] **Step 2: Measure the real endpoint timing**

Run (Shovot zich nuqtasi, 3 km):

```bash
cd D:/kadr/platform
C:/php84/php.exe artisan tinker --execute="\$t=microtime(true); \$r=app(App\Domains\Mahalla\Services\NearbyFinder::class)->pointsWithOverflow(41.674814, 60.248507, 3000, ['monitoring','home','org'], 600, DB::connection('master')->table('districts')->where('soato_code','1733230')->value('id')); echo count(\$r['points']).' nuqta, has_more='.var_export(\$r['has_more'], true).', '.round((microtime(true)-\$t)*1000).' ms';"
```
Expected: `600 nuqta, <150 ms` (o'lchangan asos: SQL ~24 ms).

- [ ] **Step 3: Update the design doc contract**

In `docs/superpowers/specs/2026-09-13-mahalla-mobile-nearby-map-design.md`, section 3.1 dagi javob namunasini haqiqiy javobga moslang: `counts` kalitlari `monitoring|home|org|returned|truncated` (ko'plik `homes`/`orgs` EMAS), `points[]` maydonlari esa Task 4 dagi `presentPoint` ro'yxati bilan bir xil bo'lsin.

- [ ] **Step 4: Commit**

```bash
cd D:/kadr/platform
git add docs/superpowers/specs/2026-09-13-mahalla-mobile-nearby-map-design.md
git commit -m "docs(mahalla): atrof API real kontraktga moslandi"
```

---

## Definition of Done (A-reja)

- `GET /api/mahalla/nearby?lat=&lng=&radius_m=&layers=&limit=` — radius ichidagi nuqtalar, masofa bo'yicha saralangan, qatlam filtri bilan.
- Har nuqtada: `kind`, `type`, `is_social`, `category`/`category_label`, manzil/kadastr/ko'cha/mahalla, `monitored`, `overall_status`, `mine`, `distance_m`.
- `current_mahalla` (ST_Contains) va `counts` (+`truncated`).
- `GET /api/mahalla/mahallas/{mahalla}/boundary` — keshlangan, soddalashtirilgan GeoJSON Feature.
- Auth 401, validatsiya 422 (radius max 5000), throttle 60/min.
- `php artisan test --filter=Mahalla` yashil; 3 km so'rov < 150 ms.
- Schema/migratsiya O'ZGARMAGAN.

## Keyingi qadam

B-reja (mobil «Атроф» tabi) SHU API yashil bo'lgach va production'ga deploy qilingach yoziladi — shunda mobil kod tasdiqlangan real kontrakt ustiga quriladi (taxminga emas).
