# MurojAAT — Murojaatlar monitoring/tahlil moduli — DIZAYN (spec)

> Sana: 2026-08-06. Manba: `MurojAAT_GOV_FINAL.html` (Urganch tumani hokimligi standalone dashboard).
> Maqsad: shu tizimni platforma ekotizimiga **yangi modul** sifatida ko'chirish — ma'lumot
> **PostgreSQL DB'da** saqlanadi, barcha tahlil **serverda (SQL)** hisoblanadi.

## 1. Maqsad va qamrov

**Maqsad:** Fuqarolar murojaatlarini Excel'dan import qilib, **chuqur tahlil** qilish — dashboard,
KPI/reyting, mahalla kesimi, sayyor qabullar, PVQ/XQ statistika, dinamika, qizil zona, hisobotlar.

**Bu CRUD tizim EMAS:** murojaat yaratish/tahrirlash yo'q. Oqim: **Excel import → normalizatsiya
→ DB → tahlil**. Foydalanuvchi murojaatlarni faqat ko'radi va tahlil qiladi.

**Qamrov:** DB va RBAC **ko'p-tumanга tayyor** (district_id, viloyat/tuman scoping — advisor naqshi),
lekin **faza-1'da faqat Urganch tumani** ma'lumoti to'ldiriladi.

**Non-goals (faza-1):** murojaat CRUD, real-time integratsiya (PVQ/XQ API), mobil ilova, AI tahlil.

## 2. Global cheklovlar (barcha vazifalarga taalluqli)

- Backend: `D:\kadr\platform`, Laravel 11, **PHP 8.4 = `C:\php84\php.exe`** (8.5 prod).
- Frontend: `D:\kadr\murojaat`, Vue 3.5 + TS + Pinia (options) + Tailwind v4 + Vite (**bun**).
- DB: PostgreSQL, **yangi schema `murojaat`** (local `kbt`, prod `xorazm`). Migratsiya pgsql-only, idempotent, forward-only.
- Grafiklar: **kutubxonasiz SVG** (advisor `components/charts/*` naqshi) — Chart.js YO'Q.
- Tema: **advisor yashil brend** (advisor SPA nusxasi).
- Auth: markaziy Sanctum SPA cookie; yangi `auth.systems` code=`murojaat`.
- Excel o'qish: **client** (SheetJS) — 44 ustun kirill→maydon mapping → xom JSON.
  Normalizatsiya + tahlil: **server**.
- Kirill Edit tuzog'i: Read'dan aynan bayt nusxa. Local-first; git push/deploy FAQAT buyruq bilan.

## 3. Rollar va RBAC

Yangi `auth.systems` code=`murojaat`. Rollar (`user_system_access.role`):

| Rol | Ko'rish | Import | Eksport | Boshqaruv (user/parol) | Qamrov |
|---|---|---|---|---|---|
| `murojaat_viloyat` | ✓ hammasi | ✓ | ✓ | ✓ | barcha tuman (`*`) |
| `murojaat_admin` | ✓ o'z tumani | ✓ | ✓ | ✓ (o'z tumani) | 1 tuman |
| `murojaat_xodim` | ✓ o'z tumani | ✓ | ✓ | — | 1 tuman |
| `murojaat_viewer` | ✓ o'z tumani | — | — | — | 1 tuman |

- `MurojaatAccess` support klass (advisor `AdvisorAccess` naqshi): `can(user, perm)`, `scopeFor(user)`.
- Permission kalitlari: `murojaat.view`, `murojaat.import`, `murojaat.export`, `murojaat.manage`.
- District scoping: viloyat = barcha; boshqalar = `district_id = scope.districtId`.
- `EnsureMurojaat` middleware (advisor `EnsureAdvisor` naqshi).
- Profil `murojaat.profiles` (user_id, district_id, level) YOKI reuse — advisor `advisors` naqshi.
  Qaror: alohida `murojaat.profiles` (user_id, district_id, level, active).

## 4. Ma'lumotlar modeli (`murojaat` schema)

### 4.1 `import_sessions`
`id uuid pk, district_id uuid, file_name, records_count int, sayyor_count int,
imported_by uuid, imported_at timestamptz, is_active bool`. `is_active` = joriy tahlil to'plami.
Yangi import: eski (o'sha district) `is_active=false`, yangi `true` (transactionda).

### 4.2 `appeals` — 44 xom maydon + hisoblangan
Xom (Excel'dan, kirill→maydon mapping):
`tr, murojaat_raqami, masala_raqami, qaerdan, kelgan_sana(date/text), muddat_kun,
nazoratchi, yuqori_tashkilot, ijrochi, murojaat_turi, jamoaviy, yashash_hudud, yashash_tuman,
sektor, mahalla, manzil, fuqaro_id, familiya, ism, otasi_ismi, telefon, jinsi, tugilgan_sana,
bandlik, soha, yonalish, masala, natija_toifa, natija_holat, javob_kiritilgan, javob_yuborilgan,
javob_tasdiqlangan, korib_chiqish_kun, kechikish_30dan, kechikib_yopilgan, kechikib_30dan,
takroriylik, kiritgan_tashkilot, sayyor_tashkilot, sayyor_rahbar, rahbar_lavozim,
ijrochi_hudud, ijrochi_tuman, pinfl`.

Server tomonidan hisoblangan (saqlanadi — tahlil tez bo'lsin):
- `session_id uuid`, `district_id uuid`
- `natija_holat_norm` — 5 standart (`hal|kechikkan|jarayon|rad|yonaltirildi`)
- `is_kechikkan bool`, `is_sayyor bool`
- `manba_type` — `pvq|xq|boshqa`
- `kun_otgan int` (kelgan_sanadан bugungача)
- `kelgan_sana_d date` (parse qilingan), `kelgan_yil int`, `kelgan_oy int`
- `stat_holat` — statistika toifasi (`ijobiy|huquqiy|uzoq|tushuntirish|rad|kormasdan|tugatilgan|malumot|boshqa`)

Indekslar: `(session_id)`, `(district_id, natija_holat_norm)`, `(session_id, is_kechikkan)`,
`(session_id, mahalla)`, `(session_id, ijrochi)`, `(kelgan_yil, kelgan_oy)`, `(is_sayyor)`, `(manba_type)`.

### 4.3 `audit_log` (ixtiyoriy, faza-3)
`id, user_id, action, details, created_at`.

## 5. Import oqimi

1. **Client (Vue):** SheetJS `read` → `sheet_to_json` → 44-ustun **kirill sarlavha → maydon**
   mapping (HTMLdagi `COL_MAP`), Excel serial sana → matn. Xom qatorlar (mapping qilingan,
   normalizatsiyasiz) JSON. `murojaat_raqami` bo'sh qatorlar tashlanadi. Katta fayl → 500 tali batch.
2. **`POST /api/murojaat/import`** (`{district_id?, file_name, rows[]}`):
   - `murojaat.import` ruxsati; district scope.
   - `MurojaatNormalizer` har qator uchun normalizatsiya (§6).
   - Transaction: yangi `import_session` (is_active=true), eski deactivate; `appeals` batch insert.
   - Javob: `{ok, session_id, count, sayyor_count}`.
3. Faqat `is_active` sessiya tahlil qilinadi; eskilar arxiv (ko'rish/qayta faollashtirish mumkin).

## 6. Normalizatsiya qoidalari (HTMLdagi `normalizeRow` — PHP `MurojaatNormalizer`)

Aynan ko'chiriladi:
- **Raqamlar:** `korib_chiqish_kun`, `kechikish_30dan` = int; `muddat_kun` default 30.
- **`kun_otgan`:** `kelgan_sana` parse (DD.MM.YYYY | DD/MM/YYYY | YYYY-MM-DD) → bugungача kunlar.
- **`javobBerilgan`** = `natija_holat_norm ∈ {hal, rad, yonaltirildi}`.
- **`is_kechikkan`** = `(!javobBerilgan && kun_otgan>15) || kechikish_30dan>0 || natija_holat=Kechikkan`.
- **Holat normalizatsiyasi** (`natija_holat_norm`): katta kirill→standart xarita (`holatMap`) +
  `natija_toifa` xaritasi (`toifaMap`) + qism-moslik (substring: ижобий/тасдиқ/hal→hal;
  муддат/kechik/ошиб→kechikkan; ижрода/jarayon/кўриб→jarayon; рад→rad; йўнал→yonaltirildi) +
  `javob_tasdiqlangan` bo'lsa→hal + `korib_chiqish_kun>muddat_kun`→kechikkan.
- **`takroriylik`:** "takror"/"такрор" → `Takroriy`.
- **`jamoaviy`:** ha/ха/yes/1 → `Ha`, aks holda `Yo'q`.
- **`is_sayyor`:** `sayyor_tashkilot` bo'sh emas.
- **`manba_type`:** `qaerdan` — виртуал/virtual/pvq/prezident → `pvq`; халқ/xalq/xq/қабулхона/qabulxona → `xq`; aks holda `boshqa`.
- **`stat_holat`** (statistika toifasi — `getStatHolat`): natija_holat+toifa qo'shib substring:
  ижобий/ijobiy/жавоб тасдиқ/муддатда ҳал → `ijobiy`; ҳуқуқий/huquqiy/маълумот бер → `huquqiy`;
  узоқ/uzoq/назоратга → `uzoq`; тушунтириш → `tushuntirish`; рад → `rad`;
  кўрмасдан/qoldirilgan → `kormasdan`; тугатилган → `tugatilgan`; маълумот учун → `malumot`; else `boshqa`.

## 7. Tahlil (serverda, SQL — endpointlar)

Barchasi `is_active` sessiya + scope (district) ustida. **Sayyor qabullar asosiy statistikadan chiqariladi**
(oddiy = `is_sayyor=false`).

- **`GET /api/murojaat/dashboard`** → 6 KPI (jami, kechikkan, jarayon, hal, takroriy, jamoaviy);
  holat/manba/yo'nalish taqsimoti; **qizil zona** RZ-01 (kechikkan), RZ-03 (takroriy),
  RZ-04 (mahalla klaster: bitta mahalla+masala ≥5), RZ-05 (jamoaviy hal etilmagan),
  RZ-06 (sayyor hal etilmagan), RZ-08 (rad etilgan); top mahalla; tashkilot reytingi (qisqa).
- **`GET /api/murojaat/appeals`** → filtr (holat/manba/mahalla/masala_raqami/muddat/tashkilot/qidiruv) + sahifalash.
- **`GET /api/murojaat/kpi`** → tashkilot reytingi **PF-51 3(g)** (§8), A/B/C/D, radar uchun umumiy o'rtacha.
- **`GET /api/murojaat/mahalla`** → mahalla kesimi (jami/hal/kechik/takror + top masala + yo'nalishlar).
- **`GET /api/murojaat/sayyor`** → sayyor qabullar (KPI + rahbar/holat taqsimoti + ro'yxat).
- **`GET /api/murojaat/statistika?manba=&yil=&oy=`** → PVQ/XQ tuman kesimi (§9), donut+bar.
- **`GET /api/murojaat/dynamics?period=kun|hafta|oy|chorak|yil`** → davr bo'yicha kelgan/hal/kechik + peak/trend/avg.
- **`GET /api/murojaat/import/sessions`** → import tarixi/arxiv; `POST /import/sessions/{id}/activate`.
- **`GET /api/murojaat/export?type=`** → xlsx (App\Support\SimpleXlsx) / csv.

## 8. KPI reyting formulasi (PF-51, 3(g)-band) — aynan saqlanadi

Har tashkilot (`ijrochi`) uchun, n=jami:
- `halB = hal/n*100`
- `muddatB = (1 − kechik/n)*100`  (kechik = is_kechikkan)
- `qaytaB = (1 − qaytaIjro/n)*100` (qaytaIjro = takroriylik "qayta" so'zi)
- `takrorB = (1 − takroriy/n)*100` (takroriy = takroriylik "takror")
- **`umumiy = halB*0.35 + muddatB*0.30 + qaytaB*0.20 + takrorB*0.15`**
- Toifa: **A ≥ 85, B ≥ 70, C ≥ 50, D < 50**.
- Faza-1: `ijrochi` "urganch tuman" bo'lganlar (HTMLdagi filtr); ko'p-tuman fazasida district bo'yicha.

## 9. PVQ/XQ statistika (aynan saqlanadi)

Tuman bo'yicha (`yashash_tuman`/`ijrochi_tuman`) guruh; ustunlar: masala soni; ko'rib chiqilgan
(jami/ijobiy/%/huquqiy/uzoq/tushuntirish/rad/kormasdan/tugatilgan/malumot); takroriy;
muddati buzilgan (jami/kechik/30dan/yopilgan). `stat_holat` bo'yicha. Manba: faqat PVQ yoki XQ (yoki ikkalasi).
Filtr: manba/yil/oy.

## 10. Frontend (Vue SPA — 10 bo'lim)

`layouts/AppLayout.vue` (advisor nusxasi, yashil), `router`, `stores/*` (Pinia), `lib/api.ts` (Sanctum).
Bo'limlar/route: `/dashboard`, `/vmc`, `/appeals`, `/kpi`, `/mahalla`, `/sayyor`, `/statistika`,
`/import`, `/reports`, `/settings`. Grafiklar: SVG (donut/bar/line + kichik radar yoki bar-fallback).
Import sahifasi: SheetJS (self-host), drag-drop, progress, arxiv tarix. Parol o'zgartirish: markaziy
`POST /api/change-password` (advisorda bor). Foydalanuvchilar boshqaruvi: viloyat/admin (`murojaat.manage`).

## 11. Fazalar (writing-plans uchun)

- **Faza 0 — poydevor:** schema+migratsiya, modellar, `auth.systems` murojaat + rollar + seeder,
  `MurojaatAccess`+`EnsureMurojaat`, `/me`+`/districts`, Vue skelet (advisor nusxasi, yashil), auth.
- **Faza 1 — import + tahlil yadrosi:** SheetJS client parse+mapping, `MurojaatNormalizer`,
  `POST /import`, `appeals` ro'yxat, `dashboard` (KPI+taqsimot+qizil zona+top mahalla). Testlar.
- **Faza 2 — kesimlar:** `kpi` (PF-51), `mahalla`, `statistika` (PVQ/XQ). Testlar.
- **Faza 3 — qolgan:** `vmc`, `sayyor`, `dynamics`, `reports`+`export`, arxiv/sessiya. Testlar.
- **Faza 4 — RBAC + deploy:** foydalanuvchilar boshqaruvi, parol, district scoping polish;
  prod deploy (subdomen, nginx, TLS) — buyruq bilan.

## 12. Konventsiyalar / tuzoqlar

- Test izolyatsiyasi: advisor naqshi (DatabaseTransactions, 2099 yil/izolyatsiya kerak bo'lsa).
- Import katta bo'lishi mumkin (10k+ qator) — batch + indeks + SQL agregatsiya (frontendда emas).
- Sana formatlari xilma-xil — normalizatsiyада bir nechta format qo'llab-quvvatlanadi.
- Kirill string xaritalari katta — PHP'ga aynan ko'chirilsin (test bilan qoplansin).
- CDN yo'q: SheetJS self-host (`public` yoki npm paket).

## 13. Ochiq/keyinги (faza-1 tashqarisida)

- Yashash tuman ustuni orqali haqiqiy ko'p-tuman statistika (faza-2+).
- Aholi soni (statistika ustuni) manbasi — hozircha bo'sh/qo'lda.
- PVQ/XQ real-time API integratsiyasi.
