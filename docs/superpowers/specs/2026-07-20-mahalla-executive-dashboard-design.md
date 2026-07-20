# Mahalla monitoring — rahbariyat dashboard'i (viloyat kesimi)

**Sana:** 2026-07-20
**Holat:** tasdiqlangan (foydalanuvchi dizaynni ma'qulladi)
**Qamrov:** `platform` (backend) + `mahalla` (Vue SPA frontend)

## 1. Muammo

Viloyat hokimi o'rinbosari mahalla monitoringining natijasini bir ekranda ko'rishi kerak: qaysi mahallada nechta xonadon bor va ularning qaysi qismlarida (fasad, oshxona, hojatxona, tomorqa) haqiqiy o'zgarish bo'lgan.

Hozirgi `AdminReports` sahifasi bunga yaramaydi — u butun tizim bo'yicha yig'ma ko'rsatkichlarni beradi, mahalla kesimi yo'q, va admin huquqini talab qiladi.

## 2. Maqsad va chegara

**Maqsad:** ikki darajali jadval — tuman (mahallalar kesimida) va mahalla (zonalar kesimida), faqat ko'rish uchun.

**Bu ishga KIRMAYDI (YAGNI):**
- Excel/PDF eksport
- Grafiklar va diagrammalar (jadval yetarli)
- Sana oralig'ini qo'lda tanlash (davrlar qat'iy: hafta va kun)
- Xonadon darajasidagi ro'yxat va rasmlar galereyasi
- Boshqa tumanlar (kod tayyor bo'ladi, lekin hozir faqat Shovot ochiladi)

## 3. Ma'lumot manbai va hisoblash

### 3.1 Maxraj — jami xonadon

Manba: `master.buildings`, `type = 'residential'`, mahalla bo'yicha `COUNT(*)`.

Operatsion `mahalla.houses` jadvali **ishlatilmaydi**: u kerak bo'lganda to'ldiriladi (`HouseProvisioner`) — deputat birinchi marta rasm yuklaganda. Undan olingan son har doim haqiqatdan kam bo'ladi.

Shovot bo'yicha tekshirilgan raqamlar (2026-07-20):

| Ko'rsatkich | Qiymat |
|---|---|
| Mahallalar | 52 |
| Turar-joy binolari (jami) | 34 651 |
| Mahallaga bog'langan | 34 648 |
| Mahallaga **bog'lanmagan** | 3 |

**Qaror:** ЖАМИ qatori ko'rsatilgan mahalla qatorlarining yig'indisi bo'ladi (34 648). Bog'lanmagan xonadonlar bo'lsa, jadval ostida izoh chiqadi: «N та хонадон маҳаллага бириктирилмаган». Bosh sondan jimgina farq qilishdan ko'ra, farqni ochiq aytish kerak.

### 3.2 Surat — o'zgarganlar

Manba: `mahalla.zone_observations` + `mahalla.houses` (bir ulanishda).

Shartlar:
- `is_change = true` — AI avtomatik tasdiqlagan **va** admin qo'lda tasdiqlagan o'zgarishlarni ikkalasini qamraydi (`ReviewController::confirm` ham shu bayroqni qo'yadi). Tekshiruv kutayotgan (`flagged`) kuzatuvlar sanalmaydi.
- Davr bo'yicha `observed_at` filtri.

**Sanoq birligi: `COUNT(DISTINCT house_id)`** — ya'ni «nechta xonadonda» o'zgarish bo'lgani, kuzatuvlar soni emas. Bitta xonadon bir haftada ikki marta o'zgarsa, u 1 marta sanaladi. Aks holda son maxrajdan oshib ketishi mumkin.

### 3.3 Davrlar va vaqt mintaqasi

Ilova UTC da ishlaydi, O'zbekiston esa UTC+5. Davr chegaralari **`Asia/Tashkent`** bo'yicha hisoblanadi, so'ngra UTC ga o'giriladi:

```php
$tz = config('mahalla.timezone', 'Asia/Tashkent');
$now = Carbon::now($tz);
$todayStart = $now->copy()->startOfDay()->utc();          // mahalliy yarim tundan
$weekStart  = $now->copy()->startOfWeek(Carbon::MONDAY)->utc();
```

Aks holda mahalliy vaqt bilan 00:00–05:00 oralig'idagi o'zgarishlar kechagi kunga tushib qoladi.

### 3.4 So'rovlar

**Schema'lararo `JOIN` ishlatilmaydi.** `master` va `mahalla` bitta `kbt` bazasida bo'lsa ham, `master.buildings JOIN mahalla.houses` ko'rinishidagi so'rov PostgreSQL'ga qattiq bog'lanadi — testlar esa SQLite `:memory:` da yuradi va u yerda schema tushunchasi yo'q.

Buning o'rniga ikkita mustaqil agregat, PHP'da `mahalla_id` bo'yicha birlashtiriladi:

```php
// Maxraj — master ulanishi
DB::connection('master')->table('buildings')
    ->where('district_id', $districtId)->where('type', 'residential')
    ->groupBy('mahalla_id')
    ->selectRaw('mahalla_id, count(*) as households');

// Surat — mahalla ulanishi
DB::connection('mahalla')->table('zone_observations as o')
    ->join('houses as h', 'h.id', '=', 'o.house_id')
    ->where('h.district_id', $districtId)
    ->where('o.is_change', true)
    ->where('o.observed_at', '>=', $weekStart)
    ->groupBy('h.mahalla_id', 'o.zone')
    ->selectRaw(
        'h.mahalla_id, o.zone,
         count(distinct o.house_id) as week_count,
         count(distinct case when o.observed_at >= ? then o.house_id end) as today_count',
        [$todayStart]
    );
```

`FILTER (WHERE ...)` emas, `CASE` ishlatiladi — `CASE` standart SQL va ikkala bazada ham ishlaydi.

Mahallalar ro'yxati `master.mahallas` dan (`district_id` bo'yicha, `sort_order`, keyin `name_cyr` tartibida). Kuzatuvi yo'q mahalla ham jadvalda **nol bilan** ko'rinadi — yo'qolib qolmaydi.

Hajm: 52 qator. Kesh kerak emas.

## 4. Rol va ruxsat

### 4.1 Yangi `viloyat` roli

`MahallaAccess::PERMISSIONS` ga qo'shiladi:

```php
'viloyat' => ['dashboard.view', 'reports.view', 'houses.view', 'analyses.view'],
```

`photos.upload` va `*` yo'q — bu rol hech narsani o'zgartira olmaydi.

### 4.2 `MahallaScope` ga `canSeeAll`

Hozir `House::scopeVisibleTo()` `isAdmin` ni tekshiradi. Viloyat roli ham hammasini ko'rishi kerak, lekin admin **emas**.

Shuning uchun `MahallaScope` ga yangi `canSeeAll` xossasi qo'shiladi (`admin` va `viloyat` uchun `true`), `scopeVisibleTo()` esa shunga o'tadi. `isAdmin` faqat **boshqaruv** huquqi bo'lib qoladi.

Bu ajratish muhim: ko'rish doirasi va boshqaruv huquqi ikki xil narsa, ularni bitta bayroq bilan ifodalash kelajakda xatoga olib keladi.

### 4.3 Middleware

Yangi `EnsureMahallaViewer` (alias `mahalla.viewer`) — `admin` va `viloyat` rollariga ruxsat, qolganiga 403.

Mavjud `mahalla.admin` **tegilmaydi**. Natijada viloyat roli user yarata olmaydi, kuzatuv tasdiqlay olmaydi, o'chira olmaydi.

## 5. API

Ikkala endpoint `auth:sanctum` + `system.access:mahalla` + `mahalla.viewer` ostida.

| Metod | URL | Nom |
|---|---|---|
| GET | `/api/mahalla/executive/districts/{district?}` | `api.mahalla.executive.district` |
| GET | `/api/mahalla/executive/mahallas/{mahalla}` | `api.mahalla.executive.mahalla` |

`{district}` **ixtiyoriy**: berilmasa sozlamadagi standart tuman (Shovot) olinadi. Shu tufayli frontend `/executive` ni parametrsiz ocha oladi va tuman kodi faqat bitta joyda — konfiguratsiyada turadi.

### 5.1 Tuman javobi

```json
{
  "district": { "id": "uuid", "name": "Шовот тумани", "soato": "1733230" },
  "period": { "today": "2026-07-20", "week_start": "2026-07-20", "timezone": "Asia/Tashkent" },
  "zones": [ { "code": "facade", "label": "Уй фасади" }, "…" ],
  "rows": [
    {
      "mahalla": { "id": "uuid", "name": "РОЯТ МФЙ" },
      "households": 1156,
      "zones": {
        "facade":  { "week": 2, "today": 2 },
        "kitchen": { "week": 0, "today": 0 },
        "toilet":  { "week": 1, "today": 0 },
        "yard":    { "week": 30, "today": 20 }
      }
    }
  ],
  "totals": {
    "households": 34648,
    "zones": { "facade": { "week": 0, "today": 0 }, "…": {} }
  },
  "unassigned_households": 3
}
```

### 5.2 Mahalla javobi

```json
{
  "mahalla": { "id": "uuid", "name": "РОЯТ МФЙ",
               "district": { "id": "uuid", "name": "Шовот тумани" } },
  "period": { "today": "2026-07-20", "week_start": "2026-07-20", "timezone": "Asia/Tashkent" },
  "households": 1156,
  "rows": [
    { "zone": "facade", "label": "Уй фасади", "households": 1156, "week": 2, "today": 2 },
    { "zone": "kitchen", "label": "Ошхона",   "households": 1156, "week": 0, "today": 0 },
    { "zone": "toilet",  "label": "Ҳожатхона", "households": 1156, "week": 1, "today": 0 },
    { "zone": "yard",    "label": "Томорқа",   "households": 1156, "week": 30, "today": 20 }
  ]
}
```

Har zonaning `households` qiymati bir xil — har xonadonda to'rt zona ham bor.

### 5.3 Sozlama

`config/mahalla.php`:

```php
'timezone' => env('MAHALLA_TIMEZONE', 'Asia/Tashkent'),
'executive' => [
    'default_district_soato' => env('MAHALLA_EXECUTIVE_DISTRICT', '1733230'), // Shovot
],
```

Tuman kodda qattiq yozilmaydi. Rol barcha tumanlarni ko'ra oladi; hozircha faqat Shovot ochiladi.

## 6. Frontend

Mavjud dizayn tili saqlanadi: Tailwind v4, `slate` palitra, `rounded-xl border border-slate-200 bg-white`, komponent kutubxonasi yo'q.

| Yo'l | Komponent | meta |
|---|---|---|
| `/executive` | `pages/executive/ExecutiveDistrict.vue` | `viewerOnly` |
| `/executive/mahallas/:id` | `pages/executive/ExecutiveMahalla.vue` | `viewerOnly` |

- Yangi Pinia store `stores/executive.ts` — `district`, `mahalla`, `fetchDistrict(id?)`, `fetchMahalla(id)`.
- `router/index.ts` dagi `homeFor()` viloyat rolini `/executive` ga yo'naltiradi.
- `/executive` parametrsiz ochilsa, backend sozlamadagi standart tumanni qaytaradi.
- Tuman jadvalida qator bosiladigan (`cursor-pointer hover:bg-slate-50`), mahalla sahifasiga o'tadi.
- Nol qiymatlar bo'sh emas, `0` bo'lib ko'rinadi — ma'lumot yo'qligi bilan nol farqlanishi kerak.
- Raqamlar `tabular-nums` bilan tekislanadi.

## 7. Testlar

`platform/tests/Feature/Mahalla/ExecutiveDashboardTest.php`:

**Ruxsat:**
1. `viloyat` roli executive endpointlarni ocha oladi (200)
2. `viloyat` roli `admin/users` ga POST qilsa 403
3. `deputat` roli executive'ga kirsa 403
4. Autentifikatsiyasiz 401

**Hisoblash:**
5. Bitta xonadon bir haftada 2 marta o'zgarsa — `week = 1` (DISTINCT)
6. `is_change = false` kuzatuv sanalmaydi
7. Kuzatuvi yo'q mahalla jadvalda nol bilan ko'rinadi
8. `households` maxraji `master.buildings` dan olinadi (`houses` bo'sh bo'lsa ham to'g'ri)

**Vaqt mintaqasi:**
9. Toshkent vaqti bilan bugun 00:30 da bo'lgan kuzatuv (UTC bo'yicha kechagi 19:30) `today` ga tushadi
10. O'tgan haftaning yakshanbasidagi kuzatuv `week` ga tushmaydi

### 7.1 Test muhiti (2026-07-20 da tuzatildi)

Dastlab «testlar SQLite `:memory:` da yuradi» deb rejalashtirilgan edi. **Bu noto'g'ri:**

- `config/database.php` da `auth`, `master`, `mahalla` ulanishlari qattiq `pgsql` drayveriga bog'langan. `phpunit.xml` dagi `DB_CONNECTION=sqlite` faqat *default* ulanishni almashtiradi — qolgan uchtasi baribir PostgreSQL'ga boradi, ya'ni test **dev bazasini** o'qigan bo'lardi.
- Migratsiyalarda PostGIS turlari bor (`geometry(MultiPolygon,4326)`), SQLite ularni umuman qo'llab-quvvatlamaydi.

**Qaror:** alohida `kbt_test` PostgreSQL bazasi. `phpunit.xml` da `DB_CONNECTION=pgsql`, `DB_DATABASE=kbt_test`. Barcha to'rt ulanish `DB_DATABASE` ni bo'lishadi, shuning uchun bitta o'zgaruvchi yetarli.

Dev bazasi `kbt` ga **umuman tegilmaydi** — bu avvaldan kelishilgan qat'iy chegara.

Schema'lararo `JOIN` dan voz kechish qarori (3.4) kuchida qoladi, lekin sababi boshqa: kod tushunarli va ulanish chegaralari aniq bo'lishi uchun, ko'chimlilik uchun emas.

## 8. Fayllar

**Yangi:**
- `app/Domains/Mahalla/Http/Controllers/Api/Executive/DistrictDashboardController.php`
- `app/Domains/Mahalla/Http/Controllers/Api/Executive/MahallaDashboardController.php`
- `app/Domains/Mahalla/Services/ExecutiveStats.php` — hisoblash mantig'i (kontrollerlar faqat shakllantiradi)
- `app/Domains/Mahalla/Http/Middleware/EnsureMahallaViewer.php`
- `tests/Feature/Mahalla/ExecutiveDashboardTest.php`
- `mahalla/src/pages/executive/ExecutiveDistrict.vue`, `ExecutiveMahalla.vue`
- `mahalla/src/stores/executive.ts`

**O'zgaradi:**
- `app/Domains/Mahalla/Support/MahallaAccess.php` — `viloyat` roli
- `app/Domains/Mahalla/Support/MahallaScope.php` — `canSeeAll`
- `app/Domains/Mahalla/Models/House.php` — `scopeVisibleTo` `canSeeAll` ga o'tadi
- `bootstrap/app.php` — `mahalla.viewer` aliasi
- `routes/api/mahalla.php` — executive guruhi
- `config/mahalla.php` — `timezone`, `executive`
- `mahalla/src/router/index.ts`, `src/types/index.ts`

`ExecutiveStats` alohida servis sifatida ajratiladi: kontrollerlar HTTP shakllantirish bilan, hisoblash esa o'z joyida qoladi va mustaqil testlanadi.

## 9. Ochiq masalalar

Yo'q. Barcha savollar dizayn bosqichida hal qilindi:
- Davrlar: **shu hafta** (dushanbadan) va **bugun**
- Rol: yangi `viloyat`, faqat ko'rish
- Ma'lumot: faqat real (demo/seed yaratilmaydi)
- Chuqurlashish: tuman jadvali → mahalla zonalar jadvali (ikki daraja)
