# Audit va optimizatsiya — kamchiliklar ro'yxati (fazalarga bo'lingan)

> 2026-07-21. Uch yo'nalish bo'yicha to'liq audit (performance + backend DRY/SOLID +
> frontend DRY). Har band: **holat** (✅ bajarildi / 🔵 reja), **xavf**, **fayl(lar)**.
> Tamoyil: xavfsiz + yuqori qiymatли tuzatishlar darhol; katta/xavfli refaktorlar
> foydalanuvchi ishtirokida (deploy topshiriq bilan).

---

## ✅ FAZA 0 — Darhol bajarildi (xavfsiz)

### 0.1. Sessiya/token muddati (2-vazifa) ✅
- `SESSION_LIFETIME` 120 → **43200 min (30 kun)** (.env, gitignored).
- Login `remember=true` (mahalla + HR) — sessiya tugasa ҳам cookie қайta авторизация.
- **Xavf:** yo'q. **Prod:** deploy vaqtida prod `.env` da ham SESSION_LIFETIME=43200.

### 0.2. Backend Finding 2 — controller base (DRY) ✅
- `MahallaPanelController` (abstract) yaratildi: `mahallaId()`, `requireMahallaId()`,
  `noMahalla()`. ProjectController/CadastreController/ContractController undan meros
  oladi — ~30 qator takror olib tashlandi.
- **Xavf:** yo'q (xatti-harakat o'zgarmadi, 17 route yuklandi).

---

## ✅ FAZA 1 — Performance (localda xavfsiz, hozir bajariladi)

### 1.1. Leaflet (148 KB) landing sahifadan olib tashlash — P3 ✅
- `ChangeMap`/`FeatureMap` `defineAsyncComponent` bilan — xarita jadval/KPI'dan
  keyin yuklanadi. Fayllar: ExecutiveDistrict/RaisDashboard/HokimDashboard/
  ExecutiveSocialObjects/ExecutiveOgir. **Xavf:** past.

### 1.2. ExecutiveMahalla'ga skeleton — P7 ✅
- Yuklanish paytida bo'sh ekran o'rniga `DashboardSkeleton`. **Xavf:** yo'q.

### 1.3. DB indekslari — P5 ✅
- Migratsiya: `zone_observations(observed_at) WHERE is_change` (partial),
  `buildings(mahalla_id, type)`, `buildings(district_id, type)`. Forward migrate
  (ruxsat). **Xavf:** past (CREATE INDEX).

### 1.4. ExecutiveCache::version() memoizatsiya — P6 ✅
- Har `remember()` da 2× cache so'rovi → request davomida bir marta. **Xavf:** yo'q.

---

## 🔵 FAZA 2 — Performance (PROD .env / infra — deploy vaqtida)

### 2.1. Redis'ga o'tish — P1 (ENG KATTA YUTUQ) 🔵
- `SESSION_DRIVER=redis`, `CACHE_STORE=redis`. Har autentifikatsiyalangan so'rov
  hozir `sessions` jadvaliga read+write qiladi (DB tax). Redis prod serverda
  o'rnatilgan (Ubuntu), lekin ishlatilmayapti.
- **Localda:** Windows'da Redis yo'q → localda qo'llanmadi. Memurai (Windows Redis)
  o'rnatilса localda ham tezlashadi.
- **Xavf (prod):** past — Redis ishlab turibdi; faqat .env + config:cache.

### 2.2. nginx gzip/brotli + `Cache-Control: immutable` (/assets/*) — P7 🔵
- Leaflet 148KB + vendor 115KB + CSS 49KB siqилmasдан ketmasin. Prod nginx tekshiruv.

---

## 🔵 FAZA 3 — Performance (backend+frontend koordinatsiya, o'rtacha xavf)

### 3.1. Boot waterfall — P2 🔵
- `router.beforeEach` → `boot()` → `/api/me` **keyin** `/api/mahalla/context`
  **keyin** `fetchDistrict` **keyin** `fetchGeoJson` = 4 ketma-ket so'rov.
- Tuzatish: (a) `/api/me` javobiga `context` ni qo'shib bitta so'rov; (b) district
  id ni clientga berib `fetchDistrict`+`fetchGeoJson` ni `Promise.all`.
- **Xavf:** o'rtacha (backend + frontend birga). Foydalanuvchi ishtirokida.

### 3.2. Og'ir agregatlarni qisqa-TTL kesh — P4 🔵
- `ExecutiveStats::district()` (~11 so'rov), `MahallaDashboardController` (~14),
  `ObodStats::forMahalla()` (~6) — har so'rovda qayta hisoblanadi.
- **Xavf:** kesh eskirishi (test paytida "kiritdim, ko'rinmayapti"). Version-key
  invalidatsiya bilan xavfsiz qilish mumkin. Foydalanuvchi bilan kelishilib.

---

## 🔵 FAZA 4 — Backend DRY/SOLID (o'rtacha xavf, ehtiyot bilan)

### 4.1. Finding 1 — ko'cha/mahalla agregatlar servisi 🔵
- "by-street/by-mahalla" GROUP BY helperlari 2–6× takrorlangan (RaisCadastre,
  ObodStats, ExecutiveStats, ExecutiveMahallaStats, ContractService). Umumiy
  `StreetAggregates`/`MahallaAggregates` servisiga chiqarish.
- **Xavf:** o'rtacha (query semantikasi aynan saqlanishi shart). Testlar bilan.

### 4.2. Finding 3 — HR Hokim-yordamchisi / Yoshlar-yetakchisi stack 🔵
- Action/Request/Policy/Controller to'liq takror (2 nusxa). `LeaderCrudController`,
  `LeaderRequest`, `LeaderPolicy` base + generic action.
- **Xavf:** o'rtacha. Qatlam-qatlam (action→request→policy→controller).

### 4.3. Finding 4/5 — zona-status manbasi, fat controller, ExecutiveStats (God, 680q) 🔵
- "done" status to'plami bir joyda (`MahallaZones::doneStatusCodes()`).
- Controller ichidagi xom `DB::connection()` querylari servisga.
- `ExecutiveStats` ni PeriodResolver/IndicatorRepository/… ga bo'lish.
- **Xavf:** past–o'rtacha.

---

## 🔵 FAZA 5 — Frontend DRY (struktura, katta — foydalanuvchi ishtirokida)

### 5.1. Shared paket (`@kadr/shared`) — workspace 🔵
- `api.ts` (~95% bir xil), `Pagination.vue` (100%), `useToast.ts` (100%),
  `format.ts`, `AuthImage`, status-meta — mahalla va HR'da nusxa. npm workspace +
  umumiy paket (har ilova mustaqil deploy bo'laveradi).
- **Xavf:** o'rtacha-yuqori (struktura o'zgarishi, ikkala build sinovi). Alohida seans.

### 5.2. `usePaginatedResource` kompozabl 🔵
- 9 ro'yxat sahifasi (HR 6 + mahalla 3) bir xil `rows/meta/loading/search/page` +
  debounce + `watch` naqshini takrorlaydi. Bitta kompozablga.
- **Xavf:** o'rtacha (9 sahifa refaktori — bittadan sinab).

### 5.3. `useResourceForm` + `FormModalShell` 🔵
- HR 3 modal (HY/YY/Org) ~90% bir xil submit/hydrate/extractErrors.
- **Xavf:** o'rtacha.

### 5.4. Inline util: `fmt()` 6×, `shortDateTime`, debounce, status-meta 🔵
- Har ilovada `format.ts` (fmtNumber/fmtDate/toDateInput). **Xavf:** past.

---

## Bajarilish tartibi (tavsiya)
FAZA 0 ✅ → FAZA 1 ✅ (localda hozir) → FAZA 2 (prod deploy) → FAZA 3/4/5
(foydalanuvchi ishtirokida, bittadan, sinab). Deploy — faqat topshiriq bilan.
